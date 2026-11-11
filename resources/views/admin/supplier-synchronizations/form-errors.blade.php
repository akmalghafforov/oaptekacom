@if($restoring && $errors->any())
    <x-ui.alert type="danger"><ul class="list-inside list-disc">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-ui.alert>
@endif
