<?php

return [
    'enabled' => env('GMAIL_IMPORTS_ENABLED', false),
    'connection_id' => env('GMAIL_IMPORTS_CONNECTION_ID'),
    'mailbox' => env('GMAIL_IMPORTS_MAILBOX'),
    'queue' => env('GMAIL_IMPORTS_QUEUE', 'gmail-imports'),
];
