<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PaymentDecision;
use App\Http\Requests\PaymentDispatchMode;
use App\Http\Responses\PageData;
use App\Payments\MockPaymentNotifications;
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
        $row = $this->paymentRow($paymentId);
        $stored = PaymentData::fromRow($row, $row->status);
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
        $row = $this->paymentRow(strtolower($paymentId));

        return response()->json(['data' => PaymentData::fromRow($row, $row->status)]);
    }

    // 決定與不可變事件一起提交，使用同一付款列鎖協調派送及相反終局。
    public function decide(Request $request, string $paymentId, MockPaymentNotifications $notifications): JsonResponse
    {
        $decision = PaymentDecision::fromRequest($request);
        $paymentId = strtolower($paymentId);

        // 相同結果的重試取得原事件，早期版本的終局付款在此補建事件。
        $payment = DB::transaction(function () use ($paymentId, $decision, $notifications): PaymentData {
            $row = $this->paymentRow($paymentId, lock: true);
            if ($row->status === 'pending') {
                DB::table('payments')->where('payment_id', $paymentId)->update(['status' => $decision->value]);
            } else {
                abort_if($row->status !== $decision->value, 409);
            }

            // 事件建立失敗時回滾新決定；此操作本身不排入派送。
            $payment = PaymentData::fromRow($row, $decision->value);
            $notifications->eventId($payment);

            return $payment;
        });

        return response()->json(['data' => $payment]);
    }

    // 派送不改變結果，舊付款事件補建與新增待辦在同一交易完成。
    public function dispatch(Request $request, string $paymentId, MockPaymentNotifications $notifications): JsonResponse
    {
        $mode = PaymentDispatchMode::fromRequest($request);
        $paymentId = strtolower($paymentId);

        // 只在終局排入；重送即新增一筆指向同一事件的待辦。
        $eventId = DB::transaction(function () use ($paymentId, $mode, $notifications): string {
            $row = $this->paymentRow($paymentId, lock: true);
            abort_if($row->status === 'pending', 409);
            $eventId = $notifications->eventId(PaymentData::fromRow($row, $row->status));
            $notifications->enqueue($eventId, delayed: $mode === PaymentDispatchMode::Delayed);

            return $eventId;
        });

        return response()->json(['data' => ['eventId' => $eventId, 'mode' => $mode->value]], 202);
    }

    // 讀取及寫入共用相同的查無資源行為；寫入須先鎖定付款。
    private function paymentRow(string $paymentId, bool $lock = false): object
    {
        $payments = DB::table('payments')->where('payment_id', $paymentId);
        $row = ($lock ? $payments->lock('FOR NO KEY UPDATE') : $payments)->first();
        abort_if($row === null, 404);

        return $row;
    }
}
