<?php

declare(strict_types=1);

namespace App\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class MockPaymentNotifications
{
    private const int DISPATCHES_PER_TICK = 4;

    // 呼叫端鎖定終局付款，首次決定或升級後的首次操作只建立一份原始事件。
    public function eventId(PaymentData $payment): string
    {
        $eventId = DB::table('payment_events')
            ->where('payment_id', $payment->paymentId)
            ->value('event_id');
        if ($eventId !== null) {
            return $eventId;
        }

        // 保存原始位元組，重新派送僅重算時間及簽章。
        $eventId = (string) Str::uuid();
        $body = ['eventId' => $eventId] + $payment->createPayload() + ['status' => $payment->status];
        DB::table('payment_events')->insert([
            'event_id' => $eventId,
            'payment_id' => $payment->paymentId,
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ]);

        return $eventId;
    }

    // 每次人工派送都另存一筆待辦，重送沿用事件識別並以資料庫牆鐘安排。
    public function enqueue(string $eventId, bool $delayed): void
    {
        DB::table('payment_notification_dispatches')->insert([
            'event_id' => $eventId,
            'available_at' => DB::raw($delayed ? "clock_timestamp() + interval '60 seconds'" : 'clock_timestamp()'),
        ]);
    }

    // 每次排程以固定上限消化待辦，避免每秒只能派送一筆或單次排程持續過久。
    public function sendAvailable(): void
    {
        // 缺少金鑰時不領取任何待辦，避免延後可恢復的派送。
        $key = (string) config('services.payment_notifications.key');
        if ($key === '') {
            return;
        }

        // 逐筆領取避免為尚未送出的工作占用租期，清空後立即結束這次排程。
        for ($sent = 0; $sent < self::DISPATCHES_PER_TICK; $sent++) {
            if (!$this->sendNext($key)) {
                return;
            }
        }
    }

    // 短交易領取一筆，再以獨立 HTTP 傳送，程序中斷時可在租期結束後重試。
    private function sendNext(string $key): bool
    {
        // 單一 SQL 提交租期，重疊的工作程序略過已鎖列，外呼期間不持有鎖。
        $dispatch = DB::selectOne(<<<'SQL'
            WITH candidate AS (
                SELECT id FROM payment_notification_dispatches
                WHERE delivered_at IS NULL AND available_at <= clock_timestamp()
                ORDER BY available_at, id
                LIMIT 1 FOR UPDATE SKIP LOCKED
            )
            UPDATE payment_notification_dispatches AS dispatch
            SET available_at = clock_timestamp() + interval '30 seconds'
            FROM candidate, payment_events AS event
            WHERE dispatch.id = candidate.id AND dispatch.event_id = event.event_id
            RETURNING dispatch.id, dispatch.available_at, event.body
            SQL, useReadPdo: false);
        if ($dispatch === null) {
            return false;
        }

        // 每次外呼都用當下時間重簽，逾時或非成功回應保留待辦。
        $timestamp = (string) now()->getTimestamp();
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-Payment-Timestamp' => $timestamp,
                    'X-Payment-Signature' => PaymentNotificationSignature::sign($timestamp, $dispatch->body, $key),
                ])
                ->withBody($dispatch->body, 'application/json')
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(3)
                ->post(config('services.payment_notifications.url'));
        } catch (ConnectionException) {
            return true;
        }

        // 只確認自己領取的租期，晚到的回應不能改寫其他程序的領取狀態。
        if ($response->successful()) {
            DB::table('payment_notification_dispatches')
                ->where('id', $dispatch->id)
                ->where('available_at', $dispatch->available_at)
                ->update(['delivered_at' => DB::raw('clock_timestamp()')]);
        }

        return true;
    }
}
