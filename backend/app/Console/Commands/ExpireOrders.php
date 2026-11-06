<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Orders\OrderReservation;
use Illuminate\Console\Command;

final class ExpireOrders extends Command
{
    protected $signature = 'store-ordering:expire-orders';

    protected $description = '釋放已到期且尚未開始付款的訂單庫存';

    // 與庫存查詢及開單共用同一個到期流程，重複執行不會增加釋放紀錄。
    public function handle(OrderReservation $reservations): int
    {
        $reservations->expireDue();

        return self::SUCCESS;
    }
}
