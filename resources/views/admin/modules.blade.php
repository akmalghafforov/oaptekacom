@extends('layouts.app')
@section('content')
<x-ui.page-header title="Модули" description="Отключённые модули недоступны пользователям, включая прямые URL." />
<x-ui.card><div class="table-wrap"><table class="data-table"><thead><tr><th>Модуль</th><th>Статус</th><th></th></tr></thead><tbody>@forelse($modules as $module)<tr><td>{{ $module->key }}</td><td>{{ $module->enabled ? 'Включён' : 'Отключён' }}</td><td><form method="post" action="{{ route('admin.modules.update',$module) }}">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $module->enabled ? 0 : 1 }}"><x-ui.button variant="secondary">{{ $module->enabled ? 'Отключить' : 'Включить' }}</x-ui.button></form></td></tr>@empty<tr><td colspan="3">Модули появятся после первого запуска миграций.</td></tr>@endforelse</tbody></table></div></x-ui.card>
@endsection
