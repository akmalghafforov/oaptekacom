@extends('layouts.app')
@section('content')
<x-ui.page-header title="Рекламные баннеры" description="Управление размещением и статистика показов. Показ: не менее 50% изображения в течение одной непрерывной секунды в активной вкладке."><x-slot:actions><x-ui.button :href="route('admin.catalog-banners.create')">Создать баннер</x-ui.button></x-slot:actions></x-ui.page-header>
<x-ui.card>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Название</th><th>Статус</th><th>Расписание</th><th>Секунды</th><th>Показы</th><th>Действия</th></tr></thead><tbody>
    @forelse($banners as $banner)
        <tr><td>{{ $banner->name }}</td><td>{{ $banner->is_active ? 'Активен' : 'Отключён' }}</td><td>{{ $banner->starts_at?->format('d.m.Y H:i') ?? 'Без начала' }} — {{ $banner->ends_at?->format('d.m.Y H:i') ?? 'Без окончания' }}</td><td>{{ $banner->duration_seconds }}</td><td>{{ number_format($banner->impressions, 0, '.', ' ') }}</td><td><div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('admin.catalog-banners.edit', $banner)" variant="secondary">Изменить / предпросмотр</x-ui.button>
            <form method="POST" action="{{ route('admin.catalog-banners.activation', $banner) }}">@csrf @method('PATCH') <x-mode-version /><input type="hidden" name="is_active" value="{{ $banner->is_active ? 0 : 1 }}"><x-ui.button variant="secondary">{{ $banner->is_active ? 'Отключить' : 'Активировать' }}</x-ui.button></form>
            <form method="POST" action="{{ route('admin.catalog-banners.destroy', $banner) }}" data-banner-delete>@csrf @method('DELETE') <x-mode-version /><x-ui.button variant="danger">Удалить</x-ui.button></form>
        </div></td></tr>
    @empty
        <tr><td colspan="6"><x-ui.empty-state title="Баннеров пока нет" description="Создайте первый баннер для размещения в каталоге." /></td></tr>
    @endforelse
    </tbody></table></div>
    <div class="mt-4">{{ $banners->links() }}</div>
</x-ui.card>
@endsection
