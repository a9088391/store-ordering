<?php

declare(strict_types=1);

namespace App\Payments;

use App\Orders\OrderReservation;
use Illuminate\Support\Facades\DB;

final readonly class PaymentNotifications
{
    private const string SOURCE = 'mock-payment';

    // 收件負責去重及稽核，訂單交易規則由原本的保留服務負責。
    public function __construct(private OrderReservation $reservations)
    {
    }

    // 同一交易領取收件識別、套用付款及保存結果；提交前中斷可重試整個操作。
    public function receive(string $eventId, string $body, ?PaymentData $payment): int
    {
        $identity = ['source' => self::SOURCE, 'event_id' => $eventId];
        $hash = hash('sha256', $body);

        // 先領取事件鍵且不參照訂單，避免在既有的門市鎖之前持有訂單外鍵鎖。
        return DB::transaction(function () use ($identity, $eventId, $hash, $payment): int {
            $receipts = DB::table('payment_notification_receipts');
            $claimed = $receipts->insertOrIgnore($identity + ['body_hash' => $hash, 'status' => 422]);
            $receipt = $receipts->where($identity);
            if ($claimed === 0) {
                // 衝突插入會等候首筆交易完成，因此讀到的雜湊與結果已一起提交。
                $stored = $receipt->firstOrFail();
                if ($stored->body_hash === $hash) {
                    return (int) $stored->status;
                }

                // 用首次收件的可信綁定記錄衝突，完全不使用新本文指向的訂單。
                $this->recordAnomaly($stored->order_id, $eventId, 'event_content_conflict', $hash);

                return 409;
            }
            if ($payment === null) {
                return 422;
            }

            // 付款綁定及終局錯誤仍提交異常，HTTP 錯誤在交易結束後回傳。
            [$orderId, $anomaly] = $this->reservations->applyPaymentNotification($payment);
            if ($anomaly !== null) {
                $this->recordAnomaly($orderId, $eventId, $anomaly);
            }
            $status = $anomaly === null ? 200 : 409;
            $receipt->update(['status' => $status, 'order_id' => $orderId]);

            return $status;
        });
    }

    // 保存可讀原因及來源事件，僅對有可信綁定的訂單顯示異常。
    private function recordAnomaly(
        ?int $orderId,
        string $eventId,
        string $reason,
        ?string $conflictingHash = null,
    ): void {
        DB::table('payment_anomalies')->insertOrIgnore([
            'order_id' => $orderId,
            'reason' => $reason,
            'occurred_at' => DB::raw('clock_timestamp()'),
            'notification_event_id' => $eventId,
            'conflicting_body_hash' => $conflictingHash,
        ]);
    }
}
