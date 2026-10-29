<!doctype html>
<html lang="@yield('document-language', 'ru')" data-theme="{{ auth()->user()?->theme === 'dark' && ! request()->routeIs('login', 'login.*', 'admin.login*', 'provider.*', 'register', 'register.*') ? 'dark' : 'light' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>OAPTEKA</title>@if(config('account-modes.enabled') && auth()->check())<meta name="mode-version" content="{{ app(\App\Services\AccountModeContext::class)->version }}"><meta name="mode-user" content="{{ auth()->id() }}">@endif
@if(request()->routeIs('login', 'register') || auth()->user()?->isPharmacyMode())
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-NGFW8V5GLR"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-NGFW8V5GLR');
</script>
@endif
@if(request()->routeIs('cart'))<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">@endif @vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="flex min-h-dvh flex-col">
<header lang="ru" class="app-header border-b border-border bg-surface" data-app-header><div class="page-container flex min-h-16 flex-wrap items-center justify-between gap-3 py-3">
<a href="{{ auth()->check() ? route(auth()->user()->defaultLandingRouteName()) : route('login') }}" aria-label="OAPTEKA — на главную">
<img src="{{ asset('images/oapteka-logo-header.png') }}" alt="OAPTEKA" class="app-header-logo h-9 w-auto sm:h-10">
</a>
@auth
<x-navigation :cart-total="$navigationCartTotal ?? $cartTotal ?? 0" />
@else
<details class="relative md:hidden"><summary class="flex min-h-10 cursor-pointer list-none items-center rounded-control border border-control-border px-3 text-sm font-medium">Меню</summary><nav class="absolute right-0 z-10 mt-2 flex w-60 flex-col gap-1 rounded-panel border border-border bg-surface p-2 shadow-panel" aria-label="Навигация"><a class="flex min-h-10 items-center rounded-control px-3 text-secondary hover:bg-surface-subtle" href="{{ route('login') }}">Войти</a><a class="flex min-h-10 items-center rounded-control px-3 hover:bg-accent-surface" href="{{ route('register') }}">Регистрация</a></nav></details>
<nav class="hidden items-center gap-1 text-sm md:flex" aria-label="Навигация"><a class="flex min-h-10 items-center rounded-control px-3 text-secondary hover:bg-surface-subtle" href="{{ route('login') }}">Войти</a><a class="flex min-h-10 items-center rounded-control bg-action px-3 font-medium text-white hover:bg-action-hover" href="{{ route('register') }}">Регистрация</a></nav>
@endauth
</div></header>
<main class="page-container {{ request()->routeIs('login', 'login.*', 'admin.login', 'provider.*', 'register', 'register.*') ? 'max-w-lg py-10 sm:py-16' : 'py-6 sm:py-8' }} {{ auth()->user()?->isPharmacyMode() ? 'customer-page' : '' }}"><div class="mb-5 space-y-3" aria-live="polite">@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif @if(session('warning'))<x-ui.alert type="warning">{{ session('warning') }}</x-ui.alert>@endif @if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif</div><div data-mode-stale hidden><x-ui.alert type="warning">Режим изменился в другой вкладке. <a href="{{ request()->fullUrl() }}">Обновить страницу</a></x-ui.alert></div>@yield('content')</main>
<x-legal-footer />
<x-floating-whatsapp />
</body></html>
