<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Http\Request;

enum PaymentDecision: string
{
    case Paid = 'paid';
    case Failed = 'failed';

    // 以原始 JSON 驗證終局決定，避免框架修剪空白而接受非契約值。
    public static function fromRequest(Request $request): self
    {
        $payload = $request->isJson() ? json_decode($request->getContent(), true) : null;
        $decision = $payload['status'] ?? null;
        if (!is_string($decision) || self::tryFrom($decision) === null) {
            abort(422);
        }

        return self::from($decision);
    }
}
