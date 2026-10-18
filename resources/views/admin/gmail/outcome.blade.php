@if(isset($attachment['outcome']))
                <span data-attachment-status><x-ui.status-badge :status="$attachment['outcome']['status']" :label="$attachment['outcome']['label']" /></span>
                <p class="text-sm text-muted" data-attachment-reason>{{ $attachment['outcome']['reason'] }}</p>
                <p class="text-sm" data-attachment-duplicate @if(!$attachment['outcome']['duplicate']) hidden @endif>Дубликат: используется существующий импорт.</p>
                <a class="inline-flex min-h-10 items-center text-brand underline" data-attachment-import href="{{ $attachment['outcome']['url'] ?? '#' }}" @if(!$attachment['outcome']['url']) hidden @endif>{{ $attachment['outcome']['status'] === 'failed' ? 'Открыть прайс-лист и повторить' : 'Открыть импорт' }} #{{ $attachment['outcome']['import_id'] }}</a>
            @endif
