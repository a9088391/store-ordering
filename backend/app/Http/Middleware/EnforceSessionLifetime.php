<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSessionLifetime
{
    // 在授權前拒絕達到期限的登入，並只為仍有效的登入更新閒置起算時間。
    public function handle(Request $request, Closure $next): Response
    {
        // 匿名會話供登入取得 CSRF token 使用，登入期限只作用於已驗證的使用者。
        if ($request->user() === null) {
            return $next($request);
        }

        // 兩個期限都比較同一個請求當下時間，避免跨秒造成不一致。
        $session = $request->session();
        $now = now()->getTimestamp();
        $authenticatedAt = $session->get('authenticated_at');
        $lastActivityAt = $session->get('last_activity_at');

        // 資料庫 session 原生閒置判斷為嚴格小於，這裡補上等於邊界及固定登入期限。
        if (
            !is_int($authenticatedAt)
            || !is_int($lastActivityAt)
            || $now - $authenticatedAt >= 28800
            || $now - $lastActivityAt >= 1800
        ) {
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();
        } else {
            $session->put('last_activity_at', $now);
        }

        return $next($request);
    }
}
