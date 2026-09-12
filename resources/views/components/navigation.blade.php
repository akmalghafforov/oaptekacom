@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    $navigationItems = [];

    if ($user->isAdmin()) {
        $navigationItems = [
            ['label' => 'Операционный центр', 'route' => 'admin.index', 'active' => ['admin.index']],
            ['label' => 'Пользователи', 'route' => 'admin.users', 'active' => ['admin.users']],
            ['label' => 'Подписки', 'route' => 'admin.subscriptions.index', 'active' => ['admin.subscriptions.*', 'admin.subscription-prices.*', 'admin.subscription-payments.*']],
        ];
    } else {
        $navigationItems[] = ['label' => 'Обзор', 'route' => 'dashboard', 'active' => ['dashboard']];

        if ($user->isCustomer()) {
            $navigationItems[] = ['label' => 'Подписка', 'route' => 'subscription.create', 'active' => ['subscription.*', 'payment-requests.*']];
        }

        $navigationItems[] = ['label' => 'Каталог', 'route' => 'catalog', 'active' => ['catalog']];

        if ($user->canBuy()) {
            $navigationItems[] = ['label' => 'Корзина', 'route' => 'cart', 'active' => ['cart']];
        }

        $navigationItems[] = ['label' => 'Заказы', 'route' => 'orders.index', 'active' => ['orders.*']];
    }
@endphp

<details class="relative md:hidden">
    <summary class="flex min-h-10 cursor-pointer list-none items-center rounded-control border border-slate-300 px-3 text-sm font-medium">Меню</summary>
    <nav class="absolute right-0 z-10 mt-2 flex w-60 flex-col gap-1 rounded-panel border border-slate-200 bg-white p-2 shadow-panel" aria-label="Основная навигация">
        @foreach($navigationItems as $item)
            @php($isActive = request()->routeIs(...$item['active']))
            <a class="flex min-h-10 items-center rounded-control px-3 {{ $isActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" href="{{ route($item['route']) }}" @if($isActive) aria-current="page" @endif>{{ $item['label'] }}</a>
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
        <a class="flex min-h-10 items-center rounded-control px-3 {{ $isActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" href="{{ route($item['route']) }}" @if($isActive) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
    <div class="ml-2 flex items-center gap-1 border-l border-slate-200 pl-3">
        <span class="px-1 text-slate-500">{{ $user->name }}</span>
        @php($isProfileActive = request()->routeIs('profile.*'))
        <a class="flex min-h-10 items-center rounded-control px-3 {{ $isProfileActive ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-50' }}" href="{{ route('profile.edit') }}" @if($isProfileActive) aria-current="page" @endif>Профиль</a>
        <form method="post" action="{{ route('logout') }}">@csrf <button class="flex min-h-10 items-center rounded-control px-3 text-danger hover:bg-red-50">Выйти</button></form>
    </div>
</nav>
