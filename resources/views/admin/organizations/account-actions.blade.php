@if(! $user->isAdmin() && $user->organization_id === $organization->id)
    <div class="flex flex-wrap items-end gap-3">
        @if($user->isCustomer())
            <x-ui.button variant="secondary" :href="route('admin.subscriptions.create', ['user_id' => $user->id])">Подписка</x-ui.button>
        @endif
        <form method="post" action="{{ route('admin.block', $user) }}">
            @csrf <x-mode-version />
            <x-ui.button :variant="$user->is_blocked ? 'secondary' : 'danger'">{{ $user->is_blocked ? 'Разблокировать' : 'Заблокировать' }}</x-ui.button>
        </form>
        @if(($user->isCustomer() || $user->isWholesaler()) && $user->is_blocked)
            <form method="post" action="{{ route('admin.phone.remediate', $user) }}" class="flex flex-wrap items-end gap-3">
                @csrf <x-mode-version />
                <x-ui.input :id="'account-phone-'.$user->id" name="phone" label="Телефон для восстановления доступа" required placeholder="+992901234567" />
                <x-ui.button variant="secondary">Исправить телефон</x-ui.button>
            </form>
        @endif
    </div>
@endif
