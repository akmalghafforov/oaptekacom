@extends('layouts.app')
@section('content')
    <x-ui.page-header :title="$message['subject']">
        <x-slot:actions><x-ui.button variant="secondary" :href="route('admin.gmail.index')">К входящим</x-ui.button></x-slot:actions>
    </x-ui.page-header>
    <div class="mt-6 space-y-4">
        <x-ui.card>
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
            <x-ui.card><h2 class="font-semibold">Вложения</h2><ul class="mt-3 space-y-2">@foreach($message['attachments'] as $attachment)<li>{{ $attachment['name'] }} · {{ number_format($attachment['size'], 0, '.', ' ') }} байт</li>@endforeach</ul><p class="mt-3 text-sm text-muted">Скачивание и импорт вложений пока недоступны.</p></x-ui.card>
        @endif
    </div>
@endsection
