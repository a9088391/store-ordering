<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Payments\MockPaymentNotifications;
use Illuminate\Console\Command;

final class DispatchPaymentNotification extends Command
{
    protected $signature = 'store-ordering:dispatch-payment-notification';

    protected $description = '派送已到可送時間的模擬付款通知';

    // 逐筆領取已到可送時間的待辦，回應遺失或程序中斷後仍可重試。
    public function handle(MockPaymentNotifications $notifications): int
    {
        $notifications->sendAvailable();

        return self::SUCCESS;
    }
}
