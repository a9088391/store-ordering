<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

final readonly class PageData
{
    // 建立通過驗證的頁碼及每頁筆數，讓查詢與回應使用相同參數。
    private function __construct(public int $page, public int $perPage)
    {
    }

    // 延用商品、庫存及訂單共用的分頁預設值與 JSON 整數上限。
    public static function fromQuery(array $query): self
    {
        $pagination = Validator::make($query, [
            'page' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        return new self((int) ($pagination['page'] ?? 1), (int) ($pagination['perPage'] ?? 50));
    }

    // 只映射目前頁面的資料，並將總筆數一併回傳供介面翻頁。
    public function response(LengthAwarePaginator $items, Closure $toItem): JsonResponse
    {
        return response()->json([
            'data' => array_map($toItem, $items->items()),
            'meta' => ['page' => $this->page, 'perPage' => $this->perPage, 'total' => $items->total()],
        ]);
    }
}
