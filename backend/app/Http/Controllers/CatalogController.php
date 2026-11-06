<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\InventoryItemData;
use App\Http\Responses\PageData;
use App\Http\Responses\ProductData;
use App\Http\Responses\StoreData;
use App\Orders\OrderReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CatalogController
{
    // 庫存讀取與開單共用門市鎖及到期處理。
    public function __construct(private readonly OrderReservation $reservations)
    {
    }

    // 只回傳目前身分可選的門市，並以識別排序供介面穩定顯示。
    public function stores(Request $request): JsonResponse
    {
        $stores = $request
            ->user()
            ->restrictStores(DB::table('stores'), 'id')
            ->orderBy('id')
            ->get(['id', 'name']);

        // 將授權範圍內的查詢列映射成對外的門市格式。
        $data = $stores->map(static fn (object $row): StoreData => new StoreData((int) $row->id, $row->name));

        return response()->json(['data' => $data]);
    }

    // 回傳所有登入角色共用的商品目錄，金額明確轉成 TWD 整數。
    public function products(Request $request): JsonResponse
    {
        $page = PageData::fromQuery($request->query());
        $products = DB::table('products')
            ->select('id', 'name', 'price')
            ->orderBy('id')
            ->paginate($page->perPage, page: $page->page);

        // 明列前端所需欄位，避免回應隨資料表結構改動。
        return $page->response(
            $products,
            static fn (object $row): ProductData => new ProductData((int) $row->id, $row->name, (int) $row->price),
        );
    }

    // 先確認門市在授權範圍，再讀取已納入保留與到期狀態的庫存。
    public function inventory(Request $request, string $store): JsonResponse
    {
        // 超出 PostgreSQL bigint 的路徑值與不存在的門市一樣回應 404。
        $storeId = filter_var($store, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($storeId === false) {
            abort(404);
        }

        // 授權與存在性使用同一筆查詢，避免從錯誤回應看出其他門市是否存在。
        if (!$request->user()->canAccessStore($storeId)) {
            abort(404);
        }

        // 分頁無效時先拒絕請求，避免引發到期處理。
        $page = PageData::fromQuery($request->query());
        $inventory = $this->reservations->inventory($storeId, $page);

        // 維持既有欄位與整數格式，並回傳扣除有效保留後的可售量。
        return $page->response(
            $inventory,
            static fn (object $row): InventoryItemData => new InventoryItemData(
                id: (int) $row->id,
                name: $row->name,
                price: (int) $row->price,
                onHand: (int) $row->on_hand,
                available: (int) $row->available,
            ),
        );
    }
}
