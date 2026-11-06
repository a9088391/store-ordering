<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // 獨立保存模擬付款綁定及結果；訂單識別由 HTTP 提供，無跨資料庫外鍵。
    public function up(): void
    {
        // 付款識別唯一，並保留建立順序供總部查詢。
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('payment_id')->primary();
            $table->bigInteger('order_id');
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['created_at', 'payment_id']);
        });

        // PostgreSQL 也保護外部數值及狀態契約。
        DB::statement(
            "ALTER TABLE payments
             ADD CONSTRAINT payments_order_id_check CHECK (order_id BETWEEN 1 AND 9007199254740991),
             ADD CONSTRAINT payments_amount_check CHECK (amount BETWEEN 1 AND 9007199254740991),
             ADD CONSTRAINT payments_currency_check CHECK (currency = 'TWD'),
             ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'paid', 'failed'))"
        );
    }

    // 只允許移除空表，避免回滾使已承諾的付款結果無法再查詢。
    public function down(): void
    {
        DB::statement('LOCK TABLE payments IN ACCESS EXCLUSIVE MODE');
        if (DB::table('payments')->exists()) {
            throw new LogicException('Cannot roll back mock payment tables containing payments.');
        }

        Schema::dropIfExists('payments');
    }
};
