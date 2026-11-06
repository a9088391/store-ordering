<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class OrderApiTest extends TestCase
{
    // 多品項開單保存固定金額及商品快照，並同時反映在詳情、異動紀錄與可售庫存。
    public function testCreationReservesEveryItemAndKeepsItsSnapshot(): void
    {
        // 使用不同單價及數量，讓錯誤金額或庫存變化可被區分。
        $this->insertFixture();
        $this->authenticate(2);
        $response = $this
            ->postJson('/api/orders', [
                'storeId' => 1,
                'operationKey' => 'multiple-items',
                'items' => [
                    ['productId' => 1, 'quantity' => 2],
                    ['productId' => 2, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        // 建立及到期以同一個資料庫時間為準，明細與保留歷程逐筆對應。
        $order = $response->json('data');
        $createdAt = $order['createdAt'];
        $expiresAt = $order['expiresAt'];
        self::assertIsInt($order['id']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/D', $createdAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/D', $expiresAt);
        self::assertEquals(CarbonImmutable::parse($createdAt)->addMinutes(15), CarbonImmutable::parse($expiresAt));
        $response->assertExactJson(['data' => [
            'id' => $order['id'],
            'storeId' => 1,
            'createdBy' => 2,
            'status' => 'awaiting_payment',
            'total' => 150,
            'createdAt' => $createdAt,
            'expiresAt' => $expiresAt,
            'items' => [
                ['productId' => 1, 'name' => '濾掛咖啡', 'quantity' => 2, 'unitPrice' => 45, 'subtotal' => 90],
                ['productId' => 2, 'name' => '燕麥餅乾', 'quantity' => 1, 'unitPrice' => 60, 'subtotal' => 60],
            ],
            'history' => [['status' => 'awaiting_payment', 'occurredAt' => $createdAt]],
            'stockLedger' => [
                ['productId' => 1, 'quantity' => 2, 'type' => 'reserved', 'occurredAt' => $createdAt],
                ['productId' => 2, 'quantity' => 1, 'type' => 'reserved', 'occurredAt' => $createdAt],
            ],
            'payment' => null,
            'anomalies' => [],
        ]]);

        // 目錄後續改名調價不會改寫歷史快照，實體庫存也未被扣除。
        DB::table('products')->where('id', 1)->update(['name' => '新濾掛咖啡', 'price' => 70]);
        $this
            ->getJson('/api/orders/' . $order['id'])
            ->assertOk()
            ->assertExactJson($response->json());
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data', [
                ['id' => 1, 'name' => '新濾掛咖啡', 'price' => 70, 'onHand' => 12, 'available' => 10],
                ['id' => 2, 'name' => '燕麥餅乾', 'price' => 60, 'onHand' => 3, 'available' => 2],
            ]);
    }

    // 期限前可取消，恰好到期改成 expired，兩條路徑的每一品項都只能釋放一次。
    public function testCancellationAndExactDeadlineBothReleaseOnce(): void
    {
        // 固定 PostgreSQL 的時間函式，能在真實查詢上重現相差一微秒的兩個請求。
        $this->insertFixture();
        $this->authenticate(2);
        $this->setDatabaseClock('2026-11-06 00:00:00.000001+00');
        $items = [['productId' => 1, 'quantity' => 3], ['productId' => 2, 'quantity' => 1]];
        $cancellable = $this
            ->createOrder('before', $items)
            ->assertCreated()
            ->json('data.id');
        $expiring = $this
            ->createOrder('exactly', $items)
            ->assertCreated()
            ->json('data.id');

        // 到期前一微秒仍能取消；重複取消應回傳完全相同的資料。
        $this->setDatabaseClock('2026-11-06 00:15:00.000000+00');
        $cancel = $this
            ->postJson("/api/orders/$cancellable/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this
            ->postJson("/api/orders/$cancellable/cancel")
            ->assertOk()
            ->assertExactJson($cancel->json());
        $this->assertSingleRelease($cancel, 'cancelled', '2026-11-06T00:15:00.000000Z');

        // 恰好到期的取消回衝突，仍需提交整筆到期與釋放紀錄。
        $this->setDatabaseClock('2026-11-06 00:15:00.000001+00');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this
                ->postJson("/api/orders/$expiring/cancel")
                ->assertConflict()
                ->assertJsonPath('code', 'ORDER_STATE_CONFLICT');
        }
        $expired = $this
            ->getJson("/api/orders/$expiring")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
        $this->assertSingleRelease($expired, 'expired', '2026-11-06T00:15:00.000001Z');
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.available', 12)
            ->assertJsonPath('data.1.available', 3);
    }

    // 後一品項缺貨時整單不成立，另一間門市的可售量也不受影響。
    public function testInsufficientStockLeavesNoPartialOrderAndDoesNotAffectAnotherStore(): void
    {
        $this->insertFixture();
        $this->authenticate(1);
        $this
            ->createOrder('shortage', [['productId' => 1, 'quantity' => 12], ['productId' => 2, 'quantity' => 4]])
            ->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        // 兩店庫存完整可售，訂單、狀態及異動資料都未增加。
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_history', 0);
        $this->assertDatabaseCount('stock_ledger', 0);
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.available', 12)
            ->assertJsonPath('data.1.available', 3);

        // 使用相同鍵修正先前失敗的內容，跨店建立不扣另一門市庫存。
        $this
            ->createOrder('shortage', [['productId' => 1, 'quantity' => 12]])
            ->assertCreated();
        $this
            ->createOrder('taichung', [['productId' => 1, 'quantity' => 4]], 2)
            ->assertCreated();
        foreach ([1 => 12, 2 => 4] as $storeId => $onHand) {
            $this
                ->getJson("/api/stores/$storeId/inventory")
                ->assertOk()
                ->assertJsonPath('data.0.available', 0)
                ->assertJsonPath('data.0.onHand', $onHand);
        }
    }

    // 操作鍵綁定使用者與正規化後的訂購內容，保留字母大小寫。
    public function testReplayUsesSameOrderAndRejectsChangedContentAcrossStores(): void
    {
        $this->insertFixture();
        $this->authenticate(1);
        $items = [['productId' => 1, 'quantity' => 2], ['productId' => 2, 'quantity' => 1]];
        $first = $this->createOrder('Shared-Key', $items)->assertCreated();
        $id = $first->json('data.id');

        // 調價及重新排列明細不影響重送，保留和建立歷程也不增加。
        DB::table('products')->where('id', 1)->update(['price' => 80]);
        $this
            ->createOrder('Shared-Key', array_reverse($items))
            ->assertOk()
            ->assertExactJson($first->json());
        $this
            ->createOrder('Shared-Key', [['productId' => 1, 'quantity' => 2]])
            ->assertConflict()
            ->assertJsonPath('code', 'OPERATION_KEY_CONFLICT');
        $this
            ->createOrder('Shared-Key', $items, 2)
            ->assertConflict()
            ->assertJsonPath('code', 'OPERATION_KEY_CONFLICT');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_history', 1);
        $this->assertDatabaseCount('stock_ledger', 2);

        // 取消後重送回同一張單的目前狀態，大小寫不同或使用者不同可另行下單。
        $cancelled = $this->postJson("/api/orders/$id/cancel")->assertOk();
        $this
            ->createOrder('Shared-Key', $items)
            ->assertOk()
            ->assertExactJson($cancelled->json());
        $this->createOrder('shared-key', $items)->assertCreated();
        $this->authenticate(2);
        $this
            ->createOrder('Shared-Key', $items)
            ->assertCreated()
            ->assertJsonPath('data.createdBy', 2);
        $this->assertDatabaseCount('orders', 3);

        // Unicode、前後空白及長鍵都是操作鍵本身的一部分。
        $oneItem = [['productId' => 1, 'quantity' => 1]];
        $key = ' ' . str_repeat('保留', 150) . ' ';
        $unicode = $this->createOrder($key, $oneItem)->assertCreated();
        $this
            ->createOrder($key, $oneItem)
            ->assertOk()
            ->assertExactJson($unicode->json());
        $this->createOrder(trim($key), $oneItem)->assertCreated();
        $this->assertDatabaseCount('orders', 5);
    }

    // 回應遺失後重送可直接取得到期結果，且只釋放該操作鍵對應訂單的庫存。
    public function testReplayFinalizesOnlyItsOwnReservationAtOrAfterDeadline(): void
    {
        // 建立兩張同時間到期的多品項訂單，讓錯誤的整店到期處理能被辨識。
        $this->insertFixture();
        $this->authenticate(2);
        $this->setDatabaseClock('2026-11-06 00:00:00.000001+00');
        $items = [['productId' => 1, 'quantity' => 3], ['productId' => 2, 'quantity' => 1]];
        $first = $this->createOrder('exact-deadline', $items)->assertCreated();
        $second = $this->createOrder('past-deadline', $items)->assertCreated();
        $firstId = $first->json('data.id');
        $secondId = $second->json('data.id');

        // 期限前一微秒的有效重送仍回原狀；恰好到期時異內容不觸發釋放。
        $this->setDatabaseClock('2026-11-06 00:15:00.000000+00');
        $this
            ->createOrder('exact-deadline', $items)
            ->assertOk()
            ->assertExactJson($first->json());
        $this->setDatabaseClock('2026-11-06 00:15:00.000001+00');
        $this
            ->createOrder('exact-deadline', [$items[0]])
            ->assertConflict()
            ->assertJsonPath('code', 'OPERATION_KEY_CONFLICT');
        $this
            ->getJson("/api/orders/$firstId")
            ->assertOk()
            ->assertExactJson($first->json());

        // 同鍵同內容的有效重送回原訂單及一次完整釋放；另一張逾期單維持原狀。
        $expired = $this
            ->createOrder('exact-deadline', array_reverse($items))
            ->assertOk()
            ->assertJsonPath('data.id', $firstId)
            ->assertJsonPath('data.status', 'expired');
        $this->assertSingleRelease($expired, 'expired', '2026-11-06T00:15:00.000001Z');
        $this
            ->getJson("/api/orders/$secondId")
            ->assertOk()
            ->assertExactJson($second->json());

        // 晚於期限也可從重送復原，之後重送或取消不會再增加任何異動。
        $this->setDatabaseClock('2026-11-06 00:15:00.000002+00');
        $pastDeadline = $this
            ->createOrder('past-deadline', $items)
            ->assertOk()
            ->assertJsonPath('data.id', $secondId)
            ->assertJsonPath('data.status', 'expired');
        $this->assertSingleRelease($pastDeadline, 'expired', '2026-11-06T00:15:00.000002Z');
        $this
            ->createOrder('exact-deadline', $items)
            ->assertOk()
            ->assertExactJson($expired->json());
        $this
            ->postJson("/api/orders/$firstId/cancel")
            ->assertConflict()
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT');
        $this
            ->getJson("/api/orders/$firstId")
            ->assertOk()
            ->assertExactJson($expired->json());
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.available', 12)
            ->assertJsonPath('data.1.available', 3);
        $this->assertDatabaseCount('orders', 2);
    }

    // 真實原始 JSON 應拒絕非整數、超界、重複品項與多餘欄位。
    public function testInvalidJsonAndOrderInputsHaveNoBusinessEffects(): void
    {
        $this->insertFixture();
        $this->authenticate(2);
        $valid = '{"storeId":1,"operationKey":"raw","items":[{"productId":1,"quantity":1}]}';

        // 讓一個不安全的商品識別確實存在，避免把商品不存在誤當成 JSON 邊界已驗證。
        $unsafeId = 9007199254740992;
        DB::table('products')->insert(['id' => $unsafeId, 'name' => '超界商品', 'price' => 1]);
        DB::table('store_inventory')->insert(['store_id' => 1, 'product_id' => $unsafeId, 'on_hand' => 1]);

        // 直接送出各數值欄位的原始字面值，避免 PHP 測試工具把浮點數轉為整數。
        $invalidNumbers = ['"1"', 'true', 'null', '1.0', '1e0', '0', '-1', '9007199254740992', '9223372036854775808'];
        foreach (['storeId', 'productId', 'quantity'] as $field) {
            foreach ($invalidNumbers as $bad) {
                $body = str_replace('"' . $field . '":1', '"' . $field . '":' . $bad, $valid);
                $this
                    ->postRawOrder($body)
                    ->assertUnprocessable()
                    ->assertJsonPath('code', 'VALIDATION_FAILED');
            }
        }

        // 拒絕物件型明細、空清單、價格覆寫及未定義的欄位。
        $badBodies = [
            substr($valid, 0, -1),
            str_replace('[{"productId":1,"quantity":1}]', '{"0":{"productId":1,"quantity":1}}', $valid),
            str_replace('[{"productId":1,"quantity":1}]', '[]', $valid),
            str_replace('{"storeId"', '{"total":1,"storeId"', $valid),
            str_replace('{"productId"', '{"unitPrice":1,"productId"', $valid),
            str_replace('"raw"', '""', $valid),
            str_replace('"raw"', 'true', $valid),
        ];
        foreach ($badBodies as $body) {
            $this
                ->postRawOrder($body)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'VALIDATION_FAILED');
        }

        // 確認同一商品不能列兩次，101 個相異商品與超出 64 KiB 的本文也不成立。
        $this
            ->createOrder('duplicate', [['productId' => 1, 'quantity' => 1], ['productId' => 1, 'quantity' => 2]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        // 所有 101 個商品均存在且有貨，讓錯誤能明確歸因於筆數上限。
        $products = [];
        $stock = [];
        foreach (range(3, 101) as $id) {
            $products[] = ['id' => $id, 'name' => "測試商品 $id", 'price' => 1];
            $stock[] = ['store_id' => 1, 'product_id' => $id, 'on_hand' => 1];
        }
        DB::table('products')->insert($products);
        DB::table('store_inventory')->insert($stock);
        $items = [];
        foreach (range(1, 101) as $id) {
            $items[] = ['productId' => $id, 'quantity' => 1];
        }
        $this
            ->createOrder('too-many', $items)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this
            ->postRawOrder(str_pad($valid, 65537))
            ->assertStatus(413)
            ->assertJsonPath('code', 'PAYLOAD_TOO_LARGE');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_history', 0);
        $this->assertDatabaseCount('stock_ledger', 0);

        // 恰好 64 KiB 的有效 JSON 仍可建立訂單，沒有為非法提交消耗操作鍵。
        $this
            ->postRawOrder(str_pad($valid, 65536))
            ->assertCreated()
            ->assertJsonPath('data.total', 45);
        $this
            ->createOrder('exact-item-limit', array_slice($items, 0, 100))
            ->assertCreated()
            ->assertJsonCount(100, 'data.items');
    }

    // 乘法及加法各自超界時拒絕，且不會先寫入歷程或處理其他訂單到期。
    public function testMoneyOverflowDoesNotWriteButSafeIntegerMaximumWorks(): void
    {
        $this->insertFixture();
        $this->authenticate(1);
        $this->setDatabaseClock('2026-11-06 00:00:00+00');
        $existing = $this->createOrder('existing', [['productId' => 1, 'quantity' => 1]])->assertCreated();
        $existingId = $existing->json('data.id');
        $this->setDatabaseClock('2026-11-06 00:15:01+00');

        // 以足夠庫存區分溢位與缺貨，並檢查另一張逾期訂單的狀態保持原樣。
        $max = 9007199254740991;
        DB::table('products')->where('id', 1)->update(['price' => $max]);
        $this
            ->createOrder('multiplication', [['productId' => 1, 'quantity' => 2]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this
            ->createOrder('addition', [['productId' => 1, 'quantity' => 1], ['productId' => 2, 'quantity' => 1]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this
            ->getJson("/api/orders/$existingId")
            ->assertOk()
            ->assertExactJson($existing->json());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_history', 1);
        $this->assertDatabaseCount('stock_ledger', 1);

        // 最大安全整數作為門市、商品、數量及金額仍能以 JSON 整數正確往返。
        DB::table('stores')->insert(['id' => $max, 'name' => '數值邊界門市']);
        DB::table('products')->insert(['id' => $max, 'name' => '數值邊界商品', 'price' => 1]);
        DB::table('store_inventory')->insert(['store_id' => $max, 'product_id' => $max, 'on_hand' => $max]);
        $this
            ->createOrder(str_repeat('k', 128), [['productId' => $max, 'quantity' => $max]], $max)
            ->assertCreated()
            ->assertJsonPath('data.storeId', $max)
            ->assertJsonPath('data.total', $max)
            ->assertJsonPath('data.items.0', [
                'productId' => $max, 'name' => '數值邊界商品', 'quantity' => $max, 'unitPrice' => 1, 'subtotal' => $max,
            ]);
        $this
            ->getJson("/api/stores/$max/inventory")
            ->assertOk()
            ->assertJsonPath('data.0.onHand', $max)
            ->assertJsonPath('data.0.available', 0);
    }

    // 排程未執行時，庫存及新訂單仍會各自處理相關門市的整單到期。
    public function testInventoryAndCreationExpireReservationsWithoutScheduler(): void
    {
        $this->insertFixture();
        $this->authenticate(1);
        $this->setDatabaseClock('2026-11-06 00:00:00+00');
        $items = [['productId' => 1, 'quantity' => 3], ['productId' => 2, 'quantity' => 1]];
        $taipei = $this
            ->createOrder('taipei', $items)
            ->assertCreated()
            ->json('data.id');
        $taichung = $this
            ->createOrder('taichung', [['productId' => 1, 'quantity' => 4]], 2)
            ->assertCreated()
            ->json('data.id');

        // 到期前一微秒庫存仍保留；晚於到期時，只釋放庫存查詢所屬門市。
        $this->setDatabaseClock('2026-11-06 00:14:59.999999+00');
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.available', 9)
            ->assertJsonPath('data.1.available', 2);
        $this->setDatabaseClock('2026-11-06 00:15:00.000001+00');
        $this
            ->getJson('/api/stores/1/inventory?perPage=1')
            ->assertOk()
            ->assertJsonPath('data.0.available', 12);
        $expired = $this
            ->getJson("/api/orders/$taipei")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
        $this->assertSingleRelease($expired, 'expired', '2026-11-06T00:15:00.000001Z');
        $this
            ->getJson("/api/orders/$taichung")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        // 台中必須由開單先釋放舊保留，才能立即再使用全數庫存。
        $this
            ->createOrder('taichung-new', [['productId' => 1, 'quantity' => 4]], 2)
            ->assertCreated();
        $this
            ->getJson("/api/orders/$taichung")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonCount(2, 'data.history')
            ->assertJsonCount(2, 'data.stockLedger')
            ->assertJsonPath('data.stockLedger.1', [
                'productId' => 1, 'quantity' => 4, 'type' => 'released', 'occurredAt' => '2026-11-06T00:15:00.000001Z',
            ]);
    }

    // 排程重跑會略過已釋放訂單，處理中與尚未到期的訂單仍保有庫存。
    public function testExpiryCommandReleasesDueOrdersAndLeavesProcessingReserved(): void
    {
        $this->insertFixture();
        $this->authenticate(1);
        $this->setDatabaseClock('2026-11-06 00:00:00+00');
        $items = [['productId' => 1, 'quantity' => 3], ['productId' => 2, 'quantity' => 1]];
        $due = $this
            ->createOrder('due', $items)
            ->assertCreated()
            ->json('data.id');
        $processing = $this
            ->createOrder('processing', $items)
            ->assertCreated()
            ->json('data.id');
        $anotherStore = $this
            ->createOrder('other-store', [['productId' => 1, 'quantity' => 4]], 2)
            ->assertCreated()
            ->json('data.id');

        // 付款入口在後續票實作，測試資料直接建立處理中狀態與對應歷程。
        DB::table('orders')->where('id', $processing)->update(['status' => 'processing']);
        DB::table('order_history')->insert([
            'order_id' => $processing, 'status' => 'processing', 'occurred_at' => '2026-11-06 00:01:00+00',
        ]);
        $this->setDatabaseClock('2026-11-06 00:10:00+00');
        $future = $this
            ->createOrder('future', $items)
            ->assertCreated()
            ->json('data.id');

        // 命令直跑兩次，兩間門市都只留下單次釋放，其他狀態不受影響。
        $this->setDatabaseClock('2026-11-06 00:15:01+00');
        self::assertSame(0, Artisan::call('store-ordering:expire-orders'));
        self::assertSame(0, Artisan::call('store-ordering:expire-orders'));
        $expired = $this
            ->getJson("/api/orders/$due")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
        $this->assertSingleRelease($expired, 'expired', '2026-11-06T00:15:01.000000Z');
        $this
            ->getJson("/api/orders/$anotherStore")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonCount(2, 'data.history')
            ->assertJsonCount(2, 'data.stockLedger');
        $this
            ->postJson("/api/orders/$processing/cancel")
            ->assertConflict()
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT');
        $processingDetail = $this
            ->getJson("/api/orders/$processing")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonCount(2, 'data.stockLedger');

        // 處理中的舊單重送仍回原詳情及完整保留。
        $this
            ->createOrder('processing', $items)
            ->assertOk()
            ->assertExactJson($processingDetail->json());
        $this
            ->getJson("/api/orders/$future")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonCount(2, 'data.stockLedger');
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.available', 6)
            ->assertJsonPath('data.1.available', 1);
    }

    // 清單篩選及翻頁使用台北日界線與穩定的第二排序鍵。
    public function testListFiltersByExactIdStatusStoreAndTaipeiDayBoundaries(): void
    {
        $this->insertFixture();
        $this->authenticate(1);
        $items = [['productId' => 1, 'quantity' => 1]];
        $this->setDatabaseClock('2026-11-05 15:59:59.999999+00');
        $before = $this
            ->createOrder('before-from', $items)
            ->assertCreated()
            ->json('data.id');
        $this->setDatabaseClock('2026-11-05 16:00:00+00');
        $start = $this
            ->createOrder('exactly-from', $items)
            ->assertCreated()
            ->json('data.id');
        $this->postJson("/api/orders/$start/cancel")->assertOk();
        $this->setDatabaseClock('2026-11-06 15:59:59.999999+00');
        $within = $this
            ->createOrder('within', $items)
            ->assertCreated()
            ->json('data.id');
        $tie = $this
            ->createOrder('same-timestamp', $items, 2)
            ->assertCreated()
            ->json('data.id');
        $this->setDatabaseClock('2026-11-06 16:00:00+00');
        $end = $this
            ->createOrder('exactly-to', $items)
            ->assertCreated()
            ->json('data.id');

        // 各查詢明列完整訂單識別及總筆數，含故意排除的日期、狀態與門市。
        $dates = 'from=2026-11-06&to=2026-11-07';
        $cases = [
            ['', [$end, $tie, $within, $start, $before], 1, 50, 5],
            [$dates, [$tie, $within, $start], 1, 50, 3],
            [$dates . '&perPage=2', [$tie, $within], 1, 2, 3],
            [$dates . '&page=2&perPage=2', [$start], 2, 2, 3],
            [$dates . '&page=3&perPage=2', [], 3, 2, 3],
            [$dates . '&storeId=1&status=cancelled', [$start], 1, 50, 1],
            ["orderNumber=$within", [$within], 1, 50, 1],
            ["orderNumber={$within}9", [], 1, 50, 0],
            ['perPage=100', [$end, $tie, $within, $start, $before], 1, 100, 5],
        ];
        foreach ($cases as [$query, $ids, $page, $perPage, $total]) {
            $response = $this
                ->getJson('/api/orders?' . $query)
                ->assertOk()
                ->assertJsonPath('meta', ['page' => $page, 'perPage' => $perPage, 'total' => $total]);
            self::assertSame($ids, array_column($response->json('data'), 'id'));
        }
        // 以固定時間及金額核對摘要，只回傳清單契約中的欄位。
        $this
            ->getJson("/api/orders?orderNumber=$within")
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $within,
                    'storeId' => 1,
                    'createdBy' => 1,
                    'status' => 'awaiting_payment',
                    'total' => 45,
                    'createdAt' => '2026-11-06T15:59:59.999999Z',
                    'expiresAt' => '2026-11-06T16:14:59.999999Z',
                ]],
                'meta' => ['page' => 1, 'perPage' => 50, 'total' => 1],
            ]);
        // 不合法的頁碼、日期、狀態及識別一律回欄位驗證錯誤。
        $invalid = ['perPage=101', 'page=0', 'from=2026-02-30', 'to=0000-01-01', 'status=unknown', 'orderNumber=%2B1'];
        foreach ($invalid as $bad) {
            $this
                ->getJson('/api/orders?' . $bad)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'VALIDATION_FAILED');
        }
    }

    // 兩店員只能看到各自門市；總部可以跨店，訂單不提供修改入口。
    public function testOrderAccessIsStoreScopedAndOrdersCannotBeEdited(): void
    {
        $this->insertFixture();
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->postJson('/api/orders')->assertUnauthorized();
        $this->getJson('/api/orders/1')->assertUnauthorized();
        $this->postJson('/api/orders/1/cancel')->assertUnauthorized();

        // 在兩店建立資料，再核對查詢及取消的跨店回應與不存在的資源相同。
        $this->authenticate(1);
        $items = [['productId' => 1, 'quantity' => 1]];
        $taipei = $this
            ->createOrder('taipei', $items)
            ->assertCreated()
            ->json('data.id');
        $taichung = $this
            ->createOrder('taichung', $items, 2)
            ->assertCreated()
            ->json('data.id');
        $cases = [2 => [$taipei, $taichung, 2], 3 => [$taichung, $taipei, 1]];
        foreach ($cases as $actor => [$own, $other, $otherStore]) {
            $this->authenticate($actor);
            $list = $this
                ->getJson('/api/orders')
                ->assertOk()
                ->assertJsonPath('meta.total', 1);
            self::assertSame([$own], array_column($list->json('data'), 'id'));
            $missing = $this
                ->getJson('/api/orders/999999')
                ->assertNotFound()
                ->json();
            $this->getJson("/api/orders/$own")->assertOk();
            $this
                ->getJson("/api/orders/$other")
                ->assertNotFound()
                ->assertExactJson($missing);
            $this
                ->postJson("/api/orders/$other/cancel")
                ->assertNotFound()
                ->assertExactJson($missing);
            $this
                ->getJson("/api/orders?storeId=$otherStore")
                ->assertNotFound()
                ->assertExactJson($missing);
            $this
                ->getJson('/api/orders?storeId=999999')
                ->assertNotFound()
                ->assertExactJson($missing);
            $this
                ->createOrder('cross-store', $items, $otherStore)
                ->assertNotFound()
                ->assertExactJson($missing);
            $this
                ->createOrder('missing-store', $items, 999999)
                ->assertNotFound()
                ->assertExactJson($missing);
        }

        // 總部也需確認門市確實存在，庫存查詢、訂單查詢及建單回應一致。
        $this->authenticate(1);
        $missingStore = $this
            ->getJson('/api/stores/999999/inventory')
            ->assertNotFound()
            ->json();
        $this
            ->getJson('/api/orders?storeId=999999')
            ->assertNotFound()
            ->assertExactJson($missingStore);
        $this
            ->createOrder('missing-store', $items, 999999)
            ->assertNotFound()
            ->assertExactJson($missingStore);

        // 嘗試覆寫數量、金額或門市會收到 405，且兩單詳情仍維持既有內容。
        $detail = $this->getJson("/api/orders/$taipei")->assertOk();
        $this
            ->patchJson("/api/orders/$taipei", ['storeId' => 2, 'quantity' => 100, 'total' => 1])
            ->assertMethodNotAllowed();
        $this->deleteJson("/api/orders/$taipei")->assertMethodNotAllowed();
        $this
            ->getJson("/api/orders/$taipei")
            ->assertOk()
            ->assertExactJson($detail->json());
        $this
            ->getJson("/api/orders/$taichung")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');
    }

    // 明確保留原始位元組，供 JSON 類型及本文大小驗收。
    private function postRawOrder(string $body): TestResponse
    {
        return $this->call('POST', '/api/orders', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    // 透過授權 HTTP 入口開單，讓各案例共用相同的請求結構。
    private function createOrder(string $key, array $items, int $storeId = 1): TestResponse
    {
        return $this->postJson('/api/orders', ['storeId' => $storeId, 'operationKey' => $key, 'items' => $items]);
    }

    // 在隔離的測試交易覆寫資料庫牆鐘；交易回滾會移除設定及函式。
    private function setDatabaseClock(string $time): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS order_api_clock');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_api_clock.clock_timestamp() RETURNS timestamptz
            LANGUAGE sql VOLATILE AS $$ SELECT current_setting('store_ordering.test_clock')::timestamptz $$
            SQL);
        DB::statement('SET LOCAL search_path TO order_api_clock, pg_catalog, public');
        DB::scalar("SELECT set_config('store_ordering.test_clock', ?, true)", [$time]);
    }

    // 檢查同一份詳情中，每個原始保留各自對應一次完整釋放。
    private function assertSingleRelease(TestResponse $response, string $status, string $occurredAt): void
    {
        $response
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.1', ['status' => $status, 'occurredAt' => $occurredAt])
            ->assertJsonCount(4, 'data.stockLedger')
            ->assertJsonPath('data.stockLedger.2', [
                'productId' => 1, 'quantity' => 3, 'type' => 'released', 'occurredAt' => $occurredAt,
            ])
            ->assertJsonPath('data.stockLedger.3', [
                'productId' => 2, 'quantity' => 1, 'type' => 'released', 'occurredAt' => $occurredAt,
            ]);
    }

    // 建立可辨識的門市、角色及商品，所有訂單仍透過公開 API 建立。
    private function insertFixture(): void
    {
        // 用固定角色和門市識別驗證相同 API 在不同權限下的結果。
        DB::table('stores')->insert([
            ['id' => 1, 'name' => '台北門市'],
            ['id' => 2, 'name' => '台中門市'],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => '總部人員', 'email' => 'hq@example.test', 'role' => 'headquarters', 'store_id' => null,
                'password' => 'unused'],
            ['id' => 2, 'name' => '台北店員', 'email' => 'taipei@example.test', 'role' => 'staff', 'store_id' => 1,
                'password' => 'unused'],
            ['id' => 3, 'name' => '台中店員', 'email' => 'taichung@example.test', 'role' => 'staff', 'store_id' => 2,
                'password' => 'unused'],
        ]);
        // 兩種商品保留不同單價和兩店不同實體庫存，讓誤用可被讀回結果辨識。
        DB::table('products')->insert([
            ['id' => 1, 'name' => '濾掛咖啡', 'price' => 45],
            ['id' => 2, 'name' => '燕麥餅乾', 'price' => 60],
        ]);
        DB::table('store_inventory')->insert([
            ['store_id' => 1, 'product_id' => 1, 'on_hand' => 12],
            ['store_id' => 1, 'product_id' => 2, 'on_hand' => 3],
            ['store_id' => 2, 'product_id' => 1, 'on_hand' => 4],
            ['store_id' => 2, 'product_id' => 2, 'on_hand' => 0],
        ]);
    }

    // 使用現有登入 guard 及有效的會話期限，聚焦驗證實際 API 的訂單權限及交易結果。
    private function authenticate(int $userId): void
    {
        $now = now()->getTimestamp();
        $this
            ->actingAs(User::query()->findOrFail($userId))
            ->withSession(['authenticated_at' => $now, 'last_activity_at' => $now]);
    }
}
