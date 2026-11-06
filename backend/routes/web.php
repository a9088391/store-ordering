<?php

declare(strict_types=1);

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');

// 同來源 API 採 web middleware，共享 Laravel session 與 CSRF 驗證。
Route::prefix('api')
    ->middleware('cache.headers:no_store')
    ->group(function (): void {
        // 匿名使用者可先取得 CSRF token，登入與登出回傳同樣的會話格式。
        Route::get('/session', [SessionController::class, 'show']);
        Route::post('/login', [SessionController::class, 'login']);
        Route::post('/logout', [SessionController::class, 'logout']);

        // 商品及門市資訊只對有效登入開放，門市範圍由查詢層檢查。
        Route::middleware('auth')->group(function (): void {
            Route::get('/stores', [CatalogController::class, 'stores']);
            Route::get('/stores/{store}/inventory', [CatalogController::class, 'inventory'])->whereNumber('store');
            Route::get('/products', [CatalogController::class, 'products']);
        });
    });
