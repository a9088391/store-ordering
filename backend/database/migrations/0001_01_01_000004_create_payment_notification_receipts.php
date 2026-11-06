<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // 訂單服務以來源及事件識別保留驗證後的結果，並擴充原有異常紀錄。
    public function up(): void
    {
        // 本機綁定無法確認時仍可保存收件結果，避免把異常歸給本文聲稱的訂單。
        Schema::create('payment_notification_receipts', function (Blueprint $table): void {
            $table->string('source');
            $table->uuid('event_id');
            $table->char('body_hash', 64);
            $table->unsignedSmallInteger('status');
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->primary(['source', 'event_id']);
        });

        // 同一份衝突本文的重試沿用異常紀錄，異內容則各有自己的稽核證據。
        DB::statement('ALTER TABLE payment_anomalies ALTER COLUMN order_id DROP NOT NULL');
        Schema::table('payment_anomalies', function (Blueprint $table): void {
            $table->uuid('notification_event_id')->nullable();
            $table->char('conflicting_body_hash', 64)->nullable();
            $table->unique(['notification_event_id', 'conflicting_body_hash'], 'payment_anomalies_conflict_unique');
        });
    }

    // 已有通知處理結果時拒絕回滾，保留重送依據及不可刪除的異常歷程。
    public function down(): void
    {
        DB::statement('LOCK TABLE payment_notification_receipts, payment_anomalies IN ACCESS EXCLUSIVE MODE');
        if (DB::table('payment_notification_receipts')->exists()) {
            throw new LogicException('Cannot roll back payment notification tables containing receipts.');
        }

        // 空表才能移除收件資訊，再還原原本的訂單異常欄位約束。
        Schema::dropIfExists('payment_notification_receipts');
        Schema::table('payment_anomalies', function (Blueprint $table): void {
            $table->dropUnique('payment_anomalies_conflict_unique');
            $table->dropColumn(['notification_event_id', 'conflicting_body_hash']);
        });
        DB::statement('ALTER TABLE payment_anomalies ALTER COLUMN order_id SET NOT NULL');
    }
};
