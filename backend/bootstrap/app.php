<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\RequireCsrfToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php', health: '/up')
    // 每分鐘清理已到期的訂單；庫存讀取及開單仍各自即時檢查到期。
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('store-ordering:expire-orders')->everyMinute();
    })
    // 讓 API 沿用 web session，並於授權前檢查會話期限。
    ->withMiddleware(function (Middleware $middleware): void {
        // 本文過大時不執行任何路由行為。
        $middleware->prepend(LimitRequestBody::class);

        // 保留 Laravel CSRF token 驗證，並在 Laravel 的 auth 優先順序之前檢查期限。
        $middleware->web(
            append: [EnforceSessionLifetime::class],
            replace: [PreventRequestForgery::class => RequireCsrfToken::class],
        );
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnforceSessionLifetime::class);
        $middleware->redirectGuestsTo('/');
    })
    // API 不受 Accept 標頭影響，回應固定代碼且不揭露例外細節。
    ->withExceptions(function (Exceptions $exceptions): void {
        // 對預期錯誤提供固定格式，其他例外保留 Laravel 的伺服器紀錄。
        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if (!$request->is('api', 'api/*')) {
                return null;
            }

            // 保留驗證錯誤、框架 HTTP 狀態與 Retry-After 等協定標頭。
            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof ValidationException => 422,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
            $headers['Cache-Control'] = 'no-store';

            // 避免把帳號存在性、路由查詢或堆疊資訊寫入回應。
            [$code, $message] = match ($status) {
                401 => $exception instanceof AuthenticationException
                    ? ['UNAUTHENTICATED', '請先登入。']
                    : ['INVALID_CREDENTIALS', '電子郵件或密碼不正確。'],
                403 => ['FORBIDDEN', '您沒有執行此操作的權限。'],
                404 => ['NOT_FOUND', '找不到指定資源。'],
                405 => ['METHOD_NOT_ALLOWED', '不支援此請求方法。'],
                413 => [LimitRequestBody::ERROR_CODE, LimitRequestBody::ERROR_MESSAGE],
                419 => ['CSRF_MISMATCH', '安全驗證已失效，請重新取得會話後再試。'],
                422 => ['VALIDATION_FAILED', '欄位格式或範圍不正確。'],
                429 => ['LOGIN_THROTTLED', '登入次數過多，請稍後再試。'],
                default => ['REQUEST_FAILED', '目前無法處理請求。'],
            };

            return response()->json(['code' => $code, 'message' => $message], $status, $headers);
        });
    })
    ->create();
