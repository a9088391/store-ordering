<?php

declare(strict_types=1);

namespace App\Http\Responses;

final readonly class StoreData
{
    // 明列門市選單所需欄位，避免查詢結果的其他欄位進入 API。
    public function __construct(public int $id, public string $name)
    {
    }
}
