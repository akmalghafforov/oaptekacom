@php
    $email = trim((string) config('contact.email'));
    $contacts = [];

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $contacts[] = ['href' => 'mailto:'.$email, 'label' => $email];
    }

    foreach (['phone' => 'Телефон', 'whatsapp_phone' => 'WhatsApp'] as $key => $label) {
        $displayPhone = trim((string) config('contact.'.$key));
        $digits = preg_replace('/[^0-9]/', '', $displayPhone);

        if (preg_match('/^\+?[0-9 ()-]+$/D', $displayPhone) && preg_match('/^[1-9][0-9]{6,14}$/D', $digits)) {
            $contacts[] = [
                'href' => $key === 'phone' ? 'tel:'.(str_starts_with($displayPhone, '+') ? '+' : '').$digits : 'https://wa.me/'.$digits,
                'label' => $label.': '.$displayPhone,
            ];
        }
    }
@endphp
<footer class="mt-auto border-t border-border bg-surface" lang="ru">
    <div class="page-container grid gap-5 pt-5 md:grid-cols-2 {{ auth()->user()?->isPharmacyMode() ? 'pb-24 md:pb-5' : 'pb-5' }}">
        <nav class="min-w-0" aria-label="Правовые документы">
            <h2 class="mb-2 px-4 text-sm font-semibold text-muted">Правовые документы</h2>
            <div class="flex flex-wrap gap-2">
                <x-ui.button variant="ghost" :href="route('legal.privacy')" class="max-w-full text-left [overflow-wrap:anywhere]">Политика конфиденциальности</x-ui.button>
                <x-ui.button variant="ghost" :href="route('legal.terms')" class="max-w-full text-left [overflow-wrap:anywhere]">Условия использования</x-ui.button>
            </div>
        </nav>
        @if($contacts)
            <nav class="min-w-0" aria-label="Контакты">
                <h2 class="mb-2 px-4 text-sm font-semibold text-muted">Контакты</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach($contacts as $contact)
                        <x-ui.button variant="ghost" :href="$contact['href']" class="max-w-full text-left [overflow-wrap:anywhere]">{{ $contact['label'] }}</x-ui.button>
                    @endforeach
                </div>
            </nav>
        @endif
    </div>
</footer>
