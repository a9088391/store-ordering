<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Payments\PaymentData;
use Illuminate\Http\Client\Response;

final readonly class MockPaymentPageData
{
    // 面板分頁沿用本機分頁格式，項目只公開固定的金流欄位。
    private function __construct(public array $data, public array $meta)
    {
    }

    // 外部清單和分頁數字必須符合已驗證的請求，避免將錯誤內容顯示成空清單。
    public static function fromResponse(Response $response, PageData $page): ?self
    {
        $data = $response->json('data');
        $meta = $response->json('meta');
        $total = $meta['total'] ?? null;
        if (
            !is_array($data)
            || !array_is_list($data)
            || count($data) > $page->perPage
            || ($meta['page'] ?? null) !== $page->page
            || ($meta['perPage'] ?? null) !== $page->perPage
            || !is_int($total)
            || $total < count($data)
        ) {
            return null;
        }

        // 每筆結果沿用金流 DTO 驗證欄位及型別。
        $items = [];
        foreach ($data as $payload) {
            $item = PaymentData::fromResult($payload);
            if ($item === null || $item->currency !== 'TWD') {
                return null;
            }
            $items[] = $item;
        }

        return new self($items, ['page' => $page->page, 'perPage' => $page->perPage, 'total' => $total]);
    }
}
