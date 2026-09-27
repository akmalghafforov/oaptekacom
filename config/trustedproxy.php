<?php

return [
    'proxies' => array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))),
];
