@props(['text', 'query' => ''])
@php
    $searchTerm = trim($query);
    $parts = $searchTerm === ''
        ? [$text]
        : preg_split('/('.preg_quote($searchTerm, '/').')/iu', $text, flags: PREG_SPLIT_DELIM_CAPTURE);
@endphp
<?php foreach ($parts as $index => $part): ?><?php if ($index % 2 === 1): ?><mark class="rounded-sm bg-warning-surface-strong text-ink">{{ $part }}</mark><?php else: ?>{{ $part }}<?php endif; ?><?php endforeach; ?>
