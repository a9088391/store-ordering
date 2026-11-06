<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MockPaymentNotificationTest extends TestCase
{
    private const string CALLBACK = 'http://app:8000/api/payment-notifications';
    private const string KEY = 'mock-notification-test-key';
    private const string COMMAND = 'store-ordering:dispatch-payment-notification';

    // 使用真正的模擬金流路由；只在測試應用啟動時切換服務角色。
    public function createApplication(): Application
    {
        $previous = getenv('STORE_ORDERING_SERVICE');
        putenv('STORE_ORDERING_SERVICE=mock-payment');
        try {
            return parent::createApplication();
        } finally {
            putenv($previous === false ? 'STORE_ORDERING_SERVICE' : 'STORE_ORDERING_SERVICE=' . $previous);
        }
    }

    // 在 PHPUnit 既有 PostgreSQL 隔離交易內安裝模擬金流的實際資料表。
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(0, Artisan::call('migrate', [
            '--path' => 'database/mock-payment-migrations',
            '--force' => true,
        ]));
        config(['services.payment_notifications.key' => self::KEY]);
    }

    // 驗證決定、延遲、失聯、重新簽章與重送沿用同一原始事件。
    public function testDispatchesKeepTheEventAndRetryAfterTheLease(): void
    {
        $time = CarbonImmutable::parse('2026-11-07 03:00:00 UTC');
        $this->atTime($time);
        $binding = $this->createPayment();
        $path = '/api/payments/' . $binding['paymentId'];
        $event = ['status' => 'paid'];
        $attempts = [];
        // 只模擬訂單服務的 HTTP 連線；領取、租期與確認使用 PostgreSQL。
        Http::preventStrayRequests()->fake([
            self::CALLBACK => static function (Request $request) use (&$attempts): PromiseInterface {
                $attempts[] = $request;
                if (count($attempts) === 1) {
                    throw new ConnectionException('Simulated lost callback response.');
                }

                return Http::response(['received' => true]);
            },
        ]);

        // 尚未決定、查無付款或相反終局都無法排入有效派送。
        $this->postJson('/api/payment-notifications', [])->assertNotFound();
        $this->postJson('/api/payments/' . Str::uuid() . '/dispatch', ['mode' => 'immediate'])->assertNotFound();
        $this->postJson("$path/dispatch", ['mode' => 'immediate'])->assertConflict();
        $this->postJson("$path/decision", $event)->assertOk()->assertExactJson(['data' => $binding + $event]);
        $this->postJson("$path/decision", $event)->assertOk()->assertExactJson(['data' => $binding + $event]);
        $this->postJson("$path/decision", ['status' => 'failed'])->assertConflict();
        self::assertSame(0, Artisan::call(self::COMMAND));
        self::assertCount(0, $attempts);

        // 立即與延遲各有待辦，兩次請求使用相同事件識別。
        $eventId = $this->postJson("$path/dispatch", ['mode' => 'delayed'])->assertAccepted()->json('data.eventId');
        self::assertTrue(Str::isUuid($eventId));
        self::assertSame(0, Artisan::call(self::COMMAND));
        self::assertCount(0, $attempts);
        $this->postJson("$path/dispatch", ['mode' => 'immediate'])->assertAccepted()->assertExactJson([
            'data' => ['eventId' => $eventId, 'mode' => 'immediate'],
        ]);
        self::assertSame(0, Artisan::call(self::COMMAND));
        self::assertCount(1, $attempts);

        // 租期前不重送，租期結束可重新簽章送出，滿 60 秒才送延遲待辦。
        foreach ([29 => 1, 30 => 2, 59 => 2, 60 => 3, 91 => 3] as $seconds => $expected) {
            $this->atTime($time->addSeconds($seconds));
            self::assertSame(0, Artisan::call(self::COMMAND));
            self::assertCount($expected, $attempts);
        }

        // 已送達後仍可明確重送，成功回應的既有待辦不再自動領取。
        $this->postJson("$path/dispatch", ['mode' => 'immediate'])->assertAccepted()->assertExactJson([
            'data' => ['eventId' => $eventId, 'mode' => 'immediate'],
        ]);
        self::assertSame(0, Artisan::call(self::COMMAND));
        self::assertCount(4, $attempts);
        $body = $attempts[0]->body();
        $expectedEvent = ['eventId' => $eventId] + $binding + $event;
        self::assertSame($expectedEvent, json_decode($body, true, flags: JSON_THROW_ON_ERROR));
        foreach ([0, 30, 60, 91] as $attempt => $seconds) {
            $timestamp = (string) $time->addSeconds($seconds)->getTimestamp();
            $request = $attempts[$attempt];
            self::assertSame('POST', $request->method());
            self::assertSame($body, $request->body());
            self::assertSame([$timestamp], $request->header('X-Payment-Timestamp'));
            $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::KEY);
            self::assertSame([$signature], $request->header('X-Payment-Signature'));
        }
    }

    // 升級前的終局在首次派送補建事件，單次排程可處理多筆立即待辦。
    public function testOldTerminalPaymentCanDispatchMultipleTimesInOneTick(): void
    {
        $binding = $this->createPayment();
        $paymentId = $binding['paymentId'];
        $path = "/api/payments/$paymentId/dispatch";
        // 模擬前一版已保存終局，但尚未具備事件資料表的付款紀錄。
        DB::table('payments')->where('payment_id', $paymentId)->update(['status' => 'failed']);
        Http::preventStrayRequests()->fake([self::CALLBACK => Http::response()]);

        // 第一次補建後固定同一事件，三筆待辦同一次排程就會送達。
        $eventId = $this->postJson($path, ['mode' => 'immediate'])->assertAccepted()->json('data.eventId');
        for ($count = 0; $count < 2; $count++) {
            $this->postJson($path, ['mode' => 'immediate'])->assertAccepted()->assertJsonPath('data.eventId', $eventId);
        }
        self::assertSame(0, Artisan::call(self::COMMAND));
        Http::assertSentCount(3);
        self::assertSame(0, Artisan::call(self::COMMAND));
        Http::assertSentCount(3);
        $this->getJson("/api/payments/$paymentId")->assertOk()->assertJsonPath('data.status', 'failed');
    }

    // 建立一筆具有固定綁定的待處理付款，不倚賴其他服務或先前測試資料。
    private function createPayment(): array
    {
        $binding = ['paymentId' => (string) Str::uuid(), 'orderId' => 42, 'amount' => 90, 'currency' => 'TWD'];
        $this->postJson('/api/payments', $binding)->assertCreated();

        return $binding;
    }

    // 分別控制傳送時間及排程牆鐘，檢查邊界時不需等待實際租期。
    private function atTime(CarbonImmutable $time): void
    {
        $this->travelTo($time);
        $this->setDatabaseClock($time->format('Y-m-d H:i:sP'));
    }
}
