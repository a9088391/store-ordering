<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Carbon\CarbonImmutable;

final readonly class OrderData
{
    // 清單與詳情共用這組欄位，金額與識別保持 JSON 整數。
    private function __construct(
        public int $id,
        public int $storeId,
        public int $createdBy,
        public string $status,
        public int $total,
        public string $createdAt,
        public string $expiresAt,
    ) {
    }

    // 明確取用對外欄位，避免回應隨資料表增加欄位而改動。
    public static function fromRow(object $row): self
    {
        return new self(
            (int) $row->id,
            (int) $row->store_id,
            (int) $row->created_by,
            $row->status,
            (int) $row->total,
            self::isoDate($row->created_at),
            self::isoDate($row->expires_at),
        );
    }

    // 訂單時間及各類歷程統一使用 UTC 的 ISO 8601 格式，保留資料庫微秒精度。
    public static function isoDate(string $timestamp): string
    {
        return CarbonImmutable::parse($timestamp)->toISOString();
    }
}
