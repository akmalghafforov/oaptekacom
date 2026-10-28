<?php

return [
    'enabled' => env('TELEGRAM_REVIEWS_ENABLED', false),
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    'group_chat_id' => env('TELEGRAM_GROUP_CHAT_ID', ''),
    'admin_mapping' => json_decode(env('TELEGRAM_ADMIN_MAPPING', '{}'), true) ?? [],
];
