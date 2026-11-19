<?php

declare(strict_types=1);

namespace App\Orders;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class OrderListQuery
{
    // 依篩選及身分範圍讀取穩定排序的訂單頁面。
    public function handle(User $actor, OrderListFilters $filters, int $page, int $perPage): LengthAwarePaginator
    {
        // 店員省略門市也只會看到自己的門市；明確越權的門市與不存在同樣回 404。
        $orders = $actor->restrictStores(DB::table('orders'), 'store_id');
        if ($filters->storeId !== null) {
            if (!$actor->canAccessStore($filters->storeId)) {
                abort(404);
            }
            $orders->where('store_id', $filters->storeId);
        }
        if ($filters->orderNumber !== null) {
            $orders->where('id', $filters->orderNumber);
        }
        if ($filters->status !== null) {
            $orders->where('status', $filters->status);
        }

        // 以台北時區的日界線比對 UTC 建立時間，起日包含、迄日不包含。
        if ($filters->from !== null) {
            $orders->whereRaw(
                "created_at >= CAST(? AS date)::timestamp AT TIME ZONE 'Asia/Taipei'",
                [$filters->from],
            );
        }
        if ($filters->to !== null) {
            $orders->whereRaw("created_at < CAST(? AS date)::timestamp AT TIME ZONE 'Asia/Taipei'", [$filters->to]);
        }

        // 同一時間的訂單以識別降冪固定先後，跨頁不因排序相同而改變順序。
        return $orders
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, page: $page);
    }
}
