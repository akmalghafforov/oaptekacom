<!doctype html>
<html lang="ru">
<head><meta charset="UTF-8"><style>
@page { margin: 34px 36px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #172b26; }
a { color: #{{ $brand['emerald'] }}; text-decoration: none; }
.brand { width: 100%; margin-bottom: 18px; }
.brand td { border: 0; padding: 0; vertical-align: middle; }
.logo { width: 178px; height: auto; }
h1 { color: #{{ $brand['emerald'] }}; font-size: 22px; margin: 0 0 16px; }
p { margin: 6px 0; }
.details { background: #f3f7f5; padding: 10px 14px; margin-bottom: 18px; }
.label { color: #52665e; }
.notice { color: #52665e; margin-bottom: 12px; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
th, td { border-bottom: 1px solid #d1ded9; padding: 9px 6px; vertical-align: top; overflow-wrap: break-word; }
th { text-align: left; background: #{{ $brand['emerald'] }}; color: #ffffff; font-weight: bold; }
.alternate td { background: #f3f7f5; }
.amount { text-align: right; }
.summary { page-break-inside: avoid; margin-top: 18px; }
.total { background: #{{ $brand['paleEmerald'] }}; padding: 12px 14px; text-align: right; font-weight: bold; font-size: 14px; }
.invitation { background: #{{ $brand['paleEmerald'] }}; padding: 14px; margin-top: 12px; page-break-inside: avoid; }
</style></head>
<body>
<table class="brand"><tr><td><img class="logo" src="{{ $logo }}" alt="OAPTEKA"></td><td class="amount"><a href="{{ $brand['websiteUrl'] }}">{{ $brand['website'] }}</a></td></tr></table>
<h1>Предварительная заявка</h1>
<div class="details">
<p><span class="label">Покупатель:</span> {{ $buyer }}</p>
<p><span class="label">Поставщик:</span> {{ $supplier }}</p>
<p><span class="label">Дата:</span> {{ $generatedAt }}</p>
</div>
@if($excluded > 0)<p class="notice">Исключено недоступных позиций: {{ $excluded }}. Они сохранены в корзине.</p>@endif
<table><thead><tr><th style="width: 46%">Товар</th><th class="amount" style="width: 10%">Кол-во</th><th class="amount" style="width: 19%">Цена, TJS</th><th class="amount" style="width: 25%">Сумма, TJS</th></tr></thead><tbody>
@foreach($items as $item)
<tr @class(['alternate' => $loop->even])><td>{{ $item['name'] }}</td><td class="amount">{{ $item['quantity'] }}</td><td class="amount">{{ $item['price'] }}</td><td class="amount">{{ $item['total'] }}</td></tr>
@endforeach
</tbody></table>
<div class="summary">
<div class="total">Итого: {{ $total }} TJS</div>
<div class="invitation">{!! str_replace(e($brand['email']), '<a href="mailto:'.e($brand['email']).'">'.e($brand['email']).'</a>', e($brand['invitation'])) !!}</div>
</div>
</body></html>
