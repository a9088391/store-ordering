<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OrderDraft;
use App\Http\Responses\OrderData;
use App\Http\Responses\PageData;
use App\Orders\OrderConflict;
use App\Orders\OrderReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class OrderController
{
    // 依賴共用的庫存交易入口，讓開單、取消和庫存讀取採用相同鎖定。
    public function __construct(private readonly OrderReservation $reservations)
    {
    }

    // 先驗證篩選及門市權限，再依建立時間及訂單識別取得穩定頁面。
    public function index(Request $request): JsonResponse
    {
        // 查詢參數是字串，識別限定為十進位正整數，日期限定為有效的年月日。
        $query = $request->query();
        $page = PageData::fromQuery($query);
        $idRules = ['sometimes', 'bail', 'regex:/\A[1-9][0-9]*\z/', 'integer', 'max:9007199254740991'];
        $dateRules = ['sometimes', 'bail', 'regex:/\A(?!0000)[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', 'date_format:Y-m-d'];
        $filters = Validator::make($query, [
            'storeId' => $idRules,
            'orderNumber' => $idRules,
            'status' => ['sometimes', 'in:awaiting_payment,processing,paid,failed,cancelled,expired'],
            'from' => $dateRules,
            'to' => $dateRules,
        ])->validate();

        // 店員省略門市也只會看到自己的門市；明確越權的門市與不存在同樣回 404。
        $actor = $request->user();
        $orders = $actor->restrictStores(DB::table('orders'), 'store_id');
        if (isset($filters['storeId'])) {
            $storeId = (int) $filters['storeId'];
            if (!$actor->canAccessStore($storeId)) {
                abort(404);
            }
            $orders->where('store_id', $storeId);
        }
        if (isset($filters['orderNumber'])) {
            $orders->where('id', (int) $filters['orderNumber']);
        }
        if (isset($filters['status'])) {
            $orders->where('status', $filters['status']);
        }

        // 以台北時區的日界線比對 UTC 建立時間，起日包含、迄日不包含。
        if (isset($filters['from'])) {
            $orders->whereRaw(
                "created_at >= CAST(? AS date)::timestamp AT TIME ZONE 'Asia/Taipei'",
                [$filters['from']],
            );
        }
        if (isset($filters['to'])) {
            $orders->whereRaw("created_at < CAST(? AS date)::timestamp AT TIME ZONE 'Asia/Taipei'", [$filters['to']]);
        }

        // 同一時間的訂單以識別降冪固定先後，跨頁不因排序相同而改變順序。
        $orders = $orders
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($page->perPage, page: $page->page);

        return $page->response($orders, OrderData::fromRow(...));
    }

    // 先驗證輸入及門市範圍，再依是否重送回傳不同的成功狀態。
    public function create(Request $request): JsonResponse
    {
        $draft = OrderDraft::fromRequest($request);
        $actor = $request->user();
        if (!$actor->canAccessStore($draft->storeId)) {
            abort(404);
        }

        // 只有第一次成功保留回傳 201；重送直接回傳已存在的訂單。
        $result = $this->reservations->create($actor, $draft);
        if ($result instanceof OrderConflict) {
            return $this->conflict($result);
        }
        [$order, $created] = $result;

        return response()->json(['data' => $order], $created ? 201 : 200);
    }

    // 只在授權範圍內讀取訂單與歷程。
    public function show(Request $request, string $order): JsonResponse
    {
        $detail = $this->reservations->show($request->user(), $this->orderId($order));

        return response()->json(['data' => $detail]);
    }

    // 取消的狀態轉移提交後，才依結果回傳詳情或狀態衝突。
    public function cancel(Request $request, string $order): JsonResponse
    {
        $result = $this->reservations->cancel($request->user(), $this->orderId($order));

        return $result instanceof OrderConflict
            ? $this->conflict($result)
            : response()->json(['data' => $result]);
    }

    // 超出安全整數範圍的路徑與不存在的資源使用相同回應。
    private function orderId(string $order): int
    {
        $id = filter_var($order, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => OrderDraft::MAX_SAFE_INTEGER],
        ]);
        if ($id === false) {
            abort(404);
        }

        return $id;
    }

    // 三種業務衝突使用固定的代碼與訊息格式，供介面區分原因。
    private function conflict(OrderConflict $conflict): JsonResponse
    {
        return response()->json(['code' => $conflict->value, 'message' => $conflict->message()], 409);
    }
}
