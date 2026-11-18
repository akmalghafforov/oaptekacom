<?php

return [
    'lifecycle' => [
        'network_retry_delays' => [300, 600, 1200],
        'retry_jitter' => 0.2,
        'recovery_hours' => (int) env('EXTERNAL_IMPORT_RECOVERY_HOURS', 24),
        'start_per_minute' => (int) env('EXTERNAL_IMPORT_START_PER_MINUTE', 10),
        'download_per_minute' => (int) env('EXTERNAL_IMPORT_DOWNLOAD_PER_MINUTE', 20),
        'poll_per_minute' => (int) env('EXTERNAL_IMPORT_POLL_PER_MINUTE', 60),
    ],
    'browser_node' => env('SUPPLIER_BROWSER_NODE', 'node'),
    'browser_executable' => env('PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH'),
    'pricelist_tj' => [
        'request_interval_seconds' => (int) env('PRICELIST_TJ_REQUEST_INTERVAL_SECONDS', 5),
        'phone' => env('PRICELIST_TJ_PHONE'),
        'password' => env('PRICELIST_TJ_PASSWORD'),
    ],
    'icorp' => [
        'take_over' => (bool) env('ICORP_TAKE_OVER', false),
        'request_interval_seconds' => (int) env('ICORP_REQUEST_INTERVAL_SECONDS', 5),
        'firm_id' => env('ICORP_FIRM_ID'),
        'phone' => env('ICORP_PHONE'),
        'password' => env('ICORP_PASSWORD'),
    ],
];
