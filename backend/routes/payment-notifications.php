<?php

declare(strict_types=1);

use App\Http\Controllers\PaymentNotificationController;
use Illuminate\Support\Facades\Route;

// 只有訂單應用接收通知；獨立的機器入口只使用驗簽，沒有瀏覽器 session 或 CSRF。
Route::post('/payment-notifications', PaymentNotificationController::class)->middleware('cache.headers:no_store');
