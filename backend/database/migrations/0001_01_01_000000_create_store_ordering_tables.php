<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // 先建立領域資料及一致性限制，再建立 session 與登入限流依賴的 Laravel 資料表。
    public function up(): void
    {
        // 示範初始化以唯一名稱辨識既有門市，重試時仍取得相同識別。
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
        });

        // 帳號由電子郵件辨識，門市外鍵與角色限制共同防止帳號取得錯誤範圍。
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email', 254)->unique();
            $table->string('password');
            $table->string('role');
            $table
                ->foreignId('store_id')
                ->nullable()
                ->constrained('stores');
            $table->timestamps();
        });
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_role_store_check CHECK (
                (role = 'headquarters' AND store_id IS NULL) OR (role = 'staff' AND store_id IS NOT NULL)
            )"
        );

        // 金額與庫存由資料庫保障技術範圍，讓 API 可安全輸出為 JSON 整數。
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->bigInteger('price');
        });
        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_price_check CHECK (price BETWEEN 1 AND 9007199254740991)'
        );

        // 複合主鍵保證同一門市、同一商品只存在一筆庫存。
        Schema::create('store_inventory', function (Blueprint $table): void {
            $table->foreignId('store_id')->constrained('stores');
            $table->foreignId('product_id')->constrained('products');
            $table->bigInteger('on_hand');
            $table->primary(['store_id', 'product_id']);
        });
        DB::statement(
            'ALTER TABLE store_inventory ADD CONSTRAINT store_inventory_on_hand_check
             CHECK (on_hand BETWEEN 0 AND 9007199254740991)'
        );

        // 沿用 Laravel database session 欄位，刪除資料列即可讓舊登入失效。
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table
                ->foreignId('user_id')
                ->nullable()
                ->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // 使用 Laravel database cache 保存登入額度，讓多個程序共享計數結果。
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        // session 的請求鎖獨立儲存，以 Laravel 原生封鎖協調讀寫。
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    // 回滾時依外鍵依賴的反向順序移除本次建立的資料表。
    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('store_inventory');
        Schema::dropIfExists('products');
        Schema::dropIfExists('users');
        Schema::dropIfExists('stores');
    }
};
