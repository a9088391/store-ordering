<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class LoginAttemptLimiter
{
    // 在共享的 PostgreSQL 交易中原子保留一次登入額度，或回傳可重試所需秒數。
    public function claim(string $source, ?string $email): int
    {
        // 來源一律計數，具有可正規化帳號的請求也會與該帳號的其他來源共用額度。
        $keys = [1 => 'login:source:' . hash('sha256', $source)];
        if ($email !== null && $email !== '') {
            $keys[2] = 'login:account:' . hash('sha256', $email);
        }

        // 各請求依固定命名空間順序加鎖，防止兩個額度的檢查及扣計被其他請求插入。
        return DB::transaction(function () use ($keys): int {
            foreach ($keys as $namespace => $key) {
                DB::select('SELECT pg_advisory_xact_lock(?, hashtext(?))', [$namespace, $key]);
            }

            // 任一額度已用盡時先拒絕，兩者同時受限則以較晚恢復的時間為準。
            $retryAfter = 0;
            foreach ($keys as $key) {
                if (RateLimiter::tooManyAttempts($key, 5)) {
                    $retryAfter = max($retryAfter, 1, RateLimiter::availableIn($key));
                }
            }
            if ($retryAfter > 0) {
                return $retryAfter;
            }

            // 在驗證密碼前扣計，成功的登入同樣佔用額度。
            foreach ($keys as $key) {
                RateLimiter::hit($key, 60);
            }

            return 0;
        });
    }
}
