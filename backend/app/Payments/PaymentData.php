<?php

declare(strict_types=1);

namespace App\Payments;

use App\Http\Requests\OrderDraft;
use Illuminate\Support\Str;

final readonly class PaymentData
{
    // 訂單服務與模擬金流共用固定欄位；建立請求僅攜帶不可變的交易綁定。
    private function __construct(
        public string $paymentId,
        public int $orderId,
        public int $amount,
        public string $currency,
        public string $status,
    ) {
    }

    // 服務自己的資料列已受約束，轉為 JSON 整數並以呼叫端的狀態為準。
    public static function fromRow(object $row, string $status): self
    {
        return new self($row->payment_id, (int) $row->order_id, (int) $row->amount, $row->currency, $status);
    }

    // 建立只接受四個綁定欄位，避免呼叫者在建立時決定結果。
    public static function fromCreate(mixed $payload): ?self
    {
        if (!is_array($payload) || count($payload) !== 4 || ($payload['currency'] ?? null) !== 'TWD') {
            return null;
        }

        return self::fromBinding($payload, 'pending');
    }

    // 回應的狀態必須是已知值；幣別由呼叫端與本機交易綁定比較。
    public static function fromResult(mixed $payload): ?self
    {
        if (!is_array($payload) || !in_array($payload['status'] ?? null, ['pending', 'paid', 'failed'], true)) {
            return null;
        }

        return self::fromBinding($payload, $payload['status']);
    }

    // 重試直接使用同一組持久化欄位，不重新計價或產生識別。
    public function createPayload(): array
    {
        return [
            'paymentId' => $this->paymentId,
            'orderId' => $this->orderId,
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }

    // 外部資料維持 JSON 型別語意，拒絕整數字串、浮點及超出安全範圍的值。
    private static function fromBinding(array $payload, string $status): ?self
    {
        $id = $payload['paymentId'] ?? null;
        $orderId = $payload['orderId'] ?? null;
        $amount = $payload['amount'] ?? null;
        $currency = $payload['currency'] ?? null;

        if (
            !is_string($id)
            || !Str::isUuid($id)
            || !self::isSafeInteger($orderId)
            || !self::isSafeInteger($amount)
            || !is_string($currency)
        ) {
            return null;
        }

        return new self($id, $orderId, $amount, $currency, $status);
    }

    // 識別及金額都遵守本案 JSON 正整數上限。
    private static function isSafeInteger(mixed $value): bool
    {
        return is_int($value)
            && $value >= 1
            && $value <= OrderDraft::MAX_SAFE_INTEGER;
    }
}
