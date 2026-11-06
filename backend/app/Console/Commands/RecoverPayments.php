<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Payments\OrderPayments;
use Illuminate\Console\Command;

final class RecoverPayments extends Command
{
    protected $signature = 'store-ordering:recover-payments';

    protected $description = '補辦尚未確認建立的付款';

    // 定期處理資料庫中的既有付款待辦，重啟仍沿用原識別與剩餘額度。
    public function handle(OrderPayments $payments): int
    {
        $payments->recover();

        return self::SUCCESS;
    }
}
