<div class="space-y-1 break-words">
    <x-ui.status-badge status="pending" label="Предположение" />
    <p class="font-semibold">{{ $candidate['name'] }}</p>
    <p class="text-sm text-muted">{{ implode('; ', $candidate['evidence']) }}</p>
</div>
