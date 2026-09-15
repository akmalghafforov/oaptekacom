@props(['cartTotal' => 0])
@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    $navigationItems = [];

    if ($user->isAdmin()) {
        $navigationItems = [
            ['label' => 'Операционный центр', 'route' => 'admin.index', 'active' => ['admin.index']],
            ['label' => 'Пользователи', 'route' => 'admin.users', 'active' => ['admin.users']],
            ['label' => 'Подписки', 'route' => 'admin.subscriptions.index', 'active' => ['admin.subscriptions.*', 'admin.subscription-prices.*', 'admin.subscription-payments.*']],
            ['label' => 'Прайс-листы', 'route' => 'price-list-imports.index', 'active' => ['price-list-imports.*', 'admin.supplier-import-profiles.*']],
        ];
    } elseif ($user->isWholesaler()) {
        $navigationItems[] = ['label' => 'Обзор', 'route' => 'dashboard', 'active' => ['dashboard']];
        $navigationItems[] = ['label' => 'Каталог', 'route' => 'catalog', 'active' => ['catalog']];

        if ($user->canSupply()) {
            $navigationItems[] = ['label' => 'Прайс-листы', 'route' => 'price-list-imports.index', 'active' => ['price-list-imports.*']];
        }

        if ($user->canBuy()) {
            $navigationItems[] = ['label' => 'Корзина', 'route' => 'cart', 'active' => ['cart']];
        }

        $navigationItems[] = ['label' => 'Заказы', 'route' => 'orders.index', 'active' => ['orders.*']];
    }
@endphp

@if($user->isCustomer())
    @php
        $isCatalogActive = request()->routeIs('catalog*');
        $isCartActive = request()->routeIs('cart');
        $accountItems = [
            ['label' => 'Обзор', 'description' => 'Рабочий кабинет', 'route' => 'dashboard', 'active' => ['dashboard']],
            ['label' => 'Заказы', 'description' => 'История и статусы', 'route' => 'orders.index', 'active' => ['orders.*']],
            ['label' => 'Профиль', 'description' => 'Контакты и организация', 'route' => 'profile.edit', 'active' => ['profile.*']],
            ['label' => 'Мой тариф', 'description' => $user->subscription_plan->label(), 'route' => 'subscription.create', 'active' => ['subscription.*', 'payment-requests.*']],
        ];
        $accountInitial = str($user->name)->trim()->substr(0, 1)->upper();
        $accountIsActive = $user->hasActiveSubscription();
    @endphp

    <nav class="flex items-center gap-1 text-sm" aria-label="Основная навигация" data-primary-nav>
        <x-ui.icon-button label="Поиск" :href="route('catalog')" class="md:hidden {{ $isCatalogActive ? 'border-brand-500 bg-brand-50 text-brand-700' : '' }}" aria-current="{{ $isCatalogActive ? 'page' : 'false' }}" data-primary-link="catalog">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5"><circle cx="11" cy="11" r="7" stroke-width="1.8" /><path stroke-linecap="round" stroke-width="1.8" d="m16.5 16.5 4 4" /></svg>
        </x-ui.icon-button>
        <x-ui.icon-button label="Корзина" :href="route('cart')" :badge="$cartTotal" class="md:hidden {{ $isCartActive ? 'border-brand-500 bg-brand-50 text-brand-700' : '' }}" aria-current="{{ $isCartActive ? 'page' : 'false' }}" data-primary-link="cart">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.5 8H6.2M10 20h.01M17 20h.01" /></svg>
        </x-ui.icon-button>

        <div class="hidden items-center gap-1 md:flex">
            <a href="{{ route('catalog') }}" data-primary-link="catalog" class="flex min-h-10 items-center rounded-control px-3 {{ $isCatalogActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" @if($isCatalogActive) aria-current="page" @endif>Поиск</a>
            <a href="{{ route('cart') }}" data-primary-link="cart" class="flex min-h-10 items-center gap-2 rounded-control px-3 {{ $isCartActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" @if($isCartActive) aria-current="page" @endif>Корзина @if($cartTotal)<span class="rounded-full bg-brand-600 px-2 py-0.5 text-xs font-semibold text-white">{{ $cartTotal }}</span>@endif</a>
            <button type="button" disabled data-primary-placeholder="partners" class="flex min-h-10 items-center rounded-control px-3 text-slate-400" title="Раздел готовится">Партнёры <span class="sr-only">недоступно</span></button>
            <button type="button" disabled data-primary-placeholder="questions" class="flex min-h-10 items-center rounded-control px-3 text-slate-400" title="Раздел готовится">Вопросы <span class="sr-only">недоступно</span></button>
        </div>

        <x-ui.icon-button label="Открыть меню аккаунта" aria-controls="account-drawer" aria-expanded="false" data-drawer-open>
            <span aria-hidden="true" class="font-bold text-brand-700">{{ $accountInitial }}</span>
        </x-ui.icon-button>
    </nav>

    <x-ui.drawer name="account-drawer" title="Меню аккаунта" data-account-drawer>
        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto bg-slate-50 p-4 sm:p-5">
            <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-sm" aria-labelledby="current-account-title">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p id="current-account-title" class="text-xs font-semibold uppercase tracking-wide text-slate-400">Сейчас работаем</p>
                        <p class="mt-2 truncate text-base font-bold">{{ $user->organization?->name ?? $user->name }}</p>
                        <p class="mt-1 truncate text-sm text-muted">{{ $user->name }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $accountIsActive ? 'bg-brand-50 text-brand-700' : 'bg-amber-50 text-warning' }}">{{ $accountIsActive ? 'Активен' : 'Нужна подписка' }}</span>
                </div>
            </section>

            <section class="mt-4 rounded-panel border border-slate-200 bg-white p-2 shadow-sm" aria-labelledby="account-links-title">
                <h3 id="account-links-title" class="px-3 pb-2 pt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Аккаунт</h3>
                <div class="grid gap-1">
                    @foreach($accountItems as $item)
                        @php($isActive = request()->routeIs(...$item['active']))
                        <a href="{{ route($item['route']) }}" data-account-link="{{ $item['route'] }}" class="flex min-h-14 items-center justify-between gap-3 rounded-control px-3 py-2 {{ $isActive ? 'bg-brand-50 text-brand-700' : 'hover:bg-slate-50' }}" @if($isActive) aria-current="page" @endif>
                            <span><span class="block font-semibold">{{ $item['label'] }}</span><span class="mt-0.5 block text-xs text-muted">{{ $item['description'] }}</span></span>
                            <span aria-hidden="true" class="text-lg text-slate-300">›</span>
                        </a>
                    @endforeach
                </div>
            </section>

            <form method="post" action="{{ route('logout') }}" class="mt-auto pt-5">@csrf <x-ui.button variant="danger" class="w-full" data-account-logout>Выйти</x-ui.button></form>
        </div>
    </x-ui.drawer>
@else
    <details class="relative md:hidden">
        <summary class="flex min-h-10 cursor-pointer list-none items-center rounded-control border border-slate-300 px-3 text-sm font-medium">Меню</summary>
        <nav class="absolute right-0 z-10 mt-2 flex w-60 flex-col gap-1 rounded-panel border border-slate-200 bg-white p-2 shadow-panel" aria-label="Основная навигация">
            @foreach($navigationItems as $item)
                @php($isActive = request()->routeIs(...$item['active']))
                <a class="flex min-h-10 items-center justify-between gap-2 rounded-control px-3 {{ $isActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" href="{{ route($item['route']) }}" @if($isActive) aria-current="page" @endif><span>{{ $item['label'] }}</span>@if($item['route'] === 'cart' && $cartTotal)<span class="rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{{ $cartTotal }}</span>@endif</a>
            @endforeach
            <div class="mt-1 border-t border-slate-200 pt-1">
                <span class="flex min-h-10 items-center px-3 text-sm text-slate-500">{{ $user->name }}</span>
                @php($isProfileActive = request()->routeIs('profile.*'))
                <a class="flex min-h-10 items-center rounded-control px-3 {{ $isProfileActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" href="{{ route('profile.edit') }}" @if($isProfileActive) aria-current="page" @endif>Профиль</a>
                <form method="post" action="{{ route('logout') }}">@csrf <button class="flex min-h-10 w-full items-center rounded-control px-3 text-left text-danger hover:bg-red-50">Выйти</button></form>
            </div>
        </nav>
    </details>

    <nav class="hidden items-center gap-1 text-sm md:flex" aria-label="Основная навигация">
        @foreach($navigationItems as $item)
            @php($isActive = request()->routeIs(...$item['active']))
            <a class="flex min-h-10 items-center gap-2 rounded-control px-3 {{ $isActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" href="{{ route($item['route']) }}" @if($isActive) aria-current="page" @endif>{{ $item['label'] }}@if($item['route'] === 'cart' && $cartTotal)<span class="rounded-full bg-brand-600 px-2 py-0.5 text-xs text-white">{{ $cartTotal }}</span>@endif</a>
        @endforeach
        @if(! $user->isAdmin())
            <button type="button" disabled class="flex min-h-10 items-center rounded-control px-3 text-slate-400" title="Раздел готовится">Партнёры <span class="sr-only">недоступно</span></button>
            <button type="button" disabled class="flex min-h-10 items-center rounded-control px-3 text-slate-400" title="Раздел готовится">Вопросы <span class="sr-only">недоступно</span></button>
            <x-ui.icon-button label="Избранное — раздел готовится" disabled><span aria-hidden="true">♡</span></x-ui.icon-button>
            <x-ui.icon-button label="Уведомления — раздел готовится" disabled><span aria-hidden="true">♢</span></x-ui.icon-button>
        @endif
        <div class="ml-2 flex items-center gap-1 border-l border-slate-200 pl-3">
            <span class="px-1 text-slate-500">{{ $user->name }}</span>
            @php($isProfileActive = request()->routeIs('profile.*'))
            <a class="flex min-h-10 items-center rounded-control px-3 {{ $isProfileActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" href="{{ route('profile.edit') }}" @if($isProfileActive) aria-current="page" @endif>Профиль</a>
            <form method="post" action="{{ route('logout') }}">@csrf <button class="flex min-h-10 items-center rounded-control px-3 text-danger hover:bg-red-50">Выйти</button></form>
        </div>
    </nav>
@endif
