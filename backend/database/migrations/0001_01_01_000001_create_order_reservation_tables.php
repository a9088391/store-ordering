<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // 建立不可編輯的訂單快照與歷程，並以資料庫約束保護數值及去重規則。
    public function up(): void
    {
        // 以建立人與固定長度的操作鍵雜湊去重；同門市查詢及待付款到期各有適用的索引。
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores');
            $table->foreignId('created_by')->constrained('users');
            $table->string('operation_key', 64);
            $table->string('status');
            $table->bigInteger('total');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->unique(['created_by', 'operation_key']);
            $table->index(['store_id', 'created_at', 'id']);
            $table->index(['created_at', 'id']);
        });
        DB::statement(
            "CREATE INDEX orders_active_reservations_index ON orders (store_id, status, expires_at)
             WHERE status IN ('awaiting_payment', 'processing')"
        );
        DB::statement(
            "ALTER TABLE orders
             ADD CONSTRAINT orders_total_check CHECK (total BETWEEN 1 AND 9007199254740991),
             ADD CONSTRAINT orders_status_check
                 CHECK (status IN ('awaiting_payment', 'processing', 'paid', 'failed', 'cancelled', 'expired'))"
        );

        // 複合主鍵避免同單重複品項，快照金額獨立於日後的商品價格。
        Schema::create('order_items', function (Blueprint $table): void {
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('product_id')->constrained('products');
            $table->string('name');
            $table->bigInteger('quantity');
            $table->bigInteger('unit_price');
            $table->bigInteger('subtotal');
            $table->primary(['order_id', 'product_id']);
        });
        DB::statement(
            'ALTER TABLE order_items
             ADD CONSTRAINT order_items_quantity_check CHECK (quantity BETWEEN 1 AND 9007199254740991),
             ADD CONSTRAINT order_items_unit_price_check CHECK (unit_price BETWEEN 1 AND 9007199254740991),
             ADD CONSTRAINT order_items_subtotal_check CHECK (subtotal BETWEEN 1 AND 9007199254740991)'
        );

        // 同一狀態只能進入一次，流水號保留狀態轉移順序。
        Schema::create('order_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('status');
            $table->timestampTz('occurred_at', 6);
            $table->unique(['order_id', 'status']);
        });

        // 每次保留或釋放必須對應既有的訂單品項，且同類異動只能記錄一次。
        Schema::create('stock_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('product_id');
            $table->bigInteger('quantity');
            $table->string('type');
            $table->timestampTz('occurred_at', 6);
            $table
                ->foreign(['order_id', 'product_id'])
                ->references(['order_id', 'product_id'])
                ->on('order_items');
            $table->unique(['order_id', 'product_id', 'type']);
        });
        DB::statement(
            "ALTER TABLE stock_ledger
             ADD CONSTRAINT stock_ledger_quantity_check CHECK (quantity BETWEEN 1 AND 9007199254740991),
             ADD CONSTRAINT stock_ledger_type_check CHECK (type IN ('reserved', 'released'))"
        );
    }

    // 依外鍵的反向順序回滾本次資料表。
    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('order_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
