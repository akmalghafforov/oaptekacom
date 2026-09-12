@props(['row', 'checked' => false])
<label class="inline-flex min-h-10 cursor-pointer items-center gap-2 whitespace-nowrap text-sm font-medium text-slate-700">
    <input type="radio" name="data_row" value="{{ $row }}" @checked($checked) {{ $attributes->class('size-4 accent-brand-600') }}>
    <span>С строки {{ $row }}</span>
</label>
