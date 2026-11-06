<?php

declare(strict_types=1);

namespace App\Payments;

use App\Http\Responses\PageData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class MockPaymentClient
{
    // 以固定識別及相同綁定建立，安全處理前次建立是否成功未知的情況。
    public function create(PaymentData $payment): ?Response
    {
        return $this->request('POST', '/api/payments', ['json' => $payment->createPayload()]);
    }

    // 先查詢同一識別，讓人工同步可辨識查無紀錄與服務中斷。
    public function find(string $paymentId): ?Response
    {
        return $this->request('GET', "/api/payments/$paymentId");
    }

    // 由訂單應用驗證總部權限後，傳遞已驗證的分頁參數。
    public function list(PageData $page): ?Response
    {
        return $this->request('GET', '/api/payments', ['query' => [
            'page' => $page->page,
            'perPage' => $page->perPage,
        ]]);
    }

    // 總部只決定一次結果，訂單應用需再查詢或接收通知才會更新本機狀態。
    public function decide(string $paymentId, string $status): ?Response
    {
        return $this->request('POST', "/api/payments/$paymentId/decision", ['json' => ['status' => $status]]);
    }

    // 由模擬服務持久化派送請求，總部的回覆只代表排入待辦。
    public function dispatch(string $paymentId, string $mode): ?Response
    {
        return $this->request('POST', "/api/payments/$paymentId/dispatch", ['json' => ['mode' => $mode]]);
    }

    // 每個外呼各自限時三秒，保留 HTTP 狀態供呼叫端區分 404、衝突及未知結果。
    private function request(string $method, string $path, array $options = []): ?Response
    {
        try {
            return Http::baseUrl(config('services.mock_payment.url'))
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(3)
                ->send($method, $path, $options);
        } catch (ConnectionException) {
            return null;
        }
    }
}
