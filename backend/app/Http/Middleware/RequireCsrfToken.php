<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

final class RequireCsrfToken extends PreventRequestForgery
{
    // 前端從 JSON 取得 token，避免另設會被同一主機不同埠共用的 XSRF cookie。
    protected $addHttpCookie = false;

    // 關閉來源捷徑，讓同來源的瀏覽器寫入也接受 Laravel 原生的 CSRF token 驗證。
    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}
