<?php

declare(strict_types=1);

namespace App\Orders;

enum OrderConflict: string
{
    case OperationKey = 'OPERATION_KEY_CONFLICT';
    case Stock = 'INSUFFICIENT_STOCK';
    case State = 'ORDER_STATE_CONFLICT';

    // 對應穩定的錯誤代碼，提供使用者能據以處理的訊息。
    public function message(): string
    {
        return match ($this) {
            self::OperationKey => '此操作鍵已用於內容不同的訂單。',
            self::Stock => '部分品項的可售庫存不足。',
            self::State => '此訂單目前無法取消。',
        };
    }
}
