<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\InventoryItemData;
use App\Http\Responses\ProductData;
use App\Http\Responses\StoreData;
use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CatalogController
{
    // 只回傳目前身分可選的門市，並以識別排序供介面穩定顯示。
    public function stores(Request $request): JsonResponse
    {
        $stores = $this
            ->visibleStores($request->user())
            ->orderBy('id')
            ->get(['id', 'name']);

        // 將授權範圍內的查詢列映射成對外的門市格式。
        $data = $stores->map(static fn (object $row): StoreData => new StoreData((int) $row->id, $row->name));

        return response()->json(['data' => $data]);
    }

    // 回傳所有登入角色共用的商品目錄，金額明確轉成 TWD 整數。
    public function products(Request $request): JsonResponse
    {
        $products = DB::table('products')->select('id', 'name', 'price');

        // 明列前端所需欄位，避免回應隨資料表結構改動。
        return $this->paginate(
            $request,
            $products,
            static fn (object $row): ProductData => new ProductData((int) $row->id, $row->name, (int) $row->price),
        );
    }

    // 先確認門市在授權範圍，再以商品識別為每頁庫存排序。
    public function inventory(Request $request, string $store): JsonResponse
    {
        // 超出 PostgreSQL bigint 的路徑值與不存在的門市一樣回應 404。
        $storeId = filter_var($store, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($storeId === false) {
            abort(404);
        }

        // 授權與存在性使用同一筆查詢，避免從錯誤回應看出其他門市是否存在。
        $visible = $this
            ->visibleStores($request->user())
            ->where('id', $storeId)
            ->exists();
        if (!$visible) {
            abort(404);
        }

        // 本票沒有保留庫存，實體庫存完整可售，零庫存的品項仍須回傳。
        $inventory = DB::table('store_inventory')
            ->join('products', 'products.id', '=', 'store_inventory.product_id')
            ->where('store_inventory.store_id', $storeId)
            ->select('products.id', 'products.name', 'products.price', 'store_inventory.on_hand');

        // 以可售及實體庫存欄位表達領域概念，供後續加入保留庫存。
        return $this->paginate(
            $request,
            $inventory,
            static fn (object $row): InventoryItemData => new InventoryItemData(
                id: (int) $row->id,
                name: $row->name,
                price: (int) $row->price,
                onHand: (int) $row->on_hand,
                available: (int) $row->on_hand,
            ),
        );
    }

    // 共用門市可見範圍，清單與單店存取都套用同一個授權條件。
    private function visibleStores(User $user): Builder
    {
        $stores = DB::table('stores');

        return $user->role === 'headquarters' ? $stores : $stores->where('id', $user->store_id);
    }

    /**
     * 統一商品及庫存的分頁驗證、穩定排序與對外回應格式。
     *
     * @param Closure(object): (ProductData|InventoryItemData) $toItem
     */
    private function paginate(Request $request, Builder $query, Closure $toItem): JsonResponse
    {
        // 頁碼限制為 JSON 安全整數，並避免分頁偏移量乘法溢位。
        $pagination = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $page = (int) ($pagination['page'] ?? 1);
        $perPage = (int) ($pagination['perPage'] ?? 50);

        // 交由 Laravel 計算總筆數與取回指定頁，僅映射查詢結果所需欄位。
        $items = $query
            ->orderBy('id')
            ->paginate($perPage, page: $page);

        return response()->json([
            'data' => array_map($toItem, $items->items()),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $items->total()],
        ]);
    }
}
