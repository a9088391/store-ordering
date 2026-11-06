<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // 未確認建立的舊意圖從完整額度開始，已確認的意圖仍由原本的待辦旗標排除。
    public function up(): void
    {
        // 未確認建立且仍有額度時，時間為空表示可立即領取。確認後清空時間並保留計數。
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->smallInteger('recovery_attempts')->default(0);
            $table->timestampTz('next_recovery_at', 6)->nullable();
        });
        DB::statement(
            'ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_recovery_attempts_check
             CHECK (recovery_attempts BETWEEN 0 AND 3)'
        );
    }

    // 尚未確認且已支出任何額度時拒絕回滾，避免重新遷移後多送出自動補辦。
    public function down(): void
    {
        // 回滾檢查與移除欄位之間，工作者不能再占用額度。
        DB::statement('LOCK TABLE payment_intents IN ACCESS EXCLUSIVE MODE');
        $hasActiveBudget = DB::table('payment_intents')
            ->where('create_pending', true)
            ->where('recovery_attempts', '>', 0)
            ->exists();
        if ($hasActiveBudget) {
            throw new LogicException('Cannot roll back payment recovery with spent attempts on unconfirmed intents.');
        }

        // 移除欄位時，PostgreSQL 也會移除該欄位的額度約束。
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropColumn(['recovery_attempts', 'next_recovery_at']);
        });
    }
};
