<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class LegalController extends Controller
{
    public function privacy(string $locale = 'ru'): View
    {
        return $this->document('privacy', $locale);
    }

    public function terms(string $locale = 'ru'): View
    {
        return $this->document('terms', $locale);
    }

    private function document(string $document, string $locale): View
    {
        $legal = config('legal');
        $isDraft = ! $legal['published'];
        foreach (['legal_name', 'postal_address', 'privacy_email', 'hosting_country', 'document_version', 'effective_date'] as $field) {
            if (! is_string($legal[$field]) || trim($legal[$field]) === '') {
                $isDraft = true;
            }
        }

        return view('legal.show', compact('document', 'locale', 'legal', 'isDraft'));
    }
}
