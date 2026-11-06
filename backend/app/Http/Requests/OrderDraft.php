<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Http\Request;
use stdClass;

final readonly class OrderDraft
{
    public const int MAX_SAFE_INTEGER = 9007199254740991;

    /** @param array<int, int> $items 依商品識別排序的訂購數量，用於比對操作鍵重送。 */
    private function __construct(public int $storeId, public string $operationKey, public array $items)
    {
    }

    // 依原始 JSON 辨識整數、清單及操作鍵，避免框架的輸入轉型或空白正規化。
    public static function fromRequest(Request $request): self
    {
        $body = $request->isJson() ? json_decode($request->getContent()) : null;

        // 明列可接受的欄位；客戶端傳入金額或價格一律拒絕。
        if (
            !$body instanceof stdClass
            || count(get_object_vars($body)) !== 3
            || !self::isPositiveSafeInteger($body->storeId ?? null)
            || !is_string($body->operationKey ?? null)
            || $body->operationKey === ''
            || !is_array($body->items ?? null)
            || count($body->items) < 1
            || count($body->items) > 100
        ) {
            abort(422);
        }

        // 以商品識別為鍵，在產生可比較的內容前拒絕重複明細。
        $items = [];
        foreach ($body->items as $item) {
            if (
                !$item instanceof stdClass
                || count(get_object_vars($item)) !== 2
                || !self::isPositiveSafeInteger($item->productId ?? null)
                || !self::isPositiveSafeInteger($item->quantity ?? null)
                || isset($items[$item->productId])
            ) {
                abort(422);
            }
            $items[$item->productId] = $item->quantity;
        }
        ksort($items, SORT_NUMERIC);

        return new self($body->storeId, $body->operationKey, $items);
    }

    // 只接受能無損傳給 JavaScript 的 JSON 正整數。
    private static function isPositiveSafeInteger(mixed $value): bool
    {
        return is_int($value)
            && $value >= 1
            && $value <= self::MAX_SAFE_INTEGER;
    }
}
