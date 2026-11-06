<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // 事件及派送待辦都由模擬金流保存，重啟後沿用原本的內容與時間。
    public function up(): void
    {
        // 同一筆付款只產生一個事件，保存原始本文以免重送因重新編碼而改變。
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->foreignUuid('payment_id')->unique()->constrained('payments', 'payment_id');
            $table->text('body');
        });

        // 每次明確派送都可排入新待辦；只在收到成功回應後標為已送達。
        Schema::create('payment_notification_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('event_id')->constrained('payment_events', 'event_id');
            $table->timestampTz('available_at', 6);
            $table->timestampTz('delivered_at', 6)->nullable();
        });
        DB::statement(
            'CREATE INDEX payment_notification_dispatches_ready
             ON payment_notification_dispatches (available_at, id) WHERE delivered_at IS NULL'
        );
    }

    // 已保存的事件與待辦是重送依據，有事件時拒絕回滾。
    public function down(): void
    {
        DB::statement('LOCK TABLE payment_events, payment_notification_dispatches IN ACCESS EXCLUSIVE MODE');
        if (DB::table('payment_events')->exists()) {
            throw new LogicException('Cannot roll back mock payment notification tables containing events.');
        }

        // 依外鍵順序移除空表。
        Schema::dropIfExists('payment_notification_dispatches');
        Schema::dropIfExists('payment_events');
    }
};
