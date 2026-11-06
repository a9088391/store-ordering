<?php

declare(strict_types=1);

return [
    // 登入額度與 PostgreSQL 交易必須使用同一個資料庫連線。
    'limiter' => 'database',
];
