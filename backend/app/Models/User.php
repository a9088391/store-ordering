<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

final class User extends Authenticatable
{
    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['id' => 'integer', 'store_id' => 'integer'];

    // 總部可跨店，其餘帳號的查詢一律限於所屬門市。
    public function restrictStores(Builder $query, string $storeColumn): Builder
    {
        return $this->role === 'headquarters' ? $query : $query->where($storeColumn, $this->store_id);
    }

    // 用同一筆查詢檢查門市存在且可見，讓呼叫端能統一回應跨店與不存在的情況。
    public function canAccessStore(int $storeId): bool
    {
        return $this
            ->restrictStores(DB::table('stores'), 'id')
            ->where('id', $storeId)
            ->exists();
    }
}
