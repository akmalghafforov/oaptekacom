@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$banner->exists ? 'Редактировать баннер' : 'Создать баннер'" description="Предпросмотры не учитываются в статистике. Часовой пояс расписания: {{ config('app.timezone') }}."><x-slot:actions><x-ui.button :href="route('admin.catalog-banners.index')" variant="secondary">Все баннеры</x-ui.button></x-slot:actions></x-ui.page-header>
<form method="POST" enctype="multipart/form-data" action="{{ $banner->exists ? route('admin.catalog-banners.update', $banner) : route('admin.catalog-banners.store') }}" data-banner-editor class="space-y-5">
    @csrf <x-mode-version /> @if($banner->exists) @method('PUT') @endif
    <x-ui.card class="grid gap-4 md:grid-cols-2">
        <x-ui.input name="name" label="Внутреннее название" :value="$banner->name" required maxlength="255" />
        <x-ui.input name="duration_seconds" label="Длительность показа (секунды)" type="number" :value="$banner->duration_seconds" min="2" max="300" required />
        <x-ui.input name="starts_at" label="Начало показа (необязательно)" type="datetime-local" :value="$banner->starts_at?->format('Y-m-d\TH:i')" />
        <x-ui.input name="ends_at" label="Окончание показа (необязательно)" type="datetime-local" :value="$banner->ends_at?->format('Y-m-d\TH:i')" />
        <input type="hidden" name="is_active" value="0"><x-ui.checkbox name="is_active" label="Баннер активен" :checked="$banner->is_active" />
    </x-ui.card>
    <x-ui.card class="space-y-4">
        <h2 class="text-lg font-bold">Изображения и кадрирование</h2>
        <p class="text-sm text-muted">Загрузите одно изображение для всех устройств или отдельные изображения ниже. Затем настройте каждый кадр. JPEG, PNG, WebP до 15 МБ; готовый кадр до 5 МБ. Сохранение создаёт изображения рекомендуемого размера.</p>
        <x-ui.input name="source_all" type="file" label="Одно изображение для всех устройств" accept="image/jpeg,image/png,image/webp" data-banner-source-all />
        <p data-crop-status class="text-sm text-danger" role="alert"></p>
        <div class="grid gap-5 xl:grid-cols-3">
            @foreach(\App\Models\CatalogBanner::IMAGE_SIZES as $device => [$width, $height])
                @php($deviceLabel = ['desktop' => 'Компьютер', 'tablet' => 'Планшет', 'mobile' => 'Телефон'][$device])
                <div data-crop-device="{{ $device }}" data-width="{{ $width }}" data-height="{{ $height }}" class="min-w-0 space-y-3">
                    <h3 class="font-semibold">{{ $deviceLabel }} · {{ $width }} × {{ $height }}</h3>
                    <p class="text-xs text-muted">{{ ['desktop' => '3:1', 'tablet' => '2:1', 'mobile' => '4:3'][$device] }}</p>
                    <x-ui.input :name="$device.'_source'" type="file" :label="'Отдельное изображение: '.$deviceLabel" accept="image/jpeg,image/png,image/webp" data-crop-source />
                    <input type="file" name="{{ $device }}_image" data-crop-output hidden>
                    @error($device.'_image')<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                    @if($banner->exists)<img data-crop-existing src="{{ route('catalog.banners.image', [$banner, $device]) }}?v={{ $banner->updated_at->timestamp }}" alt="Предпросмотр: {{ $deviceLabel }}" class="w-full rounded-control">@endif
                    <canvas data-crop-canvas width="{{ $width }}" height="{{ $height }}" hidden aria-label="Предпросмотр кадра: {{ $deviceLabel }}" class="w-full rounded-control"></canvas>
                    <div data-crop-controls hidden class="space-y-2">
                        <x-ui.input :name="$device.'_zoom'" type="range" label="Масштаб" min="1" max="4" step="0.01" value="1" data-crop-zoom />
                        <x-ui.input :name="$device.'_x'" type="range" label="Положение по горизонтали" min="0" max="1" step="0.01" value="0.5" data-crop-x />
                        <x-ui.input :name="$device.'_y'" type="range" label="Положение по вертикали" min="0" max="1" step="0.01" value="0.5" data-crop-y />
                    </div>
                </div>
            @endforeach
        </div>
        <noscript><x-ui.alert type="warning">Для кадрирования и сохранения изображений включите JavaScript.</x-ui.alert></noscript>
    </x-ui.card>
    <x-ui.card class="space-y-4">
        <h2 class="text-lg font-bold">Страница предложения</h2>
        <x-ui.input name="title" label="Заголовок страницы" :value="$banner->title" required maxlength="255" />
        <x-ui.textarea name="description" label="Описание" :value="$banner->description" required maxlength="20000" />
        <x-ui.input name="whatsapp_number" label="Номер WhatsApp с кодом страны" :value="$banner->whatsapp_number" hint="Например: 992900000000" required />
        <x-ui.textarea name="whatsapp_message" label="Готовое сообщение (необязательно)" :value="$banner->whatsapp_message" maxlength="2000" />
    </x-ui.card>
    <x-ui.button data-banner-save>Сохранить баннер</x-ui.button>
</form>
@endsection
