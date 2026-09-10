@extends('layouts.app')
@section('content')
<x-ui.page-header title="Пользователи" description="Управление доступом к платформе."><x-slot:actions><a class="text-sm font-semibold text-brand-700 hover:underline" href="{{ route('admin.index') }}">Операционный центр</a></x-slot:actions></x-ui.page-header>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Пользователь</th><th>Организация</th><th>Роль</th><th class="text-right">Действие</th></tr></thead><tbody>@foreach($users as $user)<tr><td class="font-medium">{{ $user->name }}</td><td>{{ $user->organization?->name ?? '—' }}</td><td>{{ $user->role }}</td><td class="text-right">@if(!$user->isAdmin())<form method="post" action="{{ route('admin.block', $user) }}">@csrf <x-ui.button :variant="$user->is_blocked ? 'secondary' : 'danger'">{{ $user->is_blocked ? 'Разблокировать' : 'Заблокировать' }}</x-ui.button></form>@endif</td></tr>@endforeach</tbody></table></div>
<div class="mt-6">{{ $users->links() }}</div>
@endsection
