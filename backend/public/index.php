<?php

declare(strict_types=1);

use App\Http\Middleware\LimitRequestBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

require __DIR__ . '/../vendor/autoload.php';

// 在 Laravel 解碼本文前回應超限，避免請求進入 session、CSRF 或路由處理。
if (LimitRequestBody::exceedsAtEntry($_SERVER)) {
    $request = new Request(server: $_SERVER);
    $headers = ['Cache-Control' => 'no-store'];
    $response = $request->is('api', 'api/*')
        ? new JsonResponse([
            'code' => LimitRequestBody::ERROR_CODE,
            'message' => LimitRequestBody::ERROR_MESSAGE,
        ], 413, $headers)
        : new Response(
            LimitRequestBody::ERROR_MESSAGE,
            413,
            $headers + ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    $response->prepare($request)->send();

    return;
}

$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->handleRequest(Request::capture());
