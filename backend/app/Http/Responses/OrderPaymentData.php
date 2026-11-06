<?php

declare(strict_types=1);

namespace App\Http\Responses;

final readonly class OrderPaymentData
{
    // 詳情回傳本機已確認的狀態；待確認原因只對處理中訂單有意義。
    public function __construct(public string $id, public string $status, public ?string $pendingReason)
    {
    }
}
