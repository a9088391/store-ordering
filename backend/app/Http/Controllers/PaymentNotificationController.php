<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Payments\PaymentData;
use App\Payments\PaymentNotifications;
use App\Payments\PaymentNotificationSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PaymentNotificationController
{
    // 先驗證來源及原始本文，再解析機器通知，入口不讀取瀏覽器會話。
    public function __invoke(Request $request, PaymentNotifications $notifications): JsonResponse
    {
        $body = $request->getContent();
        abort_unless(PaymentNotificationSignature::accepts(
            (string) $request->header('X-Payment-Timestamp'),
            $body,
            (string) $request->header('X-Payment-Signature'),
            (string) config('services.payment_notifications.key'),
        ), 403);

        // 解碼確認 JSON 語法後，再從原始本文核對頂層鍵，避免重複鍵被解碼覆蓋。
        $payload = $request->isJson() ? json_decode($body, true) : null;
        abort_unless(is_array($payload), 422);
        $fieldCounts = $this->topLevelFieldCounts($body);
        $eventId = $payload['eventId'] ?? null;
        abort_unless(($fieldCounts['eventId'] ?? 0) === 1, 422);
        abort_unless(is_string($eventId) && Str::isUuid($eventId), 422);
        $eventId = strtolower($eventId);

        // UUID 依資料庫格式比對，去重仍保留原始本文的大小寫差異。
        if (is_string($payload['paymentId'] ?? null)) {
            $payload['paymentId'] = strtolower($payload['paymentId']);
        }

        // 識別明確時仍交由收件層比對原文，其他重複欄位只會讓付款資料無效。
        $payment = null;
        if (
            count($payload) === 6
            && array_sum($fieldCounts) === count($fieldCounts)
            && in_array($payload['status'] ?? null, ['paid', 'failed'], true)
        ) {
            $payment = PaymentData::fromResult($payload);
        }

        // 等待收件交易提交後才回傳結果，避免 HTTP 例外回滾應保存的異常。
        $status = $notifications->receive($eventId, $body, $payment);
        abort_if($status !== 200, $status);

        return response()->json(['data' => ['eventId' => $eventId]]);
    }

    // 對語法已驗證的 JSON 計數，完整讀取字串以忽略其中的括號、冒號及跳脫引號。
    private function topLevelFieldCounts(string $body): array
    {
        $pattern = <<<'REGEX'
            ~("(?:[^"\\]*+(?:\\.[^"\\]*+)*+)")(?:[ \t\r\n]*(:))?|[{}\[\]]~
            REGEX;
        abort_if(preg_match_all($pattern, $body, $tokens, PREG_SET_ORDER) === false, 422);

        // 只計算最外層物件的鍵，並用解碼後的名稱辨識同一欄位的跳脫寫法。
        $depth = 0;
        $counts = [];
        foreach ($tokens as $token) {
            if ($token[0] === '{' || $token[0] === '[') {
                $depth++;
            } elseif ($token[0] === '}' || $token[0] === ']') {
                $depth--;
            } elseif ($depth === 1 && isset($token[2])) {
                $field = json_decode($token[1], flags: JSON_THROW_ON_ERROR);
                $counts[$field] = ($counts[$field] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
