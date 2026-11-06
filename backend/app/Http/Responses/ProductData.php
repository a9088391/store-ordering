<?php

declare(strict_types=1);

namespace App\Http\Responses;

final readonly class ProductData
{
    // 保持共用商品目錄的公開欄位及 TWD 整數金額。
    public function __construct(public int $id, public string $name, public int $price)
    {
    }
}
