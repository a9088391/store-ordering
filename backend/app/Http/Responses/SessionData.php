<?php

declare(strict_types=1);

namespace App\Http\Responses;

final readonly class SessionData
{
    // 登入前後共用相同會話格式，匿名時使用者為 null 且仍提供 CSRF token。
    public function __construct(public ?SessionUserData $user, public string $csrfToken)
    {
    }
}
