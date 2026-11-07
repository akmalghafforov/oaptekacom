@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$promotion->exists ? $promotion->title : 'Новая акция'" :description="$promotion->exists ? $promotion->status->label() : 'Создание черновика'"><x-slot:actions><div class="flex flex-wrap gap-2"><x-ui.button :href="route('promotions.manage')" variant="secondary">К списку</x-ui.button>@if($promotion->exists)<x-ui.button :href="route('promotions.show', $promotion)" variant="secondary">Предпросмотр</x-ui.button>@endif</div></x-slot:actions></x-ui.page-header>
@if($promotion->moderation_comment)<x-ui.alert type="warning" class="mb-4">Комментарий администратора: {{ $promotion->moderation_comment }}</x-ui.alert>@endif
@if($promotion->exists)
<x-ui.card class="mb-5"><div class="flex flex-wrap gap-3">
@can('submit', $promotion)<form method="post" action="{{ route('promotions.transition', $promotion) }}">@csrf <x-mode-version /><x-ui.button name="action" value="submit">Отправить на проверку</x-ui.button></form>@endcan
@can('withdraw', $promotion)<form method="post" action="{{ route('promotions.transition', $promotion) }}">@csrf <x-mode-version /><x-ui.button name="action" value="withdraw" variant="secondary">Отозвать в черновик</x-ui.button></form>@endcan
@if(auth()->user()->isAdminMode() && in_array($promotion->status, [\App\Enums\PromotionStatus::Draft, \App\Enums\PromotionStatus::PendingReview], true))
<form method="post" action="{{ route('promotions.transition', $promotion) }}">@csrf <x-mode-version /><x-ui.button name="action" value="publish">Опубликовать</x-ui.button></form>
@endif
</div>
@can('review', $promotion)<form method="post" action="{{ route('promotions.transition', $promotion) }}" class="mt-4 space-y-3">@csrf <x-mode-version /><x-ui.textarea name="comment" label="Причина возврата или отказа" required /><div class="flex flex-wrap gap-2"><x-ui.button name="action" value="revise" variant="secondary">Вернуть на исправление</x-ui.button><x-ui.button name="action" value="reject" variant="danger">Отклонить</x-ui.button></div></form>@endcan
</x-ui.card>
@endif
@if(! $promotion->exists || auth()->user()->can('update', $promotion))
@if($promotion->status === \App\Enums\PromotionStatus::Published && ! auth()->user()->isAdminMode())<x-ui.alert type="warning" class="mb-4">Изменение акции или её материалов сразу скроет публикацию. После правок отправьте акцию на повторную проверку.</x-ui.alert>@endif
<x-ui.card class="mb-5"><form method="post" enctype="multipart/form-data" action="{{ $promotion->exists ? route('promotions.update', $promotion) : route('promotions.store') }}" class="space-y-4">
@csrf <x-mode-version />@if($promotion->exists) @method('PATCH') @endif
<x-ui.select name="organization_id" label="Компания">@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('organization_id', $promotion->organization_id) == $company->id)>{{ $company->name }}</option>@endforeach</x-ui.select>
<x-ui.input name="title" label="Заголовок" :value="$promotion->title" maxlength="255" required />
@if($promotion->exists && ($poster = $promotion->attachments->firstWhere('role', 'banner')))
<img src="{{ route('promotions.attachments.show', $poster) }}" alt="Постер акции {{ $promotion->title }}" class="max-h-80 w-full rounded-control object-contain">
@endif
<x-ui.input name="poster" type="file" label="Постер акции" accept=".jpg,.jpeg,.png,.webp" hint="Необязательно. JPEG, PNG или WebP до 5 МБ. Новый постер заменит текущий." />
<x-ui.textarea name="description" label="Описание" :value="$promotion->description" rows="4" /><x-ui.textarea name="conditions" label="Условия" :value="$promotion->conditions" rows="3" />
<div class="grid gap-3 sm:grid-cols-3"><x-ui.select name="city" label="Город"><option value="">Все города</option>@foreach($cities as $city)<option value="{{ $city }}" @selected(old('city', $promotion->city) === $city)>{{ $city }}</option>@endforeach</x-ui.select><x-ui.date-picker name="starts_on" label="Начало" :value="$promotion->starts_on?->toDateString()" required /><x-ui.date-picker name="ends_on" label="Окончание" min-field="starts_on" :value="$promotion->ends_on?->toDateString()" required /></div><p class="text-sm text-muted">Даты включительно, часовой пояс Asia/Dushanbe.</p>
<x-ui.button>Сохранить</x-ui.button></form></x-ui.card>
@if($promotion->exists)
<x-ui.card class="mb-5"><h2 class="mb-3 text-xl font-bold">Материалы</h2>
<form method="post" enctype="multipart/form-data" action="{{ route('promotions.attachments.store', $promotion) }}" class="grid gap-3 sm:grid-cols-2">@csrf <x-mode-version />
<x-ui.select name="role" label="Назначение"><option value="material">Материал</option><option value="banner">Баннер</option><option value="logo">Логотип компании</option></x-ui.select><x-ui.input name="file" type="file" label="Файл" accept=".jpg,.jpeg,.png,.webp,.pdf,.xlsx,.mp4" hint="Изображения до 5 МБ, PDF/XLSX до 20 МБ, MP4 до 100 МБ" required /><div><x-ui.button>Загрузить</x-ui.button></div></form>
<div class="mt-4 space-y-2">@foreach($promotion->attachments as $attachment)<div class="flex flex-wrap items-center justify-between gap-2"><a class="break-all text-accent underline" href="{{ route('promotions.attachments.show', $attachment) }}">{{ $attachment->original_name }} · {{ $attachment->role === 'banner' ? 'Баннер' : 'Материал' }}</a><form method="post" action="{{ route('promotions.attachments.destroy', $attachment) }}">@csrf <x-mode-version />@method('DELETE')<x-ui.button variant="danger">Удалить</x-ui.button></form></div>@endforeach</div>
</x-ui.card>
<h2 class="mb-3 text-xl font-bold">Позиции</h2>
<div class="space-y-4">
@foreach($promotion->items as $item)<x-ui.card><details><summary class="flex min-h-10 cursor-pointer items-center font-bold">{{ $item->displayName() }} · {{ $item->price ?? 'Цена по запросу' }}</summary>
<form method="post" action="{{ route('promotions.items.update', [$promotion, $item]) }}" class="mt-3 space-y-3">@csrf <x-mode-version />@method('PATCH')@include('promotions.item-fields', ['fieldId' => $item->id, 'copy' => false])<x-ui.button>Сохранить позицию</x-ui.button></form>
<form method="post" action="{{ route('promotions.items.destroy', [$promotion, $item]) }}" class="mt-3">@csrf <x-mode-version />@method('DELETE')<x-ui.button variant="danger">Удалить позицию</x-ui.button></form></details></x-ui.card>@endforeach
<x-ui.card><h3 class="mb-3 text-lg font-bold">Добавить вручную</h3><form method="post" action="{{ route('promotions.items.store', $promotion) }}" class="space-y-3">@csrf <x-mode-version />@include('promotions.item-fields', ['item' => null, 'fieldId' => 'new', 'copy' => false])<x-ui.button>Добавить позицию</x-ui.button></form></x-ui.card>
<x-ui.card><h3 class="mb-3 text-lg font-bold">Копировать из прайс-листа</h3><p class="mb-3 text-sm text-muted">Характеристики копируются. Цена и наличие акции управляются отдельно от прайса.</p><form method="post" action="{{ route('promotions.items.store', $promotion) }}" class="space-y-3">@csrf <x-mode-version /><x-ui.select name="source_offer_id" label="Товар из прайса" required><option value="">Выберите товар</option>@foreach($offers as $offer)<option value="{{ $offer->id }}">{{ $offer->source_name ?: $offer->medicine->name }} · {{ $offer->price }} TJS</option>@endforeach</x-ui.select>@include('promotions.item-fields', ['item' => null, 'fieldId' => 'copy', 'copy' => true])<x-ui.button>Копировать позицию</x-ui.button></form><div class="mt-3">{{ $offers->links() }}</div></x-ui.card>
</div>
@endif
@else
<x-ui.alert type="warning">Акция на проверке. Для редактирования отзовите заявку в черновик.</x-ui.alert>
@endif
@endsection
