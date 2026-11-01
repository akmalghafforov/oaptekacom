@php
    $email = trim((string) config('contact.email'));
    $contacts = [];

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $contacts[] = ['href' => 'mailto:'.$email, 'label' => $email, 'icon' => 'email'];
    }

    foreach (['phone' => 'Телефон', 'whatsapp_phone' => 'WhatsApp'] as $key => $label) {
        $displayPhone = trim((string) config('contact.'.$key));
        $digits = preg_replace('/[^0-9]/', '', $displayPhone);

        if (preg_match('/^\+?[0-9 ()-]+$/D', $displayPhone) && preg_match('/^[1-9][0-9]{6,14}$/D', $digits)) {
            $contacts[] = [
                'href' => $key === 'phone' ? 'tel:'.(str_starts_with($displayPhone, '+') ? '+' : '').$digits : 'https://wa.me/'.$digits,
                'label' => $label.': '.$displayPhone,
                'icon' => $key,
            ];
        }
    }
@endphp
<footer class="mt-auto border-t border-border bg-surface" lang="ru">
    <div class="page-container grid gap-8 pt-8 md:grid-cols-3 md:gap-10 {{ auth()->user()?->isPharmacyMode() ? 'pb-24 md:pb-5' : 'pb-20 md:pb-5' }}">
        <nav class="min-w-0" aria-label="Правовые документы">
            <h2 class="mb-3 px-4 text-sm font-semibold text-muted">Правовые документы</h2>
            <div class="flex flex-col items-start gap-1">
                <x-ui.button variant="ghost" :href="route('legal.privacy')" class="max-w-full justify-start text-left [overflow-wrap:anywhere]">Политика конфиденциальности</x-ui.button>
                <x-ui.button variant="ghost" :href="route('legal.terms')" class="max-w-full justify-start text-left [overflow-wrap:anywhere]">Условия использования</x-ui.button>
            </div>
        </nav>
        @if($contacts)
            <nav class="min-w-0" aria-label="Контакты">
                <h2 class="mb-3 px-4 text-sm font-semibold text-muted">Контакты</h2>
                <ul class="flex flex-col items-start gap-1">
                    @foreach($contacts as $contact)
                        <li class="min-w-0 max-w-full">
                            <x-ui.button variant="ghost" :href="$contact['href']" class="max-w-full justify-start gap-3 text-left">
                                <svg class="size-4 shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                    @if($contact['icon'] === 'email')
                                        <rect x="3" y="5" width="18" height="14" rx="2" />
                                        <path d="m3 6 9 7 9-7" />
                                    @elseif($contact['icon'] === 'phone')
                                        <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z" />
                                    @else
                                        <path d="M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.1-4.9a8.5 8.5 0 1 1 16.4-3.6Z" />
                                        <path d="m8.5 7.5 1.5 3-1 1a9 9 0 0 0 3 3l1-1 3 1.5c0 1.5-1 2-2 2-4 0-8-4-8-8 0-1 .5-2 2-2Z" />
                                    @endif
                                </svg>
                                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $contact['label'] }}</span>
                            </x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
        <section class="min-w-0 px-4 pb-4 md:col-start-3" aria-label="Приложение для Android">
            <h2 class="mb-3 text-sm font-semibold text-muted">Приложение для Android</h2>
            <x-ui.button variant="secondary" :href="asset('downloads/OAPTEKA_1.0.3.apk')" download="OAPTEKA_1.0.3.apk" class="max-w-full gap-2 text-left">
                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
                    <path d="m7.4 5.1-1.6-2.8a.6.6 0 0 1 1-.6l1.7 2.9a9.2 9.2 0 0 1 7 0l1.7-2.9a.6.6 0 0 1 1 .6l-1.6 2.8A7.4 7.4 0 0 1 20 11H4a7.4 7.4 0 0 1 3.4-5.9ZM8 8.5a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6Zm8 0a.8.8 0 1 0 0-1.6.8.8 0 0 0 0 1.6ZM4 12h16v6a2 2 0 0 1-2 2v2a1 1 0 0 1-2 0v-2H8v2a1 1 0 0 1-2 0v-2a2 2 0 0 1-2-2v-6ZM1 12a1 1 0 0 1 2 0v6a1 1 0 0 1-2 0v-6Zm20 0a1 1 0 0 1 2 0v6a1 1 0 0 1-2 0v-6Z" />
                </svg>
                Скачать для Android
            </x-ui.button>
            <p class="mt-3 text-xs text-muted">APK · версия 1.0.3</p>
        </section>
    </div>
</footer>
