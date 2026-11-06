<?php

declare(strict_types=1);

namespace App\Payments;

final class PaymentNotificationSignature
{
    // 發送與驗證共用原始時間標頭及本文，任何位元組差異都會改變簽章。
    public static function sign(string $timestamp, string $body, string $key): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $key);
    }

    // 保留正負五分鐘邊界；先限制十進位長度，以免不可信的時間值溢位。
    public static function accepts(string $timestamp, string $body, string $signature, string $key): bool
    {
        return $key !== ''
            && preg_match('/\A[0-9]{1,12}\z/', $timestamp) === 1
            && abs(now()->getTimestamp() - (int) $timestamp) <= 300
            && hash_equals(self::sign($timestamp, $body, $key), $signature);
    }
}
