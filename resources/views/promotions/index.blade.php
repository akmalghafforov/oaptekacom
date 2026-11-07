@extends('layouts.app')
@section('content')
<x-ui.page-header title="Акции" description="Предложения компаний, брошюры и специальные условия">
    <x-slot:actions><div class="flex flex-wrap gap-2"><x-ui.button :href="route('promotion-quotes.index')" variant="secondary">Запросы цены</x-ui.button>@can('create', \App\Models\Promotion::class)<x-ui.button :href="route('promotions.manage')">Управление акциями</x-ui.button>@endcan</div></x-slot:actions>
</x-ui.page-header>
<x-ui.card class="mb-5">
    <form method="get" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.select name="company" label="Компания"><option value="">Все компании</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company'] ?? '') == $company->id)>{{ $company->name }}</option>@endforeach</x-ui.select>
        <x-ui.select name="city" label="Город"><option value="">Все города</option>@foreach($cities as $city)<option @selected(($filters['city'] ?? '') === $city)>{{ $city }}</option>@endforeach</x-ui.select>
        <x-ui.input name="q" label="Название акции" :value="$filters['q'] ?? ''" />
        <div class="flex items-end gap-2"><x-ui.button>Найти</x-ui.button><x-ui.button :href="route('promotions.index')" variant="ghost">Сбросить</x-ui.button></div>
    </form>
</x-ui.card>
<div class="space-y-6">
@forelse($promotions->getCollection()->groupBy('organization_id') as $companyPromotions)
    @php($company = $companyPromotions->first()->organization)
    <section class="space-y-3">
        <div class="flex items-center gap-3">@if($company->logo_path)<img src="{{ route('promotions.logo', $company) }}" alt="Логотип {{ $company->name }}" class="size-12 rounded-control object-contain">@endif<h2 class="text-xl font-bold"><a class="text-accent underline" href="{{ route('promotions.index', ['company' => $company->id]) }}">{{ $company->name }}</a></h2></div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($companyPromotions as $promotion)
            <x-ui.card class="space-y-3">
                @php($banner = $promotion->attachments->firstWhere('role', 'banner'))
                @if($banner)<img loading="lazy" src="{{ route('promotions.attachments.show', $banner) }}" alt="Баннер акции {{ $promotion->title }}" class="h-44 w-full rounded-control object-cover">@endif
                <h3 class="text-lg font-bold">{{ $promotion->title }}</h3>
                <p class="text-sm text-muted">{{ $promotion->city }} · {{ $promotion->starts_on->format('d.m.Y') }} — {{ $promotion->ends_on->format('d.m.Y') }}</p>
                <p class="break-words text-sm">{{ \Illuminate\Support\Str::limit($promotion->description, 180) }}</p>
                <x-ui.button :href="route('promotions.show', $promotion)" variant="secondary">Подробнее</x-ui.button>
            </x-ui.card>
        @endforeach
        </div>
    </section>
@empty
    <x-ui.empty-state title="Действующих акций нет" description="Попробуйте изменить фильтры или вернитесь позже." />
@endforelse
</div>
<div class="mt-5">{{ $promotions->links() }}</div>
@endsection
