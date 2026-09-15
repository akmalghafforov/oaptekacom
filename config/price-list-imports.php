<?php

return [
    'disk' => env('PRICE_LIST_DISK', 'local'),
    'queue' => env('PRICE_LIST_QUEUE', 'price-list-imports'),
    'max_file_kilobytes' => 20480,
    'max_rows' => 100000,
    'max_columns' => 64,
    'chunk_size' => 500,
    'timezone' => 'Asia/Dushanbe',
    'near_expiry_days' => 90,
    'layout_detection_rows' => 100,
    'layout_detection_high_confidence' => 0.85,
    'multi_category_enabled' => env('PRICE_LIST_MULTI_CATEGORY_ENABLED', true),
];
