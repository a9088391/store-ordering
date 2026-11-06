<?php

declare(strict_types=1);

namespace App\Http\Responses;

final readonly class SessionUserData
{
    // 只公開前端識別身分及門市範圍所需的使用者欄位。
    public function __construct(
        public int $id,
        public string $name,
        public string $role,
        public ?int $storeId,
    ) {
    }
}
