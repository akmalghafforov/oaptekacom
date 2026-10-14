<?php

return [
    'legal_name' => env('LEGAL_NAME'),
    'postal_address' => env('LEGAL_POSTAL_ADDRESS'),
    'privacy_email' => env('LEGAL_PRIVACY_EMAIL'),
    'hosting_country' => env('LEGAL_HOSTING_COUNTRY'),
    'document_version' => env('LEGAL_DOCUMENT_VERSION', '1.0-draft'),
    'effective_date' => env('LEGAL_EFFECTIVE_DATE'),
    'document_date' => '2026-10-14',
    'published' => env('LEGAL_PUBLISHED', false),
];
