<?php

declare(strict_types=1);

namespace App\Http\Responses;

final readonly class InventoryItemData
{
    // 以整數回傳商品金額、實體庫存及可售庫存，維持既有門市庫存格式。
    public function __construct(
        public int $id,
        public string $name,
        public int $price,
        public int $onHand,
        public int $available,
    ) {
    }
}
