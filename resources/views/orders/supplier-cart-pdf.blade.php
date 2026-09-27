<!doctype html>
<html lang="ru">
<head><meta charset="UTF-8"><style>
@page { margin: 32px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #172b26; }
h1 { color: #047857; font-size: 22px; margin-bottom: 4px; }
p { margin: 6px 0; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 18px; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
th, td { border-bottom: 1px solid #d1ded9; padding: 8px 5px; vertical-align: top; overflow-wrap: break-word; }
th { text-align: left; background: #ecfdf5; }
.amount { text-align: right; }
.total { margin-top: 16px; text-align: right; font-weight: bold; }
</style></head>
<body>
<h1>OAPTEKA</h1><p>Предварительная заявка</p>
<p>Покупатель: {{ $buyer }}</p><p>Поставщик: {{ $supplier }}</p><p>Дата: {{ $generatedAt }}</p>
<table><thead><tr><th style="width: 49%">Товар</th><th style="width: 11%">Кол-во</th><th style="width: 18%">Цена, TJS</th><th style="width: 22%">Сумма, TJS</th></tr></thead><tbody>
@foreach($items as $item)
<tr><td>{{ $item['name'] }}</td><td class="amount">{{ $item['quantity'] }}</td><td class="amount">{{ $item['price'] }}</td><td class="amount">{{ $item['total'] }}</td></tr>
@endforeach
</tbody></table><p class="total">Итого: {{ $total }} TJS</p>
</body></html>
