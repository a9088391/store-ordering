<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitRequestBody
{
    private const int MAX_BYTES = 65536;

    public const string ERROR_CODE = 'PAYLOAD_TOO_LARGE';
    public const string ERROR_MESSAGE = '請求本文超過 64 KiB。';

    // 在 Laravel 捕捉請求前檢查本文，以免 JSON 或表單先由框架解碼。
    public static function exceedsAtEntry(array $server): bool
    {
        // 先用 SAPI 提供的長度拒絕已知超限的請求，不必再讀取本文。
        $length = (string) ($server['CONTENT_LENGTH'] ?? '');
        $hasLength = ctype_digit($length);
        if ($hasLength && (int) $length > self::MAX_BYTES) {
            return true;
        }

        // PHP 原生 multipart 解析會消耗原始串流，只接受有可確認長度且無傳輸編碼的表單。
        $contentType = $server['CONTENT_TYPE'] ?? '';
        $mediaType = substr($contentType, 0, strcspn($contentType, ';, '));
        if (
            ($server['REQUEST_METHOD'] ?? '') === 'POST'
            && strcasecmp($mediaType, 'multipart/form-data') === 0
            && ini_get('enable_post_data_reading')
        ) {
            return !$hasLength || array_key_exists('HTTP_TRANSFER_ENCODING', $server);
        }

        // 其他本文最多讀取上限加一位元組，並讓框架從原始串流重新讀取通過的本文。
        $body = file_get_contents('php://input', length: self::MAX_BYTES + 1);

        return $body === false || strlen($body) > self::MAX_BYTES;
    }

    // 在進入路由前限制本文為 64 KiB，並核對實際本文以涵蓋未提供長度的請求。
    public function handle(Request $request, Closure $next): Response
    {
        if (
            (int) $request->server('CONTENT_LENGTH') > self::MAX_BYTES
            || strlen($request->getContent()) > self::MAX_BYTES
        ) {
            abort(413);
        }

        // 符合大小限制時才交給後續的 CSRF、授權及路由處理。
        return $next($request);
    }
}
