<?php

declare(strict_types=1);

return [
    'driver' => 'database',
    'table' => 'sessions',
    'lifetime' => 30,
    'expire_on_close' => true,
    'encrypt' => false,
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', 'store_ordering_session'),
    'path' => '/',
    'domain' => null,
    'secure' => false,
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
    // 同一 session 的請求依序讀寫，讓活動時間與登出撤銷有一致的順序。
    'block' => true,
    'block_store' => 'database',
];
