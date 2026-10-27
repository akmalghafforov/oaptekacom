<?php

return [
    'login' => env('OSON_SMS_LOGIN', ''),
    'bearer_token' => env('OSON_SMS_TOKEN', ''),
    'sender_name' => env('OSON_SMS_SENDER', ''),
    'server_url' => preg_replace('~/sendsms_v1\.php/?$~', '', rtrim((string) env('OSON_SMS_SERVER', 'https://api.osonsms.com'), '/')),
];
