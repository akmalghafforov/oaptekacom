@extends('layouts.app')
@section('content')
    <x-ui.page-header :title="$message['subject']">
        <x-slot:actions><x-ui.button variant="secondary" :href="route('admin.gmail.index')">К входящим</x-ui.button></x-slot:actions>
    </x-ui.page-header>
    <div class="mt-6 space-y-4" data-gmail-page>
        <x-ui.card>
            <p class="break-all"><strong>ID:</strong> {{ $message['id'] }}</p>
            <p><strong>От:</strong> {{ $message['from'] }}</p>
            <p><strong>Дата:</strong> {{ $message['date'] }}</p>
            <p>{{ $message['unread'] ? 'Непрочитано' : 'Прочитано' }} · Открытие не меняет статус письма.</p>
        </x-ui.card>
        <x-ui.card>
            @if($message['html'] !== '')
                <iframe title="Содержимое письма" sandbox="" referrerpolicy="no-referrer" srcdoc="{{ $message['document'] }}" class="min-h-96 w-full border-0 bg-white"></iframe>
                @if($message['plain'] !== '')<details class="mt-4"><summary class="min-h-10 cursor-pointer">Текстовая версия</summary><pre class="mt-3 whitespace-pre-wrap break-words">{{ $message['plain'] }}</pre></details>@endif
            @elseif($message['plain'] !== '')
                <pre class="whitespace-pre-wrap break-words">{{ $message['plain'] }}</pre>
            @else
                <x-ui.empty-state title="Содержимое письма недоступно" description="Текст отсутствует или имеет неподдерживаемый формат." />
            @endif
        </x-ui.card>
        @if(count($message['attachments']))
            <x-ui.card><h2 class="mb-3 font-semibold">Вложения: {{ count($message['attachments']) }}</h2>@include('admin.gmail.attachments')</x-ui.card>
        @endif
        @if($canImport)
            <x-ui.card id="attachment-setup">
                <h2 class="font-semibold">Настройка импорта</h2>
                <p class="mt-2 text-sm text-muted">На следующем шаге проверьте образец и сопоставление столбцов перед сохранением. Для каждого поставщика допускается одно подходящее вложение в письме. При неоднозначном выборе уточните фильтр имени файла.</p>
                <form method="post" action="{{ route('admin.gmail.import-profile', $message['id']) }}" class="mt-4 grid items-end gap-3 md:grid-cols-3 [&>div]:min-w-0" data-gmail-action>
                    @csrf <x-mode-version />
                    <x-ui.select name="part_id" label="Вложение" required>
                        <option value="">Выберите вложение</option>
                        @foreach($message['attachments'] as $attachment)<option value="{{ $attachment['part_id'] }}" @selected(old('part_id', $selectedPart) === $attachment['part_id'])>{{ $attachment['name'] }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.select name="supplier_organization_id" label="Поставщик" required :autofocus="$selectedPart !== null">
                        <option value="">Выберите поставщика</option>
                        @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) old('supplier_organization_id') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.button>Проверить профиль</x-ui.button>
                </form>
            </x-ui.card>
            <x-ui.card data-gmail-progress data-status-url="{{ route('admin.gmail.import-status', $message['id']) }}" data-active="{{ $active ? 'true' : 'false' }}">
                <form method="post" action="{{ route('admin.gmail.replay', $message['id']) }}" class="flex flex-wrap gap-3" data-gmail-action>
                    @csrf <x-mode-version />
                    <x-ui.button>Повторить импорт</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('admin.gmail.show', $message['id'])">Обновить статус</x-ui.button>
                </form>
                <p class="mt-3 text-sm text-warning" data-gmail-intake-reason>{{ $message['intake_reason'] ?? '' }}</p>
                <p class="mt-3 text-sm" role="status" data-gmail-poll-status>{{ $active ? 'Импорт обрабатывается…' : 'Статус импорта обновлён.' }}</p>
                <p class="mt-3 text-sm text-muted" data-gmail-loading hidden role="status">Загрузка…</p>
            </x-ui.card>
        @endif
    </div>
@endsection
