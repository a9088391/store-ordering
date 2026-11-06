<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Http\Request;

enum PaymentDispatchMode: string
{
    case Immediate = 'immediate';
    case Delayed = 'delayed';

    // 瀏覽器與模擬金流共用固定模式；以原始 JSON 拒絕轉型、修剪和額外欄位。
    public static function fromRequest(Request $request): self
    {
        $body = $request->getContent();
        $payload = $request->isJson() ? json_decode($body, true) : null;
        $mode = $payload['mode'] ?? null;

        // 唯一合法欄位與模式都不含冒號，多出的分隔符表示 JSON 出現額外或重複欄位。
        if (
            !is_string($mode)
            || count($payload) !== 1
            || self::tryFrom($mode) === null
            || substr_count($body, ':') !== 1
        ) {
            abort(422);
        }

        return self::from($mode);
    }
}
