<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class OrderApiTest extends TestCase
{
    // 從無登入的通知入口完成交易，再重送同一事件確認歷程及庫存只異動一次。
    public function testSignedPaymentNotificationSettlesOnceWithoutSession(): void
    {
        $this->insertFixture();
        $binding = $this->startPendingPayment('signed-notification');
        $orderId = $binding['orderId'];
        $event = ['eventId' => (string) Str::uuid(), 'status' => 'paid'] + $binding;
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        // 通知無瀏覽器會話，連送兩次後再透過授權的訂單及庫存端點讀回結果。
        $this->app['auth']->forgetGuards();
        $expected = ['data' => ['eventId' => $event['eventId']]];
        $this->postSignedNotification($body)->assertOk()->assertExactJson($expected)->assertHeaderMissing('Set-Cookie');
        $this->postSignedNotification($body)->assertOk()->assertExactJson($expected);
        $this->authenticate(2);
        $detail = $this
            ->getJson("/api/orders/$orderId")
            ->assertOk()
            ->assertJsonPath('data.payment', [
                'id' => $binding['paymentId'], 'status' => 'paid', 'pendingReason' => null,
            ])
            ->assertJsonCount(0, 'data.anomalies');
        self::assertSame(
            ['awaiting_payment', 'processing', 'paid'],
            array_column($detail->json('data.history'), 'status'),
        );
        self::assertSame(['reserved', 'deducted', 'released'], array_column($detail->json('data.stockLedger'), 'type'));
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 10)
            ->assertJsonPath('data.0.available', 10);
    }

    // 驗簽優先於 JSON 驗證；有簽章但格式或數值不合法的通知也不能更改付款。
    public function testNotificationsRejectInvalidAuthenticationAndFieldsWithoutBusinessEffects(): void
    {
        $this->insertFixture();
        $binding = $this->startPendingPayment('notification-invalid');
        $event = ['eventId' => (string) Str::uuid(), 'status' => 'paid'] + $binding;
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $orderId = $binding['orderId'];
        $before = $this->getJson("/api/orders/$orderId")->assertOk()->json();
        $time = now()->startOfSecond();
        $this->travelTo($time);

        // 改動可被 JSON 解析器忽略的位元組，或用過舊／過新的有效簽章，都應拒絕。
        $this->postJson('/api/payment-notifications', $event)->assertForbidden();
        $this->postSignedNotification(' ' . $body, signedBody: $body)->assertForbidden();
        $this->postSignedNotification('{', signedBody: $body)->assertForbidden();
        $this->postSignedNotification($body, $time->getTimestamp() - 301)->assertForbidden();
        $this->postSignedNotification($body, $time->getTimestamp() + 301)->assertForbidden();
        $this->assertDatabaseCount('payment_notification_receipts', 0);
        $this->assertDatabaseCount('payment_anomalies', 0);

        // 保留 JSON 整數的字面型別，避免測試編碼器把整數值的浮點轉為整數。
        $invalid = [
            ['eventId' => 'not-a-uuid'],
            ['paymentId' => 'not-a-uuid'],
            ['orderId' => '1'],
            ['orderId' => true],
            ['orderId' => 1.0],
            ['orderId' => 9007199254740992],
            ['amount' => '90'],
            ['amount' => false],
            ['amount' => 90.0],
            ['amount' => 0],
            ['amount' => 9007199254740992],
            ['currency' => 123],
            ['status' => 'pending'],
            ['extra' => 'unexpected'],
        ];
        $this->postSignedNotification('{')->assertUnprocessable();
        foreach ($invalid as $change) {
            $payload = $change + ['eventId' => (string) Str::uuid()] + $event;
            $raw = json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $this->postSignedNotification($raw)->assertUnprocessable();
        }
        $this->getJson("/api/orders/$orderId")->assertOk()->assertExactJson($before);
        $this->assertDatabaseCount('payment_anomalies', 0);
    }

    // 有效簽章不得讓後面的合法金額遮蔽重複欄位中的非法金額。
    public function testSignedNotificationWithDuplicateAmountKeepsPaymentPending(): void
    {
        $this->insertFixture();
        $binding = $this->startPendingPayment('notification-duplicate-amount');
        $path = '/api/orders/' . $binding['orderId'];
        $inventoryPath = '/api/stores/1/inventory';
        $before = $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'processing')->json();
        $stock = $this->getJson($inventoryPath)->assertOk()->json();

        // 直接拼入錯誤型別或錯誤金額，保留同名鍵及其 JSON 跳脫寫法。
        foreach (['"amount":"90"', '"\u0061mount":91'] as $firstAmount) {
            $eventId = (string) Str::uuid();
            $event = ['eventId' => $eventId, 'status' => 'paid'] + $binding;
            $valid = json_encode($event, JSON_THROW_ON_ERROR);
            $duplicate = '{' . $firstAmount . ',' . substr($valid, 1);
            $this->postSignedNotification($duplicate)->assertUnprocessable();
            $this->postSignedNotification($duplicate)->assertUnprocessable();
            $this->assertDatabaseHas('payment_notification_receipts', ['event_id' => $eventId, 'status' => 422]);
        }

        // 收件拒絕後仍維持處理中，訂單詳情與庫存可讀回原狀。
        $this->getJson($path)->assertOk()->assertExactJson($before);
        $this->getJson($inventoryPath)->assertOk()->assertExactJson($stock);
    }

    // 跳脫鍵名仍可用於有效通知，但事件識別重複時不能領取任何收件鍵。
    public function testSignedNotificationRequiresOneUnambiguousEventId(): void
    {
        $this->insertFixture();
        $binding = $this->startPendingPayment('notification-unambiguous-id');
        $path = '/api/orders/' . $binding['orderId'];
        $eventId = (string) Str::uuid();
        $otherId = (string) Str::uuid();

        // 用不同欄位順序、空白與合法跳脫字元，避免只接受原服務序列化的格式。
        $template = <<<'JSON'
            {
                "status" : "\u0070aid",
                "currency" : "\u0054WD",
                "amount" : 90,
                "orderId" : %d,
                "payment\u0049d" : "%s",
                "event\u0049d" : "%s"
            }
            JSON;
        $valid = sprintf($template, $binding['orderId'], $binding['paymentId'], $eventId);
        $ambiguous = '{"eventId":"' . $otherId . '",' . substr($valid, 1);
        $this->postSignedNotification($ambiguous)->assertUnprocessable();
        $this->assertDatabaseCount('payment_notification_receipts', 0);
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'processing');

        // 確認合法原文可用同一事件完成付款，再測試帶有誤導性巢狀鍵的異內容重送。
        $expected = ['data' => ['eventId' => $eventId]];
        $this->postSignedNotification($valid)->assertOk()->assertExactJson($expected);
        $settled = $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'paid')->json('data');
        $nestedAmount = '"amount":{"eventId":"inner","note":"x: } ] \"eventId\": y"}';
        $conflict = '{' . $nestedAmount . ',' . substr($valid, 1);
        $this->postSignedNotification($conflict)->assertConflict();
        $this->postSignedNotification($conflict)->assertConflict();
        $this->postSignedNotification($valid)->assertOk()->assertExactJson($expected);

        // 保留一次異常及原終局，巢狀內容不能讓收件結果或既有異動被覆寫。
        $this
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.history', $settled['history'])
            ->assertJsonPath('data.stockLedger', $settled['stockLedger'])
            ->assertJsonCount(1, 'data.anomalies')
            ->assertJsonPath('data.anomalies.0.reason', 'event_content_conflict');
    }

    // 異常只能歸給真實綁定的付款；衝突本文、錯誤綁定和相反終局不改變庫存及歷程。
    public function testConflictingNotificationsKeepTheOriginalBindingAndDisposition(): void
    {
        $this->insertFixture();
        $binding = $this->startPendingPayment('notification-conflict');
        $other = $this->startPendingPayment('notification-unrelated', storeId: 2);
        $this->authenticate(1);
        $orderId = $binding['orderId'];
        $otherId = $other['orderId'];
        $path = "/api/orders/$orderId";
        $otherPath = "/api/orders/$otherId";
        $pending = $this->getJson($path)->assertOk()->json('data');
        $untouched = $this->getJson($otherPath)->assertOk()->json();

        // 金額、幣別及訂單不符各留下原因，幣別字串中的冒號和括號不影響欄位解析。
        $invalid = [
            'amount_mismatch' => ['amount' => 91],
            'currency_mismatch' => ['currency' => 'T:WD}'],
            'order_id_mismatch' => ['orderId' => $otherId],
        ];
        foreach ($invalid as $reason => $change) {
            $eventId = (string) Str::uuid();
            $body = json_encode($change + ['eventId' => $eventId, 'status' => 'paid'] + $binding, JSON_THROW_ON_ERROR);
            $this->postSignedNotification($body)->assertConflict();
            $this->postSignedNotification($body)->assertConflict();
            $this->assertDatabaseHas('payment_anomalies', [
                'notification_event_id' => $eventId, 'order_id' => $orderId, 'reason' => $reason,
            ]);
        }
        $this
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.payment', $pending['payment'])
            ->assertJsonPath('data.history', $pending['history'])
            ->assertJsonPath('data.stockLedger', $pending['stockLedger'])
            ->assertJsonCount(3, 'data.anomalies');

        // 查不到付款識別時保留異常，但不採信本文提供的訂單識別。
        $orphan = (string) Str::uuid();
        $body = json_encode([
            'eventId' => $orphan, 'status' => 'paid', 'paymentId' => (string) Str::uuid(),
        ] + $other, JSON_THROW_ON_ERROR);
        $this->postSignedNotification($body)->assertConflict();
        $this->assertDatabaseHas('payment_anomalies', [
            'notification_event_id' => $orphan, 'order_id' => null, 'reason' => 'payment_id_mismatch',
        ]);

        // 有效失敗只釋放保留；同識別的異內容只連到首次收件的可信訂單。
        $eventId = (string) Str::uuid();
        $event = ['eventId' => $eventId, 'status' => 'failed'];
        $body = json_encode($event + $binding, JSON_THROW_ON_ERROR);
        $this->postSignedNotification($body)->assertOk();
        $this->postSignedNotification($body)->assertOk();
        $changed = json_encode($event + $other, JSON_THROW_ON_ERROR);
        $this->postSignedNotification($changed)->assertConflict();
        $this->postSignedNotification($changed)->assertConflict();
        $opposite = ['eventId' => (string) Str::uuid(), 'status' => 'paid'] + $binding;
        $this->postSignedNotification(json_encode($opposite, JSON_THROW_ON_ERROR))->assertConflict();
        $this->postSignedNotification($body)->assertOk();

        // 比對終局詳情、歷程、可售量及另一店的原資料，確保錯誤內容沒有業務副作用。
        $settled = $this
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.payment', [
                'id' => $binding['paymentId'], 'status' => 'failed', 'pendingReason' => null,
            ])
            ->assertJsonCount(5, 'data.anomalies')
            ->assertJsonPath('data.anomalies.3.reason', 'event_content_conflict')
            ->assertJsonPath('data.anomalies.4.reason', 'terminal_conflict');
        self::assertSame(
            ['awaiting_payment', 'processing', 'failed'],
            array_column($settled->json('data.history'), 'status'),
        );
        self::assertSame(['reserved', 'released'], array_column($settled->json('data.stockLedger'), 'type'));
        $this->getJson($otherPath)->assertOk()->assertExactJson($untouched);
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 12)
            ->assertJsonPath('data.0.available', 12);
    }

    // 總部可代理派送，只有收到相符的持久化回覆才向前端確認已排入。
    public function testHeadquartersDispatchAuthorizesAndValidatesTheRemoteAcceptance(): void
    {
        $this->insertFixture();
        $paymentId = (string) Str::uuid();
        $eventId = (string) Str::uuid();
        $path = "/api/mock-payments/$paymentId/dispatch";
        $url = "http://mock-payment:8000/api/payments/$paymentId/dispatch";
        $accepted = ['data' => ['eventId' => $eventId, 'mode' => 'delayed']];
        Http::preventStrayRequests()->fake([
            $url => Http::sequence()
                ->push($accepted, 202)
                ->pushStatus(409)
                ->pushStatus(404)
                ->push($accepted, 202)
                ->push($accepted, 200),
        ]);

        // 未登入及店員皆不得外呼；總部只接受固定的 JSON 模式欄位。
        $this->postJson($path, ['mode' => 'immediate'])->assertUnauthorized();
        $this->authenticate(2);
        $this->postJson($path, ['mode' => 'immediate'])->assertForbidden();
        $this->authenticate(1);
        $invalid = [['mode' => ' delayed '], ['mode' => true], ['mode' => 'delayed', 'delay' => 1], ['delayed']];
        foreach ($invalid as $body) {
            $this->postJson($path, $body)->assertUnprocessable();
        }
        $duplicateMode = '{"mode":"immediate","mode":"delayed"}';
        $this
            ->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], $duplicateMode)
            ->assertUnprocessable();
        Http::assertNothingSent();

        // 保留遠端衝突及查無回應；對不相符模式或 HTTP 狀態回報服務錯誤。
        $this->postJson($path, ['mode' => 'delayed'])->assertAccepted()->assertExactJson($accepted);
        $this->postJson($path, ['mode' => 'immediate'])->assertConflict();
        $this->postJson($path, ['mode' => 'immediate'])->assertNotFound();
        $this->postJson($path, ['mode' => 'immediate'])->assertServiceUnavailable();
        $this->postJson($path, ['mode' => 'delayed'])->assertServiceUnavailable();

        // 驗證實際外呼只攜帶核對後的欄位。
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === $url
            && $request->data() === ['mode' => 'delayed']);
    }

    // 首次付款必須先保存固定識別與待辦，再由 HTTP 取得模擬金流的待處理結果。
    public function testPaymentStartRecordsIntentBeforeCallingMockPayment(): void
    {
        $this->insertFixture();
        $this->authenticate(2);
        $orderId = $this
            ->createOrder('payment-start', [['productId' => 1, 'quantity' => 2]])
            ->assertCreated()
            ->json('data.id');
        $fixtureTransactionLevel = DB::transactionLevel();

        // RefreshDatabase 保留測試外層交易；外呼時付款自己的交易必須已結束。
        Http::fake(function (Request $request) use ($fixtureTransactionLevel, $orderId): PromiseInterface {
            self::assertSame($fixtureTransactionLevel, DB::transactionLevel());
            self::assertSame('POST', $request->method());
            self::assertSame('http://mock-payment:8000/api/payments', $request->url());
            self::assertSame('processing', DB::table('orders')->where('id', $orderId)->value('status'));
            $intent = DB::table('payment_intents')->where('order_id', $orderId)->first();
            self::assertNotNull($intent);
            self::assertTrue($intent->create_pending);
            self::assertSame([
                'paymentId' => $intent->payment_id, 'orderId' => $orderId, 'amount' => 90, 'currency' => 'TWD',
            ], $request->data());

            return Http::response(['data' => $request->data() + ['status' => 'pending']], 201);
        });

        $payment = $this
            ->postJson("/api/orders/$orderId/payment")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.pendingReason', 'awaiting_result')
            ->assertJsonPath('data.history.1.status', 'processing')
            ->assertJsonCount(1, 'data.stockLedger')
            ->json('data.payment.id');
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/D', $payment);
        Http::assertSentCount(1);
    }

    // 恰好到期時付款不得外呼或留下意圖，已到期保留仍須完整釋放。
    public function testPaymentCannotStartAtExactDeadline(): void
    {
        $this->insertFixture();
        $this->authenticate(2);
        Http::fake();
        $this->setDatabaseClock('2026-11-06 00:00:00.000000+00');
        $orderId = $this
            ->createOrder('payment-exact-deadline', [['productId' => 1, 'quantity' => 3]])
            ->assertCreated()
            ->json('data.id');

        // 未發起不得人工查詢，時間到時也不得以付款啟動取代到期。
        $this
            ->postJson("/api/orders/$orderId/payment/query")
            ->assertConflict()
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT');
        $this->setDatabaseClock('2026-11-06 00:15:00.000000+00');
        $this
            ->postJson("/api/orders/$orderId/payment")
            ->assertConflict()
            ->assertJsonPath('code', 'ORDER_STATE_CONFLICT');
        $this
            ->getJson("/api/orders/$orderId")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.payment', null)
            ->assertJsonCount(2, 'data.stockLedger')
            ->assertJsonPath('data.stockLedger.1', [
                'productId' => 1, 'quantity' => 3, 'type' => 'released', 'occurredAt' => '2026-11-06T00:15:00.000000Z',
            ]);
        $this->assertDatabaseCount('payment_intents', 0);
        Http::assertNothingSent();
    }

    // 請求及回應接連遺失時保持原庫存，人工查詢須用同一識別找回並完成付款。
    public function testUnknownPaymentRecoversWithSamePayloadAndDeductsOnce(): void
    {
        $this->insertFixture();
        $this->authenticate(2);
        $orderId = $this
            ->createOrder('payment-unknown', [['productId' => 1, 'quantity' => 2], ['productId' => 2, 'quantity' => 1]])
            ->assertCreated()
            ->json('data.id');

        // 首次請求未到；第一次補送仍查無資料；第二次補送已建立但回應遺失。
        $calls = [];
        Http::fake(static function (Request $request) use (&$calls): PromiseInterface {
            $calls[] = [$request->method(), $request->url(), $request->data()];

            return match (count($calls)) {
                1, 5 => throw new ConnectionException('simulated lost request or response'),
                2, 3, 4 => Http::response([], 404),
                default => Http::response(['data' => $calls[0][2] + ['status' => 'paid']]),
            };
        });
        $paymentId = $this
            ->postJson("/api/orders/$orderId/payment")
            ->assertOk()
            ->assertJsonPath('data.payment.pendingReason', 'service_unavailable')
            ->json('data.payment.id');
        foreach (['payment_not_found', 'service_unavailable'] as $reason) {
            // 每次未知結果都保留兩品項，取消也不能搶走已提交的付款意圖。
            $this
                ->postJson("/api/orders/$orderId/payment/query")
                ->assertOk()
                ->assertJsonPath('data.status', 'processing')
                ->assertJsonPath('data.payment.id', $paymentId)
                ->assertJsonPath('data.payment.pendingReason', $reason)
                ->assertJsonCount(2, 'data.history')
                ->assertJsonCount(2, 'data.stockLedger');
            $this->postJson("/api/orders/$orderId/cancel")->assertConflict();
            $this
                ->getJson('/api/stores/1/inventory')
                ->assertOk()
                ->assertJsonPath('data.0.onHand', 12)
                ->assertJsonPath('data.0.available', 10)
                ->assertJsonPath('data.1.onHand', 3)
                ->assertJsonPath('data.1.available', 2);
        }

        // 找回成功後，兩品項只扣除及釋放一次，重複查詢及發起回傳完全相同的終局。
        $settled = $this
            ->postJson("/api/orders/$orderId/payment/query")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment', ['id' => $paymentId, 'status' => 'paid', 'pendingReason' => null])
            ->assertJsonPath('data.history.2.status', 'paid')
            ->assertJsonCount(3, 'data.history')
            ->assertJsonCount(6, 'data.stockLedger');
        self::assertSame(
            ['reserved', 'reserved', 'deducted', 'deducted', 'released', 'released'],
            array_column($settled->json('data.stockLedger'), 'type'),
        );
        $this->postJson("/api/orders/$orderId/payment/query")->assertOk()->assertExactJson($settled->json());
        $this->postJson("/api/orders/$orderId/payment")->assertOk()->assertExactJson($settled->json());
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 10)
            ->assertJsonPath('data.0.available', 10)
            ->assertJsonPath('data.1.onHand', 2)
            ->assertJsonPath('data.1.available', 2);

        // 完整比對外呼順序及所有建立欄位，避免錯誤重試改用另一筆付款。
        $base = 'http://mock-payment:8000/api/payments';
        $create = ['POST', $base, [
            'paymentId' => $paymentId, 'orderId' => $orderId, 'amount' => 150, 'currency' => 'TWD',
        ]];
        $find = ['GET', "$base/$paymentId", []];
        self::assertSame([$create, $find, $create, $find, $create, $find, $find], $calls);
        $this->assertDatabaseCount('payment_intents', 1);
    }

    // 三次背景補辦耗盡後仍保留庫存，人工查詢可用同一付款完成訂單。
    public function testAutomaticPaymentRecoveryIsBoundedAndManualQueryStillSettles(): void
    {
        // 讓付款發起與三次背景補辦都遇到中斷，另記錄每次送出的原始綁定。
        $this->insertFixture();
        $this->authenticate(2);
        $this->setDatabaseClock('2026-11-06 00:00:00+00');
        $orderId = $this
            ->createOrder('payment-recovery-budget', [['productId' => 1, 'quantity' => 3]])
            ->assertCreated()
            ->json('data.id');
        $calls = [];
        $settled = false;
        // 模擬同一遠端付款直到最後一筆人工查詢才可讀到終局。
        Http::fake(function (Request $request) use ($orderId, &$calls, &$settled): PromiseInterface {
            $calls[] = [$request->method(), $request->url(), $request->data()];
            // 最後一次背景外呼尚未回應就應顯示耗盡，避免中斷後沒有原因可讀。
            if (count($calls) === 4) {
                $this
                    ->getJson("/api/orders/$orderId")
                    ->assertOk()
                    ->assertJsonPath('data.payment.pendingReason', 'recovery_exhausted');
            }
            if (!$settled) {
                throw new ConnectionException('simulated lost request or response');
            }

            return Http::response(['data' => $calls[0][2] + ['status' => 'paid']]);
        });
        $paymentId = $this
            ->postJson("/api/orders/$orderId/payment")
            ->assertOk()
            ->assertJsonPath('data.payment.pendingReason', 'service_unavailable')
            ->json('data.payment.id');

        // 每分鐘只可補辦一次；第四次排程及之後的執行都不能再送出。
        foreach ([1 => 2, 2 => 3, 3 => 4, 4 => 4, 20 => 4] as $minute => $expectedCalls) {
            $this->setDatabaseClock(sprintf('2026-11-06 00:%02d:00+00', $minute));
            self::assertSame(0, Artisan::call('store-ordering:recover-payments'));
            self::assertSame(0, Artisan::call('store-ordering:recover-payments'));
            self::assertCount($expectedCalls, $calls);
        }

        // 耗盡後的未知查詢也應顯示耗盡原因，付款及庫存仍維持原本狀態。
        $detail = $this->getJson("/api/orders/$orderId");
        $query = $this->postJson("/api/orders/$orderId/payment/query");
        foreach ([$detail, $query] as $pending) {
            $pending
                ->assertOk()
                ->assertJsonPath('data.status', 'processing')
                ->assertJsonPath('data.payment', [
                    'id' => $paymentId, 'status' => 'pending', 'pendingReason' => 'recovery_exhausted',
                ])
                ->assertJsonCount(2, 'data.history')
                ->assertJsonCount(1, 'data.stockLedger');
        }
        $this->postJson("/api/orders/$orderId/cancel")->assertConflict();
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 12)
            ->assertJsonPath('data.0.available', 9);

        // 人工找回成功後只扣除及釋放一次，之後的排程不再外呼。
        $settled = true;
        $paid = $this
            ->postJson("/api/orders/$orderId/payment/query")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment', ['id' => $paymentId, 'status' => 'paid', 'pendingReason' => null])
            ->assertJsonPath('data.history.2.status', 'paid')
            ->assertJsonCount(3, 'data.history');
        self::assertSame(['reserved', 'deducted', 'released'], array_column($paid->json('data.stockLedger'), 'type'));
        self::assertSame(0, Artisan::call('store-ordering:recover-payments'));
        $this->getJson("/api/orders/$orderId")->assertOk()->assertExactJson($paid->json());
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 9)
            ->assertJsonPath('data.0.available', 9);

        // 所有自動建立僅重送一次 HTTP POST，人工查詢皆尋找同一付款。
        $base = 'http://mock-payment:8000/api/payments';
        $create = ['POST', $base, [
            'paymentId' => $paymentId, 'orderId' => $orderId, 'amount' => 135, 'currency' => 'TWD',
        ]];
        $find = ['GET', "$base/$paymentId", []];
        self::assertSame([$create, $create, $create, $create, $find, $find], $calls);
        $this->assertDatabaseCount('payment_intents', 1);
    }

    // 人工已確認建立時，晚到的錯誤背景回應只留下異常，且不能覆蓋已確認的原因。
    public function testLateRecoveryAnomalyPreservesConcurrentManualConfirmation(): void
    {
        // 初次外呼失敗，讓同一付款進入待補辦狀態。
        $this->insertFixture();
        $this->authenticate(2);
        $this->setDatabaseClock('2026-11-06 00:00:00+00');
        $orderId = $this
            ->createOrder('payment-recovery-overlap', [['productId' => 1, 'quantity' => 3]])
            ->assertCreated()
            ->json('data.id');
        $creates = [];
        $lookups = [];
        // 延遲背景回應直到人工查詢完成，以重現兩種回應交錯的順序。
        Http::fake(function (Request $request) use ($orderId, &$creates, &$lookups): PromiseInterface {
            if ($request->method() === 'GET') {
                $lookups[] = $request->url();

                return Http::response(['data' => $creates[0] + ['status' => 'pending']]);
            }

            // 背景補辦尚在外呼時，另一執行不能再占用相同待辦。
            $creates[] = $request->data();
            if (count($creates) === 1) {
                throw new ConnectionException('simulated lost initial request');
            }
            self::assertCount(2, $creates);
            self::assertSame(0, Artisan::call('store-ordering:recover-payments'));
            self::assertCount(2, $creates);

            // 回傳錯誤金額的終局，既不能扣庫存，也不能把已確認的付款改回未知。
            $this
                ->postJson("/api/orders/$orderId/payment/query")
                ->assertOk()
                ->assertJsonPath('data.payment.pendingReason', 'awaiting_result');

            return Http::response(['data' => ['amount' => 136, 'status' => 'paid'] + $request->data()]);
        });
        $paymentId = $this
            ->postJson("/api/orders/$orderId/payment")
            ->assertOk()
            ->assertJsonPath('data.payment.pendingReason', 'service_unavailable')
            ->json('data.payment.id');
        $this->setDatabaseClock('2026-11-06 00:01:00+00');
        self::assertSame(0, Artisan::call('store-ordering:recover-payments'));

        // 跨過租期仍不再自動建立；詳情及庫存只反映人工確認與異常紀錄。
        $this->setDatabaseClock('2026-11-06 00:30:00+00');
        self::assertSame(0, Artisan::call('store-ordering:recover-payments'));
        self::assertSame([$creates[0], $creates[0]], $creates);
        self::assertSame(["http://mock-payment:8000/api/payments/$paymentId"], $lookups);
        $this
            ->getJson("/api/orders/$orderId")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment', [
                'id' => $paymentId, 'status' => 'pending', 'pendingReason' => 'awaiting_result',
            ])
            ->assertJsonPath('data.anomalies.0.reason', 'amount_mismatch')
            ->assertJsonCount(1, 'data.anomalies')
            ->assertJsonCount(1, 'data.stockLedger');
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 12)
            ->assertJsonPath('data.0.available', 9);
    }

    // 遠端回傳正確的待處理或終局，背景都應關閉建立待辦，庫存只受已確認終局影響。
    public function testRecoveryStopsAfterEitherPendingOrPaidConfirmation(): void
    {
        // 用兩張仍待補辦的原單測試同一次排程的兩種有效回應。
        $this->insertFixture();
        $this->authenticate(2);
        $this->setDatabaseClock('2026-11-06 00:00:00+00');
        $calls = [];
        $statuses = [];
        // 模擬遠端先拒絕初次外呼，之後以不同已保存狀態回答原付款識別。
        Http::fake(static function (Request $request) use (&$calls, &$statuses): PromiseInterface {
            $payload = $request->data();
            $calls[] = [$request->method(), $request->url(), $payload];
            $status = $statuses[$payload['paymentId']] ?? null;

            return $status === null
                ? Http::response([], 503)
                : Http::response(['data' => $payload + ['status' => $status]]);
        });
        $orders = [];
        foreach (['pending', 'paid'] as $status) {
            $id = $this
                ->createOrder("payment-recovery-$status", [['productId' => 1, 'quantity' => 3]])
                ->assertCreated()
                ->json('data.id');
            $paymentId = $this
                ->postJson("/api/orders/$id/payment")
                ->assertOk()
                ->assertJsonPath('data.payment.pendingReason', 'service_unavailable')
                ->json('data.payment.id');
            $orders[$status] = [$id, $paymentId];
            $statuses[$paymentId] = $status;
        }

        // 第一次補辦應處理兩張訂單，隔一分鐘再次排程仍不能有新外呼。
        $this->setDatabaseClock('2026-11-06 00:01:00+00');
        self::assertSame(0, Artisan::call('store-ordering:recover-payments'));
        $this->setDatabaseClock('2026-11-06 00:02:00+00');
        self::assertSame(0, Artisan::call('store-ordering:recover-payments'));
        self::assertSame([$calls[0], $calls[1]], array_slice($calls, 2));

        // 待處理只保留原庫存；已付款只扣除及釋放一次。
        [$pendingOrder, $pendingPayment] = $orders['pending'];
        $this
            ->getJson("/api/orders/$pendingOrder")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment', [
                'id' => $pendingPayment, 'status' => 'pending', 'pendingReason' => 'awaiting_result',
            ])
            ->assertJsonCount(1, 'data.stockLedger');
        [$paidOrder, $paidPayment] = $orders['paid'];
        $paid = $this
            ->getJson("/api/orders/$paidOrder")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment', ['id' => $paidPayment, 'status' => 'paid', 'pendingReason' => null])
            ->assertJsonCount(3, 'data.history');
        self::assertSame(['reserved', 'deducted', 'released'], array_column($paid->json('data.stockLedger'), 'type'));
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 9)
            ->assertJsonPath('data.0.available', 6);
    }

    // 逐一拒絕錯誤綁定與非契約型別，確認失敗後晚到的待處理或相反終局不會反轉。
    public function testInvalidObservationsAndTerminalConflictPreserveStock(): void
    {
        $this->insertFixture();
        $this->authenticate(2);
        $orderId = $this
            ->createOrder('payment-binding', [['productId' => 1, 'quantity' => 3]])
            ->assertCreated()
            ->json('data.id');

        // 逐次竄改對外結果，對比本機可信綁定；開始付款先取得正常的 pending 回應。
        $binding = [];
        $change = ['status' => 'pending'];
        Http::fake(static function (Request $request) use (&$binding, &$change): PromiseInterface {
            if ($request->method() === 'POST') {
                $binding = $request->data();
            }

            return Http::response(['data' => $change + $binding]);
        });
        $this->postJson("/api/orders/$orderId/payment")->assertOk();
        $invalid = [
            ['invalid_response', ['amount' => '135']],
            ['invalid_response', ['orderId' => true]],
            ['invalid_response', ['amount' => 9007199254740992]],
            ['invalid_response', ['status' => 'unknown']],
            ['payment_id_mismatch', ['paymentId' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8']],
            ['order_id_mismatch', ['orderId' => $orderId + 1]],
            ['amount_mismatch', ['amount' => 136]],
            ['currency_mismatch', ['currency' => 'USD']],
        ];
        foreach ($invalid as $index => [$reason, $change]) {
            // 錯誤結果皆聲稱終局（未知狀態例外）；每次只新增一筆異常。
            $change += ['status' => 'paid'];
            $this
                ->postJson("/api/orders/$orderId/payment/query")
                ->assertOk()
                ->assertJsonPath('data.status', 'processing')
                ->assertJsonPath('data.payment.pendingReason', 'invalid_response')
                ->assertJsonCount($index + 1, 'data.anomalies')
                ->assertJsonPath("data.anomalies.$index.reason", $reason)
                ->assertJsonCount(1, 'data.stockLedger');
        }

        // 明確失敗只釋放，重送相同失敗與晚到 pending 皆無副作用。
        $change = ['status' => 'failed'];
        $failed = $this
            ->postJson("/api/orders/$orderId/payment/query")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.payment.pendingReason', null)
            ->assertJsonPath('data.stockLedger.1.type', 'released')
            ->assertJsonCount(2, 'data.stockLedger');
        $this->postJson("/api/orders/$orderId/payment/query")->assertOk()->assertExactJson($failed->json());
        $change = ['status' => 'pending'];
        $this->postJson("/api/orders/$orderId/payment/query")->assertOk()->assertExactJson($failed->json());

        // 相反終局只追加異常，詳情、歷程與實體庫存仍符合已確認的失敗結果。
        $change = ['status' => 'paid'];
        $this
            ->postJson("/api/orders/$orderId/payment/query")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.history.2.status', 'failed')
            ->assertJsonCount(3, 'data.history')
            ->assertJsonCount(2, 'data.stockLedger')
            ->assertJsonCount(9, 'data.anomalies')
            ->assertJsonPath('data.anomalies.8.reason', 'terminal_conflict');
        $this
            ->getJson('/api/stores/1/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 12)
            ->assertJsonPath('data.0.available', 12);
    }

    // 門市限制與總部權限先於外呼，總部可透過同來源入口讀取清單及決定結果。
    public function testPaymentAndMockConsoleEnforceAccessBeforeCallingService(): void
    {
        $this->insertFixture();
        $this->authenticate(2);
        $orderId = $this
            ->createOrder('payment-access', [['productId' => 1, 'quantity' => 1]])
            ->assertCreated()
            ->json('data.id');
        $id = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $payment = ['paymentId' => $id, 'orderId' => $orderId, 'amount' => 45, 'currency' => 'TWD', 'status' => 'paid'];
        $meta = ['page' => 1, 'perPage' => 20, 'total' => 1];
        Http::fake([
            'http://mock-payment:8000/api/payments?*' => Http::response(['data' => [$payment], 'meta' => $meta]),
            "http://mock-payment:8000/api/payments/$id/decision" => Http::sequence()
                ->push(['data' => $payment])
                ->push([], 409),
        ]);

        // 跨店與查無訂單使用相同回應，店員無法透過面板觸及外部服務。
        $this->authenticate(3);
        foreach (['payment', 'payment/query'] as $operation) {
            $missing = $this->postJson("/api/orders/9007199254740991/$operation")->assertNotFound();
            $this->postJson("/api/orders/$orderId/$operation")->assertNotFound()->assertExactJson($missing->json());
        }
        $this->getJson('/api/mock-payments')->assertForbidden();
        $this->postJson("/api/mock-payments/$id/decision", ['status' => 'paid'])->assertForbidden();
        Http::assertNothingSent();

        // 總部只能提交合法的決定，遠端衝突回傳穩定代碼且不改變本機訂單。
        $this->authenticate(1);
        $this->getJson('/api/mock-payments?perPage=101')->assertUnprocessable();
        $this->postJson("/api/mock-payments/$id/decision", ['status' => ' paid '])->assertUnprocessable();
        Http::assertNothingSent();
        $this
            ->getJson('/api/mock-payments?perPage=20')
            ->assertOk()
            ->assertExactJson(['data' => [$payment], 'meta' => $meta]);
        $this
            ->postJson("/api/mock-payments/$id/decision", ['status' => 'paid'])
            ->assertOk()
            ->assertExactJson(['data' => $payment]);
        $this
            ->postJson("/api/mock-payments/$id/decision", ['status' => 'failed'])
            ->assertConflict()
            ->assertJsonPath('code', 'PAYMENT_STATE_CONFLICT');
        $this->getJson("/api/orders/$orderId")->assertOk()->assertJsonPath('data.status', 'awaiting_payment');
        Http::assertSentCount(3);
    }

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

    // 透過店員入口建立待處理付款，保留由公開回應取得的固定綁定。
    private function startPendingPayment(string $key, int $storeId = 1): array
    {
        $this->authenticate($storeId + 1);
        $orderId = $this
            ->createOrder($key, [['productId' => 1, 'quantity' => 2]], $storeId)
            ->assertCreated()
            ->json('data.id');

        // 在 HTTP 邊界模擬待處理結果，訂單付款意圖仍由真實交易寫入。
        Http::fake(static fn (Request $request): PromiseInterface => Http::response([
            'data' => ['status' => 'pending'] + $request->data(),
        ]));
        $paymentId = $this
            ->postJson("/api/orders/$orderId/payment")
            ->assertOk()
            ->assertJsonPath('data.payment.pendingReason', 'awaiting_result')
            ->json('data.payment.id');

        return ['paymentId' => $paymentId, 'orderId' => $orderId, 'amount' => 90, 'currency' => 'TWD'];
    }

    // 直接送指定的原始位元組，避免測試工具重編碼後掩蓋驗簽錯誤。
    private function postSignedNotification(
        string $body,
        ?int $timestamp = null,
        ?string $signedBody = null,
    ): TestResponse {
        $key = 'order-api-notification-test-key';
        config(['services.payment_notifications.key' => $key]);
        $timestamp ??= now()->getTimestamp();

        // 傳送時間及簽章只取自測試指定值，可個別改變本文與傳送時間。
        return $this->call('POST', '/api/payment-notifications', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $timestamp . '.' . ($signedBody ?? $body), $key),
        ], $body);
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
