<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PaymentDecision;
use App\Http\Responses\PageData;
use App\Payments\PaymentData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MockPaymentController
{
    // 以穩定排序呈現付款；只在獨立的模擬服務路由提供清單。
    public function index(Request $request): JsonResponse
    {
        $page = PageData::fromQuery($request->query());
        $payments = DB::table('payments')
            ->orderByDesc('created_at')
            ->orderBy('payment_id')
            ->paginate($page->perPage, page: $page->page);

        // 不公開資料庫實作欄位，與個別付款回傳相同的 DTO。
        return $page->response($payments, static fn (object $row): PaymentData => PaymentData::fromRow(
            $row,
            $row->status,
        ));
    }

    // 同一識別只建立一次；既有不可變欄位完全相同時回傳已保存的狀態。
    public function create(Request $request): JsonResponse
    {
        $payload = $request->isJson() ? json_decode($request->getContent(), true) : null;
        $payment = PaymentData::fromCreate($payload);
        abort_if($payment === null, 422);

        // 唯一約束協調同時建立；失去競爭的請求再讀取已提交的同一識別。
        $paymentId = strtolower($payment->paymentId);
        $created = DB::table('payments')->insertOrIgnore([
            'payment_id' => $paymentId,
            'order_id' => $payment->orderId,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => 'pending',
        ]);
        $stored = $this->payment($paymentId);
        if (
            $stored->orderId !== $payment->orderId
            || $stored->amount !== $payment->amount
            || $stored->currency !== $payment->currency
        ) {
            abort(409);
        }

        return response()->json(['data' => $stored], $created ? 201 : 200);
    }

    // 查詢只回傳既有付款，查無識別不會自動建立。
    public function show(string $paymentId): JsonResponse
    {
        return response()->json(['data' => $this->payment(strtolower($paymentId))]);
    }

    // PostgreSQL 只允許 pending 的列轉移；相反決定即使同時抵達也無法覆寫。
    public function decide(Request $request, string $paymentId): JsonResponse
    {
        $decision = PaymentDecision::fromRequest($request);
        $paymentId = strtolower($paymentId);
        DB::table('payments')
            ->where('payment_id', $paymentId)
            ->where('status', 'pending')
            ->update(['status' => $decision->value]);

        // 讀回最終決定，讓相同重試可直接取得已保存結果。
        $payment = $this->payment($paymentId);
        abort_if($payment->status !== $decision->value, 409);

        return response()->json(['data' => $payment]);
    }

    // 單筆操作共用相同的查無資源及 DTO 轉換。
    private function payment(string $paymentId): PaymentData
    {
        $row = DB::table('payments')->where('payment_id', $paymentId)->first();
        abort_if($row === null, 404);

        return PaymentData::fromRow($row, $row->status);
    }
}
