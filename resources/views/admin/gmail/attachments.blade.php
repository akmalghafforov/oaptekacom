<ul class="space-y-3">
    @foreach($message['attachments'] as $attachment)
        <li class="break-words" data-attachment-part="{{ $attachment['part_id'] }}">
            <p>{{ $attachment['name'] }} · {{ number_format($attachment['size'], 0, '.', ' ') }} байт</p>
            @unless($inboxAttachments ?? false)
                @include('admin.gmail.outcome')
            @endunless
            @if($canImport && $attachment['part_id'] !== '')
                <a class="inline-flex min-h-10 items-center text-brand underline" href="{{ route('admin.gmail.show', ['message' => $message['id'], 'part_id' => $attachment['part_id']]) }}#attachment-setup">Настроить импорт</a>
            @endif
        </li>
    @endforeach
</ul>
