<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\LoginAttemptLimiter;
use App\Http\Responses\SessionData;
use App\Http\Responses\SessionUserData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class SessionController
{
    // 回傳 Vue 初始化與登入、登出後共用的身分及 CSRF 回應格式。
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $sessionUser = $user === null
            ? null
            : new SessionUserData($user->id, $user->name, $user->role, $user->store_id);

        return response()->json(
            new SessionData($sessionUser, $request->session()->token()),
            headers: ['Cache-Control' => 'no-store'],
        );
    }

    // 先依登入來源與帳號扣計額度，再交由 Laravel 驗證密碼及輪替登入會話。
    public function login(Request $request, LoginAttemptLimiter $limiter): JsonResponse
    {
        // 僅由本文讀取憑證，避免接受可能出現在 URL 紀錄中的密碼。
        $input = $request->request->all();
        $email = $input['email'] ?? null;
        if (is_string($email)) {
            $input['email'] = $email = Str::lower(trim($email));
        }

        // 使用伺服器提供的連線來源，避免自行送入的代理標頭改變額度。
        $retryAfter = $limiter->claim((string) $request->server('REMOTE_ADDR'), is_string($email) ? $email : null);
        if ($retryAfter > 0) {
            abort(429, headers: ['Retry-After' => (string) $retryAfter]);
        }

        // 限制 bcrypt 接收的密碼為最多 72 位元組，避免尾端內容在驗證時被截斷。
        $credentials = Validator::make($input, [
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            'password' => ['required', 'string', 'regex:/\A[^\x00]{1,72}\z/'],
        ])->validate();

        // 原生 guard 對失敗驗證套用時間補償，並為成功登入輪替 session ID 與 token。
        if (!Auth::attempt($credentials)) {
            abort(401);
        }

        // 登入時間固定供絕對期限使用，活動時間由後續驗證成功的請求更新。
        $now = now()->getTimestamp();
        $request->session()->put(['authenticated_at' => $now, 'last_activity_at' => $now]);

        return $this->show($request);
    }

    // 撤銷舊 session 並產生新的匿名會話，供登入頁立即使用新的 token。
    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $session = $request->session();
        $session->invalidate();
        $session->regenerateToken();

        return $this->show($request);
    }
}
