@foreach(array_merge(['tab' => 'external'], request()->only(array_keys(\App\Services\SupplierDirectoryContext::fields()))) as $contextKey => $contextValue)
    <input type="hidden" name="list_context[{{ $contextKey }}]" value="{{ $contextValue }}">
@endforeach
