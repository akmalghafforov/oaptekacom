<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>OAPTEKA</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body>
<header class="border-b border-slate-200 bg-white"><div class="page-container flex min-h-16 flex-wrap items-center justify-between gap-3 py-3">
<a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="flex items-center gap-2 text-lg font-bold tracking-tight text-brand-700"><span class="grid size-8 place-items-center rounded-control bg-brand-600 text-sm text-white" aria-hidden="true">+</span> OAPTEKA</a>
@auth
<x-navigation />
@else
<details class="relative md:hidden"><summary class="flex min-h-10 cursor-pointer list-none items-center rounded-control border border-slate-300 px-3 text-sm font-medium">Меню</summary><nav class="absolute right-0 z-10 mt-2 flex w-60 flex-col gap-1 rounded-panel border border-slate-200 bg-white p-2 shadow-panel" aria-label="Навигация"><a class="flex min-h-10 items-center rounded-control px-3 text-slate-600 hover:bg-slate-50" href="{{ route('login') }}">Войти</a><a class="flex min-h-10 items-center rounded-control px-3 hover:bg-brand-50" href="{{ route('register') }}">Регистрация</a></nav></details>
<nav class="hidden items-center gap-1 text-sm md:flex" aria-label="Навигация"><a class="flex min-h-10 items-center rounded-control px-3 text-slate-600 hover:bg-slate-50" href="{{ route('login') }}">Войти</a><a class="flex min-h-10 items-center rounded-control bg-brand-600 px-3 font-medium text-white hover:bg-brand-700" href="{{ route('register') }}">Регистрация</a></nav>
@endauth
</div></header>
<main class="page-container {{ request()->routeIs('login', 'login.otp.form', 'login.session.confirmation', 'admin.login', 'provider.*', 'register', 'register.*') ? 'max-w-lg py-10 sm:py-16' : 'py-6 sm:py-8' }}"><div class="mb-5 space-y-3" aria-live="polite">@if(session('success'))<x-ui.alert type="success">{{ session('success') }}</x-ui.alert>@endif @if(session('warning'))<x-ui.alert type="warning">{{ session('warning') }}</x-ui.alert>@endif @if($errors->any())<x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert>@endif</div>@yield('content')</main>
</body></html>
