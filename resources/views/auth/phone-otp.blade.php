@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$title" description="Введите шестизначный код из SMS." />
@php
    $exhausted = $otpState['send_count'] >= 3 && $otpState['allowance_resets_at'] > $otpState['server_time'];
    $seconds = max(0, (int) ceil((($otpState['resend_available_at'] ?? $otpState['server_time']) - $otpState['server_time']) / 1000));
    $resendLabel = ! $exhausted && $seconds > 0 ? 'Отправить код повторно через '.sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60) : 'Отправить код повторно';
    $supportPhone = trim((string) config('contact.whatsapp_phone'));
    $supportDigits = preg_replace('/[^0-9]/', '', $supportPhone);
    $validSupport = preg_match('/^\+?[0-9 ()-]+$/D', $supportPhone) && preg_match('/^[1-9][0-9]{6,14}$/D', $supportDigits);
@endphp
<x-ui.card data-otp-resend="{{ json_encode($otpState) }}">
@error('phone')<x-ui.alert type="danger">{{ $message }}</x-ui.alert>@enderror
<form method="post" action="{{ route($route) }}" class="space-y-4">@csrf <x-mode-version />
<p>Код отправлен на номер {{ $phone }}.</p>
<x-ui.input name="code" label="Код из SMS" hint="Введите 6 цифр." inputmode="numeric" autocomplete="one-time-code" maxlength="7" required data-sms-code-mask autofocus />
<x-ui.button class="w-full">Подтвердить</x-ui.button>
</form><form method="post" action="{{ route($resend_route) }}" class="mt-4">@csrf <x-mode-version /> @if($resend_route === 'profile.phone.send')<input type="hidden" name="phone" value="{{ $phone }}">@endif <x-ui.button variant="ghost" class="w-full" data-otp-resend-button :disabled="$exhausted || $seconds > 0">{{ $resendLabel }}</x-ui.button></form><div data-otp-fallback @if(! $exhausted) hidden @endif class="mt-4 space-y-2">
<p>Не получили код? Свяжитесь с нами в WhatsApp.</p>
@if($validSupport)
<x-ui.button variant="secondary" :href="'https://wa.me/'.$supportDigits" target="_blank" rel="noopener noreferrer">{{ $supportPhone }}</x-ui.button>
@endif
</div></x-ui.card>
@endsection
