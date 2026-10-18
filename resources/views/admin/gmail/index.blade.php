@extends('layouts.app')
@section('content')
    <x-ui.page-header title="Gmail" description="Личный почтовый ящик администратора. Доступ только для чтения; результаты импорта сохраняются в системе." />
    <div class="mt-6 space-y-4" data-gmail-page>
        @if($error)<x-ui.alert type="warning">{{ $error }}</x-ui.alert>@endif
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    @if($connection)
                        <p class="font-semibold">{{ $connection->email }}</p>
                        <p class="text-sm text-muted">{{ $connection->status === 'connected' ? 'Подключён · только чтение' : 'Требуется повторное подключение' }}</p>
                    @else
                        <p>Gmail не подключён.</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-3">
                    <form method="post" action="{{ route('admin.gmail.connect') }}" data-gmail-action>
                        @csrf <x-mode-version />
                        <x-ui.button :disabled="!$configured">{{ $connection ? 'Подключить заново' : 'Подключить Gmail' }}</x-ui.button>
                    </form>
                    @if($connection)
                        <form method="post" action="{{ route('admin.gmail.refresh') }}" data-gmail-action>
                            @csrf <x-mode-version />
                            <x-ui.button variant="secondary">Обновить</x-ui.button>
                        </form>
                        <details>
                            <summary class="flex min-h-10 cursor-pointer items-center px-3 text-danger">Отключить Gmail</summary>
                            <p class="my-3 text-sm">Удалить подключение и отозвать доступ?</p>
                            <form method="post" action="{{ route('admin.gmail.destroy') }}" data-gmail-action>
                                @csrf @method('DELETE') <x-mode-version />
                                <x-ui.button variant="danger">Подтвердить отключение</x-ui.button>
                            </form>
                        </details>
                    @endif
                </div>
            </div>
            <p class="mt-3 text-sm text-muted" data-gmail-loading hidden role="status">Загрузка…</p>
        </x-ui.card>
        @if($connection?->status === 'connected')
            <form method="get" class="flex flex-wrap items-end gap-3" data-gmail-action>
                <x-ui.select name="size" label="Писем на странице">
                    @foreach(array_unique([10, 20, 50, $size]) as $option)<option value="{{ $option }}" @selected($size === $option)>{{ $option }}</option>@endforeach
                </x-ui.select>
                <x-ui.button variant="secondary">Применить</x-ui.button>
            </form>
            @if(count($inbox['messages']))
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>ID</th><th>Статус</th><th>Отправитель</th><th>Письмо</th><th>Дата</th><th>Вложения</th></tr></thead>
                    <tbody>@foreach($inbox['messages'] as $message)<tr>
                        <td>{{ $message['inbox_id'] ?? '—' }}</td>
                        <td>{{ $message['unread'] ? 'Непрочитано' : 'Прочитано' }}</td>
                        <td>{{ $message['from'] }}</td>
                        <td><a class="font-semibold text-brand underline" href="{{ route('admin.gmail.show', $message['id']) }}">{{ $message['subject'] }}</a><p class="mt-1 text-sm text-muted">{{ $message['snippet'] }}</p></td>
                        <td>{{ $message['date'] }}</td>
                        <td><details><summary class="min-h-10 cursor-pointer">Вложения: {{ count($message['attachments']) }}</summary>@include('admin.gmail.attachments')</details></td>
                    </tr>@endforeach</tbody>
                </table></div>
            @elseif(!$error)
                <x-ui.empty-state title="Входящих писем нет" description="Нажмите «Обновить», чтобы проверить почту." />
            @endif
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" :href="route('admin.gmail.index', ['size' => $size])">Первая страница</x-ui.button>
                @if($inbox['next'])<x-ui.button variant="secondary" :href="route('admin.gmail.index', ['size' => $size, 'page' => $inbox['next']])">Следующая страница</x-ui.button>@endif
            </div>
        @elseif(!$connection)
            <x-ui.empty-state title="Подключите личный Gmail" description="Почта доступна только владельцу подключения. Вложения можно просматривать по названию и размеру." />
        @endif
    </div>
@endsection
