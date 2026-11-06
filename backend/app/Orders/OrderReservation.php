<?php

declare(strict_types=1);

namespace App\Orders;

use App\Http\Requests\OrderDraft;
use App\Http\Responses\OrderDetailData;
use App\Http\Responses\OrderPaymentData;
use App\Http\Responses\PageData;
use App\Http\Responses\PaymentAnomalyData;
use App\Models\User;
use App\Payments\PaymentData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class OrderReservation
{
    // 開單與庫存讀取在同一門市鎖下處理到期，確保可售量及歷程屬於同一個交易。
    public function inventory(int $storeId, PageData $page): LengthAwarePaginator
    {
        // 取得門市鎖後才取資料庫目前時間，避免排隊前的時間延長有效保留。
        return DB::transaction(function () use ($storeId, $page): LengthAwarePaginator {
            $this->lockStore($storeId);
            $this->expireDueOrders($storeId, $this->databaseNow());

            // 繼續依商品識別排序，零庫存商品仍保留在庫存清單。
            return $this
                ->availableInventory($storeId)
                ->join('products', 'products.id', '=', 'store_inventory.product_id')
                ->addSelect('products.id', 'products.name', 'products.price', 'store_inventory.on_hand')
                ->orderBy('products.id')
                ->paginate($page->perPage, page: $page->page);
        });
    }

    /**
     * 依使用者再依門市鎖定，序列化跨店操作鍵及同門市庫存異動。
     *
     * @return array{OrderDetailData, bool}|OrderConflict 回傳詳情及是否為首次建立，或業務衝突。
     */
    public function create(User $actor, OrderDraft $draft): array|OrderConflict
    {
        // 依原始字串產生固定長度的索引鍵，不正規化大小寫、空白或 Unicode。
        $key = hash('sha256', $draft->operationKey);

        // 所有開單請求以使用者、門市的順序取得鎖，其他庫存操作從門市鎖開始。
        return DB::transaction(function () use ($actor, $draft, $key): array|OrderConflict {
            DB::table('users')
                ->where('id', $actor->id)
                ->lock('FOR NO KEY UPDATE')
                ->first(['id']);
            $this->lockStore($draft->storeId);

            // 操作鍵重送以已保存的明細判斷內容，後續商品價格變更不影響辨識。
            $existing = DB::table('orders')
                ->where('created_by', $actor->id)
                ->where('operation_key', $key)
                ->first();
            if ($existing !== null) {
                if ((int) $existing->store_id !== $draft->storeId) {
                    return OrderConflict::OperationKey;
                }

                // 正規化資料庫的數量型別，使重送比較保留 JSON 整數語意。
                $existingItems = DB::table('order_items')
                    ->where('order_id', $existing->id)
                    ->orderBy('product_id')
                    ->pluck('quantity', 'product_id')
                    ->map(static fn (mixed $quantity): int => (int) $quantity)
                    ->all();

                if ($existingItems !== $draft->items) {
                    return OrderConflict::OperationKey;
                }

                // 確認為有效重送後，才鎖住原單並判斷到期，避免異內容請求改變庫存。
                if ($existing->status === 'awaiting_payment') {
                    $existing = $this->lockOrder((int) $existing->id);
                    $this->settleAwaitingOrder($existing, cancelBeforeDeadline: false);
                }

                return [$this->detail($existing), false];
            }

            // 先驗證商品與金額，格式或溢位錯誤不會造成到期等業務變更。
            [$items, $total] = $this->priceItems($draft->items);

            // 先釋放本店已到期的保留，再以同一交易內的可售量判斷整筆是否有貨。
            $now = $this->databaseNow();
            $this->expireDueOrders($draft->storeId, $now);
            $available = $this
                ->availableInventory($draft->storeId)
                ->whereIn('store_inventory.product_id', array_keys($draft->items))
                ->addSelect('store_inventory.product_id')
                ->get()
                ->keyBy('product_id');
            foreach ($draft->items as $productId => $quantity) {
                if ((int) ($available[$productId]->available ?? 0) < $quantity) {
                    return OrderConflict::Stock;
                }
            }

            // 只有整筆有貨才持久化訂單、快照、初始狀態與每筆保留紀錄。
            $createdAt = $this->sqlTime($now);
            $order = [
                'store_id' => $draft->storeId,
                'created_by' => $actor->id,
                'operation_key' => $key,
                'status' => 'awaiting_payment',
                'total' => $total,
                'created_at' => $createdAt,
                'expires_at' => $this->sqlTime($now->addMinutes(15)),
            ];
            $orderId = DB::table('orders')->insertGetId($order);
            $this->recordReservation($orderId, $items, $createdAt);

            return [$this->detail((object) (['id' => $orderId] + $order)), true];
        });
    }

    // 詳情與歷程在同一個共享列鎖下讀取，避免讀到轉移前的狀態及轉移後的歷程。
    public function show(User $actor, int $orderId): OrderDetailData
    {
        // 取得共享列鎖後才查詢歷程；狀態更新需要與此鎖互斥的列鎖。
        return DB::transaction(function () use ($actor, $orderId): OrderDetailData {
            $row = $actor
                ->restrictStores(DB::table('orders'), 'store_id')
                ->where('id', $orderId)
                ->sharedLock()
                ->first();
            if ($row === null) {
                abort(404);
            }

            return $this->detail($row);
        });
    }

    // 在門市及訂單鎖下取消，逾期先提交整筆釋放再回報衝突。
    public function cancel(User $actor, int $orderId): OrderDetailData|OrderConflict
    {
        $storeId = $this->authorizedStoreId($actor, $orderId);

        // 先等候門市與訂單鎖，再以資料庫時間判斷截止與當前狀態。
        return DB::transaction(function () use ($storeId, $orderId): OrderDetailData|OrderConflict {
            $this->lockStore($storeId);
            $order = $this->lockOrder($orderId);
            $this->settleAwaitingOrder($order, cancelBeforeDeadline: true);

            // 既有取消結果可重送；其他狀態統一回報衝突。
            return $order->status === 'cancelled' ? $this->detail($order) : OrderConflict::State;
        });
    }

    // 鎖內決定是否能發起付款；回傳外呼資料前先提交狀態、歷程及建立待辦。
    public function preparePayment(User $actor, int $orderId, bool $start): PaymentData|OrderDetailData|OrderConflict
    {
        $storeId = $this->authorizedStoreId($actor, $orderId);

        // 與取消及到期使用相同鎖序；逾時的發起請求仍須提交整筆到期釋放。
        return DB::transaction(function () use ($storeId, $orderId, $start): PaymentData|OrderDetailData|OrderConflict {
            $this->lockStore($storeId);
            $order = $this->lockOrder($orderId);
            if ($start && $order->status === 'awaiting_payment') {
                $now = $this->databaseNow();
                $this->settleAwaitingOrder($order, cancelBeforeDeadline: false, now: $now);
                if ($order->status === 'awaiting_payment') {
                    $this->recordPaymentIntent($order, $now);
                }
            }

            // 未曾發起的訂單不能查詢；終局或已確認建立時，重送啟動直接回既有結果。
            if (!in_array($order->status, ['processing', 'paid', 'failed'], true)) {
                return OrderConflict::State;
            }
            $intent = DB::table('payment_intents')->where('order_id', $orderId)->firstOrFail();
            if ($start && ($order->status !== 'processing' || !$intent->create_pending)) {
                return $this->detail($order);
            }

            return PaymentData::fromRow($intent, $order->status === 'processing' ? 'pending' : $order->status);
        });
    }

    // 查詢與未來通知的結果套用集中於同一交易，避免終局反轉及重複扣除或釋放。
    public function applyPaymentObservation(
        User $actor,
        int $orderId,
        ?PaymentData $observed,
        ?string $pendingReason,
    ): OrderDetailData {
        $storeId = $this->authorizedStoreId($actor, $orderId);

        // 外呼已結束；重新鎖定本機狀態，決定晚到的結果是否仍能套用。
        return DB::transaction(function () use ($storeId, $orderId, $observed, $pendingReason): OrderDetailData {
            $this->lockStore($storeId);
            $order = $this->lockOrder($orderId);
            $intents = DB::table('payment_intents')->where('order_id', $orderId);
            $intent = $intents->firstOrFail();
            $processing = $order->status === 'processing';
            $anomaly = match (true) {
                $observed === null => $pendingReason === 'invalid_response' ? $pendingReason : null,
                $observed->paymentId !== $intent->payment_id => 'payment_id_mismatch',
                $observed->orderId !== $orderId => 'order_id_mismatch',
                $observed->amount !== (int) $intent->amount => 'amount_mismatch',
                $observed->currency !== $intent->currency => 'currency_mismatch',
                !$processing && !in_array($observed->status, ['pending', $order->status], true) => 'terminal_conflict',
                default => null,
            };

            // 異常只追加可讀原因；未知結果不清除建立待辦或原本的保留。
            if ($anomaly !== null) {
                DB::table('payment_anomalies')->insert([
                    'order_id' => $orderId,
                    'reason' => $anomaly,
                    'occurred_at' => $this->sqlTime($this->databaseNow()),
                ]);
            }
            if (!$processing) {
                return $this->detail($order);
            }
            if ($observed === null || $anomaly !== null) {
                $intents->update(['pending_reason' => $anomaly !== null ? 'invalid_response' : $pendingReason]);

                return $this->detail($order);
            }

            // 綁定正確才能確認遠端存在；只有終局會改變庫存與訂單狀態。
            $pending = $observed->status === 'pending';
            $intents->update(['create_pending' => false, 'pending_reason' => $pending ? 'awaiting_result' : null]);
            if (!$pending) {
                $now = $this->databaseNow();
                if ($observed->status === 'paid') {
                    $this->deductStock($order, $now);
                }
                $this->release(DB::table('orders')->where('id', $orderId), $observed->status, $now);
                $order->status = $observed->status;
            }

            return $this->detail($order);
        });
    }

    // 以開單快照綁定唯一付款；初始待辦和 processing 歷程與意圖必須一起提交。
    private function recordPaymentIntent(object $order, CarbonImmutable $now): void
    {
        DB::table('payment_intents')->insert([
            'order_id' => $order->id,
            'payment_id' => (string) Str::uuid(),
            'amount' => $order->total,
            'currency' => 'TWD',
            'create_pending' => true,
            'pending_reason' => 'creation_unconfirmed',
        ]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'processing']);
        DB::table('order_history')->insert([
            'order_id' => $order->id, 'status' => 'processing', 'occurred_at' => $this->sqlTime($now),
        ]);
        $order->status = 'processing';
    }

    // 先授權並查得不變的門市歸屬，讓寫入端可在訂單鎖之前取得門市鎖。
    private function authorizedStoreId(User $actor, int $orderId): int
    {
        $storeId = $actor
            ->restrictStores(DB::table('orders'), 'store_id')
            ->where('id', $orderId)
            ->value('store_id');
        if ($storeId === null) {
            abort(404);
        }

        return (int) $storeId;
    }

    // 排程僅找出目前有到期候選單的門市，各店獨立鎖定及提交。
    public function expireDue(): void
    {
        $stores = DB::table('orders')
            ->where('status', 'awaiting_payment')
            ->where('expires_at', '<=', DB::raw('clock_timestamp()'))
            ->distinct()
            ->orderBy('store_id')
            ->pluck('store_id');

        // 候選清單可能過時，取得門市鎖之後重新用資料庫牆鐘判斷。
        foreach ($stores as $storeId) {
            // 每間門市使用自己的交易，已完成的釋放可在下次執行安全重試。
            DB::transaction(function () use ($storeId): void {
                $this->lockStore((int) $storeId);
                $this->expireDueOrders((int) $storeId, $this->databaseNow());
            });
        }
    }

    // 呼叫端已持有門市鎖，再鎖訂單以等候詳情讀取並維持共同鎖序。
    private function lockOrder(int $orderId): object
    {
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->lock('FOR NO KEY UPDATE')
            ->first();
        if ($order === null) {
            abort(404);
        }

        return $order;
    }

    // 取消、重送及付款共用單筆到期流程；期限前只有取消請求可釋放。
    private function settleAwaitingOrder(
        object $order,
        bool $cancelBeforeDeadline,
        ?CarbonImmutable $now = null,
    ): void {
        if ($order->status !== 'awaiting_payment') {
            return;
        }

        // 等到門市及訂單鎖都取得後，才比較資料庫時間與訂單期限。
        $now ??= $this->databaseNow();
        $expired = CarbonImmutable::parse($order->expires_at) <= $now;
        if (!$expired && !$cancelBeforeDeadline) {
            return;
        }

        // 只釋放這一張訂單，交易成功的轉移狀態也用於回應的詳情。
        $status = $expired ? 'expired' : 'cancelled';
        $pending = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', 'awaiting_payment');
        $this->release($pending, $status, $now);
        $order->status = $status;
    }

    // 呼叫端持有門市或訂單鎖，依穩定順序載入快照與歷程。
    private function detail(object $row): OrderDetailData
    {
        $items = DB::table('order_items')
            ->where('order_id', $row->id)
            ->orderBy('product_id')
            ->get(['product_id', 'name', 'quantity', 'unit_price', 'subtotal']);
        $history = DB::table('order_history')
            ->where('order_id', $row->id)
            ->orderBy('id')
            ->get(['status', 'occurred_at']);
        $ledger = DB::table('stock_ledger')
            ->where('order_id', $row->id)
            ->orderBy('id')
            ->get(['product_id', 'quantity', 'type', 'occurred_at']);

        // 付款狀態以訂單為準，與同一交易提交的異常、歷程及庫存一致。
        $intent = DB::table('payment_intents')->where('order_id', $row->id)->first();
        $payment = $intent === null ? null : new OrderPaymentData(
            $intent->payment_id,
            $row->status === 'processing' ? 'pending' : $row->status,
            $intent->pending_reason,
        );
        $anomalies = DB::table('payment_anomalies')
            ->where('order_id', $row->id)
            ->orderBy('id')
            ->get(['reason', 'occurred_at'])
            // 對外隱藏原始金流內容，只回傳異常原因及時間。
            ->map(PaymentAnomalyData::fromRow(...))
            ->all();

        return OrderDetailData::fromRows($row, $items, $history, $ledger, $payment, $anomalies);
    }

    // 在門市鎖下以快照一次扣除所有品項，任一庫存缺列或不足都回滾整筆終局。
    private function deductStock(object $order, CarbonImmutable $now): void
    {
        // 只有實際更新的庫存列能產生扣除歷程；訂單的保留稍後隨終局一起釋放。
        $deducted = DB::affectingStatement(
            "WITH deducted AS (
                UPDATE store_inventory AS stock SET on_hand = stock.on_hand - items.quantity
                FROM order_items AS items
                WHERE items.order_id = ? AND stock.store_id = ? AND stock.product_id = items.product_id
                RETURNING items.order_id, items.product_id, items.quantity
            )
            INSERT INTO stock_ledger (order_id, product_id, quantity, type, occurred_at)
            SELECT order_id, product_id, quantity, 'deducted', ? FROM deducted ORDER BY product_id",
            [$order->id, $order->store_id, $this->sqlTime($now)],
        );
        $items = DB::table('order_items')->where('order_id', $order->id)->count();
        if ($deducted !== $items) {
            throw new LogicException('Payment stock deduction did not cover every order item.');
        }
    }

    // 保留量以訂單當前狀態為唯一依據，避免另存累計值後不同步。
    private function availableInventory(int $storeId): Builder
    {
        $reserved = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.store_id', $storeId)
            ->whereIn('orders.status', ['awaiting_payment', 'processing'])
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) AS quantity');

        // 尚無保留的商品使用零，讓實體庫存等於可售庫存。
        return DB::table('store_inventory')
            ->leftJoinSub($reserved, 'reserved', 'reserved.product_id', '=', 'store_inventory.product_id')
            ->where('store_inventory.store_id', $storeId)
            ->selectRaw('store_inventory.on_hand - COALESCE(reserved.quantity, 0) AS available');
    }

    // 先計算整筆快照與金額，兩種運算都在實際相乘或相加前檢查上限。
    private function priceItems(array $quantities): array
    {
        $products = DB::table('products')
            ->whereIn('id', array_keys($quantities))
            ->get(['id', 'name', 'price'])
            ->keyBy('id');
        if ($products->count() !== count($quantities)) {
            abort(422);
        }

        // 依已驗證的商品排序產生明細，讓保留紀錄也有穩定順序。
        $items = [];
        $total = 0;
        foreach ($quantities as $productId => $quantity) {
            $product = $products[$productId];
            $price = (int) $product->price;
            if ($quantity > intdiv(OrderDraft::MAX_SAFE_INTEGER, $price)) {
                abort(422);
            }
            $subtotal = $price * $quantity;
            if ($subtotal > OrderDraft::MAX_SAFE_INTEGER - $total) {
                abort(422);
            }
            $total += $subtotal;
            $items[] = [
                'product_id' => $productId,
                'name' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $price,
                'subtotal' => $subtotal,
            ];
        }

        return [$items, $total];
    }

    // 同一交易中批次寫入各品項，確保訂單成立時已有完整的保留證據。
    private function recordReservation(int $orderId, array $items, string $createdAt): void
    {
        // 共用同一訂單識別與時間，讓快照、狀態及異動可逐筆對應。
        $snapshots = [];
        $entries = [];
        foreach ($items as $item) {
            $snapshots[] = ['order_id' => $orderId] + $item;
            $entries[] = [
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'type' => 'reserved',
                'occurred_at' => $createdAt,
            ];
        }
        DB::table('order_items')->insert($snapshots);
        DB::table('order_history')->insert([
            'order_id' => $orderId, 'status' => 'awaiting_payment', 'occurred_at' => $createdAt,
        ]);
        DB::table('stock_ledger')->insert($entries);
    }

    // 共用門市的寫入鎖，讓庫存檢查、狀態改變與其紀錄不會互相交錯。
    private function lockStore(int $storeId): void
    {
        // 不修改門市主鍵，避免阻塞只需取得外鍵參照鎖的操作。
        $store = DB::table('stores')
            ->where('id', $storeId)
            ->lock('FOR NO KEY UPDATE')
            ->first(['id']);
        if ($store === null) {
            abort(404);
        }
    }

    // 以取得必要鎖定後的資料庫牆鐘判斷到期，包含恰好到期的訂單。
    private function databaseNow(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) DB::scalar('SELECT clock_timestamp()'))->utc();
    }

    // 顯式保留微秒及時區，避免查詢綁定將 DateTime 截成整秒。
    private function sqlTime(CarbonImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }

    // 只釋放已到期且尚未開始付款的訂單，處理中的保留仍有效。
    private function expireDueOrders(int $storeId, CarbonImmutable $now): void
    {
        $due = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('status', 'awaiting_payment')
            ->where('expires_at', '<=', $this->sqlTime($now));
        $this->release($due, 'expired', $now);
    }

    // 依本次實際完成轉移的訂單批次寫入狀態及每一品項的釋放紀錄。
    private function release(Builder $orders, string $status, CarbonImmutable $now): void
    {
        // 共用更新傳回的識別，避免重複釋放或依賴逐筆額外查詢。
        $ids = $orders->select('id')->toSql();
        $occurredAt = $this->sqlTime($now);
        DB::statement(
            "WITH released AS (
                UPDATE orders SET status = ? WHERE id IN ($ids) RETURNING id
            ), history AS (
                INSERT INTO order_history (order_id, status, occurred_at)
                SELECT id, ?, ? FROM released
            )
            INSERT INTO stock_ledger (order_id, product_id, quantity, type, occurred_at)
            SELECT items.order_id, items.product_id, items.quantity, 'released', ?
            FROM order_items AS items JOIN released ON released.id = items.order_id
            ORDER BY items.order_id, items.product_id",
            [$status, ...$orders->getBindings(), $status, $occurredAt, $occurredAt],
        );
    }
}
