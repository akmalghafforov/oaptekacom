@extends('layouts.app')
@section('content')
    <x-ui.page-header :title="'Профили импорта · '.$organization->name">
        <x-slot:actions>
            <x-ui.button :href="route('admin.supplier-import-profiles.edit', ['supplier' => $organization, 'profile' => 'new'])">Создать профиль</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="mt-6">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Профиль</th><th>Состояние</th><th>Импорт из почты</th><th>Ручной импорт</th></tr></thead>
                <tbody>
                    @forelse($profiles as $profile)
                        <tr>
                            <td><x-ui.button variant="ghost" :href="route('admin.supplier-import-profiles.edit', ['supplier' => $organization, 'profile' => $profile->id])">{{ $profile->name }}</x-ui.button></td>
                            <td>{{ $profile->is_active ? 'Активен' : 'Неактивен' }}</td>
                            <td>{{ $profile->inbox_enabled ? 'Разрешён' : 'Отключён' }}</td>
                            <td>{{ $profile->manual_enabled ? 'Разрешён' : 'Отключён' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-ui.empty-state title="Профилей пока нет" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-6 grid gap-4 md:grid-cols-2">
        @foreach(['inbox' => 'Входящая почта', 'manual' => 'Ручной импорт'] as $channel => $heading)
            @php
                $field = $channel.'_import_profile_id';
                $current = $organization->{$field};
                $eligible = $profiles->filter(fn ($profile) => $profile->is_active && $profile->{$channel.'_enabled'});
                $unusable = $current !== null && ! $eligible->contains('id', $current);
            @endphp
            <x-ui.card>
                <h2 class="text-lg font-bold">{{ $heading }}</h2>
                @if($unusable)
                    <x-ui.alert type="warning" class="mt-3">Назначенный профиль «{{ $profiles->find($current)?->name ?? $current }}» недоступен.</x-ui.alert>
                @endif
                <form method="post" action="{{ route('admin.supplier-import-profiles.channel.update', [$organization, $channel]) }}" class="mt-4 space-y-4">
                    @csrf <x-mode-version /> @method('PUT')
                    <x-ui.select :name="$field" label="Профиль по умолчанию">
                        @if($unusable)<option value="{{ $current }}" disabled selected>Назначенный профиль недоступен</option>@endif
                        <option value="">Без профиля по умолчанию</option>
                        @foreach($eligible as $profile)
                            <option value="{{ $profile->id }}" @selected((string) old($field, $current) === (string) $profile->id)>{{ $profile->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button>Сохранить профиль по умолчанию</x-ui.button>
                </form>
                @if($channel === 'inbox')
                    <form method="post" action="{{ route('admin.supplier-import-profiles.channel.update', [$organization, 'senders']) }}" class="mt-6 space-y-4">
                        @csrf <x-mode-version /> @method('PUT')
                        <x-ui.textarea name="sender_emails" label="Email-адреса отправителей" :value="$senderEmails" hint="Один адрес на строку. Один адрес можно использовать для нескольких поставщиков с разными фильтрами файлов." />
                        <x-ui.button>Сохранить адреса</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        @endforeach
    </div>
@endsection
