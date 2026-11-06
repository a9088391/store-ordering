<?php

declare(strict_types=1);

use App\Http\Controllers\MockPaymentController;
use Illuminate\Support\Facades\Route;

// 只有獨立模擬服務掛載內部路由；瀏覽器須由訂單應用的總部入口操作。
Route::get('/payments', [MockPaymentController::class, 'index']);
Route::post('/payments', [MockPaymentController::class, 'create']);
Route::get('/payments/{paymentId}', [MockPaymentController::class, 'show'])->whereUuid('paymentId');
Route::post('/payments/{paymentId}/decision', [MockPaymentController::class, 'decide'])->whereUuid('paymentId');
Route::post('/payments/{paymentId}/dispatch', [MockPaymentController::class, 'dispatch'])->whereUuid('paymentId');
