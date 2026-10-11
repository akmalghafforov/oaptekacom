@props(['row', 'checked' => false])
<label class="inline-flex min-h-10 cursor-pointer items-center gap-2 whitespace-nowrap text-sm font-medium text-content">
    <input type="radio" name="data_row" value="{{ $row }}" @checked($checked) {{ $attributes->class('size-4 accent-accent-strong') }}>
    <span>С строки {{ $row }}</span>
</label>
