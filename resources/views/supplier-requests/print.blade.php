<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заявка {{ $supplierRequest->supplier_name }}</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-white p-6 text-ink sm:p-10">
    <main class="mx-auto max-w-3xl">
        <header class="mb-8 border-b border-slate-300 pb-5"><p class="text-sm font-bold text-brand-700">OAPTEKA</p><h1 class="mt-2 text-3xl font-bold">Заявка поставщику</h1><p class="mt-2 text-muted">{{ $supplierRequest->supplier_name }} · {{ $supplierRequest->shared_at->format('d.m.Y H:i') }}</p></header>
        <table class="w-full border-collapse text-left text-sm"><thead><tr class="border-b-2 border-slate-300"><th class="py-3">Товар</th><th class="py-3">Кол-во</th><th class="py-3">Цена</th><th class="py-3 text-right">Сумма</th></tr></thead><tbody>@foreach($supplierRequest->items as $item)<tr class="border-b border-slate-200"><td class="py-3">{{ $item->product_name }}</td><td class="py-3">{{ $item->quantity }}</td><td class="py-3">{{ $item->unit_price }} TJS</td><td class="py-3 text-right">{{ $item->line_total }} TJS</td></tr>@endforeach</tbody><tfoot><tr><td colspan="3" class="pt-5 text-right text-lg font-bold">Итого</td><td class="pt-5 text-right text-lg font-bold">{{ $supplierRequest->total }} TJS</td></tr></tfoot></table>
        <p class="mt-10 text-xs text-muted">Сформировано в OAPTEKA. Для сохранения выберите «Печать» → «Сохранить как PDF».</p>
    </main>
    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
