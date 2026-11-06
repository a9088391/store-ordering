<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PaymentDecision;
use App\Http\Requests\PaymentDispatchMode;
use App\Http\Responses\MockPaymentPageData;
use App\Http\Responses\PageData;
use App\Payments\MockPaymentClient;
use App\Payments\PaymentData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class HeadquartersPaymentController
{
    // 面板只經由 HTTP 操作模擬金流，避免訂單應用接觸另一服務的資料庫。
    public function __construct(private MockPaymentClient $mock)
    {
    }

    // 只有總部能轉送清單請求，無法核對的遠端回應明確顯示為服務錯誤。
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'headquarters', 403);
        $page = PageData::fromQuery($request->query());
        $response = $this->mock->list($page);

        // 維持既有分頁欄位，逐筆驗證遠端資料。
        $result = $response?->successful() ? MockPaymentPageData::fromResponse($response, $page) : null;
        abort_if($result === null, 503);

        return response()->json($result);
    }

    // 總部可決定結果；遠端既有相反終局、查無識別及無法核對的回應分開回報。
    public function decide(Request $request, string $paymentId): JsonResponse
    {
        abort_unless($request->user()->role === 'headquarters', 403);
        $status = PaymentDecision::fromRequest($request)->value;
        $paymentId = strtolower($paymentId);
        $response = $this->mock->decide($paymentId, $status);

        // 衝突或找不到沿用固定錯誤格式；其他失敗不能宣稱遠端已保存決定。
        abort_if($response?->notFound(), 404);
        abort_if($response?->conflict(), 409);
        $result = $response?->successful() ? PaymentData::fromResult($response->json('data')) : null;
        if (
            $result === null
            || $result->paymentId !== $paymentId
            || $result->currency !== 'TWD'
            || $result->status !== $status
        ) {
            abort(503);
        }

        return response()->json(['data' => $result]);
    }

    // 派送維持總部權限及 CSRF，只有可核對的遠端 202 代表已排入待辦。
    public function dispatch(Request $request, string $paymentId): JsonResponse
    {
        abort_unless($request->user()->role === 'headquarters', 403);
        $mode = PaymentDispatchMode::fromRequest($request)->value;
        $response = $this->mock->dispatch(strtolower($paymentId), $mode);

        // 未知、狀態衝突和其他服務錯誤由前端分別處理。
        abort_if($response?->notFound(), 404);
        abort_if($response?->conflict(), 409);
        abort_unless($response?->status() === 202, 503);
        $data = $response->json('data');
        $eventId = $data['eventId'] ?? null;
        if (
            !is_string($eventId)
            || !Str::isUuid($eventId)
            || ($data['mode'] ?? null) !== $mode
        ) {
            abort(503);
        }

        return response()->json(['data' => ['eventId' => $eventId, 'mode' => $mode]], 202);
    }
}
