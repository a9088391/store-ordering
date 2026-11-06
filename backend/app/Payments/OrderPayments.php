<?php

declare(strict_types=1);

namespace App\Payments;

use App\Http\Responses\OrderDetailData;
use App\Models\User;
use App\Orders\OrderConflict;
use App\Orders\OrderReservation;

final readonly class OrderPayments
{
    // 付款交易與 HTTP 傳輸分開，通訊延遲不能持有訂單及庫存鎖。
    public function __construct(private OrderReservation $reservations, private MockPaymentClient $mock)
    {
    }

    // 發起與人工查詢都使用已提交的同一意圖，再由本機交易判斷是否可套用結果。
    public function sync(User $actor, int $orderId, bool $start): OrderDetailData|OrderConflict
    {
        $intent = $this->reservations->preparePayment($actor, $orderId, $start);
        if (!$intent instanceof PaymentData) {
            return $intent;
        }

        // 人工查詢先查原識別；待確認時查無紀錄，才以相同內容補送建立。
        $response = $start ? $this->mock->create($intent) : $this->mock->find($intent->paymentId);
        if (!$start && $response?->notFound() && $intent->status === 'pending') {
            $response = $this->mock->create($intent);
        }

        // 錯誤回應或無法驗證的本文都維持未知，409 也須記下異常。
        $observed = $response?->successful() ? PaymentData::fromResult($response->json('data')) : null;
        $reason = match (true) {
            $observed !== null => null,
            $response?->successful(), $response?->conflict() => 'invalid_response',
            $response?->notFound() => 'payment_not_found',
            default => 'service_unavailable',
        };

        return $this->reservations->applyPaymentObservation($actor, $orderId, $observed, $reason);
    }
}
