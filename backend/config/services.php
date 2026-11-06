<?php

declare(strict_types=1);

return [
    'mock_payment' => [
        'url' => env('MOCK_PAYMENT_URL', 'http://mock-payment:8000'),
    ],
    'payment_notifications' => [
        'key' => env('STORE_ORDERING_PAYMENT_HMAC_KEY'),
        'url' => 'http://app:8000/api/payment-notifications',
    ],
];
