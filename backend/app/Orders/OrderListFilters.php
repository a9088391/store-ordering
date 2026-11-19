<?php

declare(strict_types=1);

namespace App\Orders;

final readonly class OrderListFilters
{
    // 僅承載 Controller 已驗證並轉型的清單條件。
    public function __construct(
        public ?int $storeId,
        public ?int $orderNumber,
        public ?string $status,
        public ?string $from,
        public ?string $to,
    ) {
    }
}
