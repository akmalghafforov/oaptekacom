<ul class="space-y-3">
    @foreach($message['attachments'] as $attachment)
        <li class="break-words" data-attachment-part="{{ $attachment['part_id'] }}">
            <p>{{ $attachment['name'] }} · {{ number_format($attachment['size'], 0, '.', ' ') }} байт</p>
            @if(isset($attachment['outcome']))
                <span data-attachment-status><x-ui.status-badge :status="$attachment['outcome']['status']" :label="$attachment['outcome']['label']" /></span>
                <p class="text-sm text-muted" data-attachment-reason>{{ $attachment['outcome']['reason'] }}</p>
                <p class="text-sm" data-attachment-duplicate @if(!$attachment['outcome']['duplicate']) hidden @endif>Дубликат: используется существующий импорт.</p>
                <a class="inline-flex min-h-10 items-center text-brand underline" data-attachment-import href="{{ $attachment['outcome']['url'] ?? '#' }}" @if(!$attachment['outcome']['url']) hidden @endif>{{ $attachment['outcome']['status'] === 'failed' ? 'Открыть прайс-лист и повторить' : 'Открыть импорт' }} #{{ $attachment['outcome']['import_id'] }}</a>
            @endif
            @if($canImport && $attachment['part_id'] !== '')
                <a class="inline-flex min-h-10 items-center text-brand underline" href="{{ route('admin.gmail.show', ['message' => $message['id'], 'part_id' => $attachment['part_id']]) }}#attachment-setup">Настроить импорт</a>
            @endif
        </li>
    @endforeach
</ul>
