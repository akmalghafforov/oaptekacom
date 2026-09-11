<?php

namespace App\Support;

class PhoneNormalizer
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);
        if ($phone === '' || preg_match('/[^0-9+()\s.-]/', $phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (! preg_match('/^(?:992)?(\d{9})$/', $digits, $matches)) {
            return null;
        }

        return '+992'.$matches[1];
    }

    public static function osonRecipient(string $canonicalPhone): string
    {
        return ltrim($canonicalPhone, '+');
    }
}
