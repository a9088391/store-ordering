<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    // RefreshDatabase 會重建資料表，必須先核對設定與實際連線的資料庫。
    protected function beforeRefreshingDatabase(): void
    {
        if (
            !$this->app->environment('testing')
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'store_ordering_phpunit'
            || config('database.connections.pgsql.url')
            || DB::scalar('SELECT current_database()') !== 'store_ordering_phpunit'
        ) {
            throw new RuntimeException('Tests require the isolated PostgreSQL database store_ordering_phpunit.');
        }

        // 直接執行 Artisan 初始化測試資料表，沿用真正的 PostgreSQL 遷移流程。
        $this->withoutMockingConsoleOutput();
    }
}
