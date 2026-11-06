<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // 保存訂單自己的付款意圖與異常；模擬金流資料另由獨立資料庫保存。
    public function up(): void
    {
        // 同一張訂單最多一個意圖，唯一付款識別可供通訊重試及後續通知核對。
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->foreignId('order_id')->primary()->constrained('orders');
            $table->uuid('payment_id')->unique();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->boolean('create_pending');
            $table->string('pending_reason')->nullable();
        });
        DB::statement(
            "ALTER TABLE payment_intents
             ADD CONSTRAINT payment_intents_amount_check CHECK (amount BETWEEN 1 AND 9007199254740991),
             ADD CONSTRAINT payment_intents_currency_check CHECK (currency = 'TWD')"
        );

        // 不覆寫先前異常，讓操作人員可按發生順序檢視。
        Schema::create('payment_anomalies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('reason');
            $table->timestampTz('occurred_at', 6);
            $table->index(['order_id', 'id']);
        });

        // 保留與釋放的舊紀錄原樣保留，成功扣除另有獨立類別。
        DB::statement(
            "ALTER TABLE stock_ledger DROP CONSTRAINT stock_ledger_type_check,
             ADD CONSTRAINT stock_ledger_type_check CHECK (type IN ('reserved', 'deducted', 'released'))"
        );
    }

    // 有付款意圖時拒絕回滾，避免讓既有訂單遺失固定識別、待辦或異常歷程。
    public function down(): void
    {
        DB::statement('LOCK TABLE payment_intents IN ACCESS EXCLUSIVE MODE');
        if (DB::table('payment_intents')->exists()) {
            throw new LogicException('Cannot roll back order payment tables containing payment intents.');
        }

        // 只有空的付款資料才能移除；舊的庫存紀錄約束隨之還原。
        DB::statement(
            "ALTER TABLE stock_ledger DROP CONSTRAINT stock_ledger_type_check,
             ADD CONSTRAINT stock_ledger_type_check CHECK (type IN ('reserved', 'released'))"
        );
        Schema::dropIfExists('payment_anomalies');
        Schema::dropIfExists('payment_intents');
    }
};
