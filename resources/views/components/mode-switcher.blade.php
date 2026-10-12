@props(['inDrawer' => false])
@if(config('account-modes.enabled'))
@php
    $user = auth()->user();
    $assignments = $user->roleAssignments;
@endphp
<div @class(['w-full', 'border-t border-border pt-3' => ! $inDrawer])>
    <p class="mb-2 text-sm text-muted">Режим: {{ $user->activeMode()->label() }}@if($user->activeOrganization()) · {{ $user->activeOrganization()->name }} · {{ match ($user->activeOrganization()->status) { 'active' => 'Активна', 'blocked' => 'Заблокирована', default => 'Требуется подтверждение' } }}@endif</p>
    @if($assignments->count() > 1)
    <form method="post" action="{{ route('mode.update') }}" class="flex flex-wrap items-end gap-3" data-mode-switcher>
        @csrf @method('PATCH') <x-mode-version />
        <x-ui.select name="mode" label="Рабочий режим">
            @foreach($assignments as $assignment)
                @php($mode = \App\Enums\AccountMode::forRole($assignment->role))
                <option value="{{ $mode->value }}" @selected($user->activeMode() === $mode)>{{ $mode->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.button variant="secondary">Переключить</x-ui.button>
    </form>
    @endif
</div>
@endif
