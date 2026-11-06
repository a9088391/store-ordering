<?php

declare(strict_types=1);

namespace App\Http\Responses;

final readonly class PaymentAnomalyData
{
    // 異常只公開固定原因及時間，不回傳不可信的外部本文。
    private function __construct(public string $reason, public string $occurredAt)
    {
    }

    // 與訂單歷程使用相同的 UTC 時間格式。
    public static function fromRow(object $row): self
    {
        return new self($row->reason, OrderData::isoDate($row->occurred_at));
    }
}
