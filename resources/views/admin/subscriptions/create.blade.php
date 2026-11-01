@extends('layouts.app')

@section('content')
<x-ui.page-header title="Выдать подписку" description="Платный тариф для аккаунта аптеки. Выдача сохраняется в истории.">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.subscriptions.index')">К подпискам</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.card>
    <form method="post" action="{{ route('admin.subscriptions.store') }}" class="grid gap-4 md:grid-cols-2">
        @csrf
        <x-mode-version />
        <div class="md:col-span-2">
            <x-ui.select name="user_id" label="Аккаунт аптеки" required>
                <option value="">Выберите аккаунт</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" @selected(old('user_id', request('user_id')) == $user->id)>{{ $user->organization?->name ?: '—' }} · {{ $user->name ?: '—' }} · {{ $user->phone ?: '—' }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <x-ui.select name="plan" label="Платный тариф" required>
            <option value="">Выберите тариф</option>
            @foreach($plans as $plan)
                <option value="{{ $plan->value }}" @selected(old('plan') === $plan->value)>{{ $plan->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="term" label="Срок" required>
            <option value="">Выберите срок</option>
            @foreach($terms as $term)
                <option value="{{ $term->value }}" @selected(old('term') === $term->value)>{{ $term->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input name="ends_on" type="date" label="Дата окончания" hint="Только для произвольного срока; дата включается в стоимость." min="{{ now('Asia/Dushanbe')->toDateString() }}" />
        <div class="md:col-span-2">
            <x-ui.button>Выдать подписку</x-ui.button>
        </div>
    </form>
</x-ui.card>
@endsection
