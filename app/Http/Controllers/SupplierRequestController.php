<?php

namespace App\Http\Controllers;

use App\Models\SupplierRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierRequestController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate(['q' => 'nullable|string|max:100']);
        $search = $validated['q'] ?? null;
        $supplierRequests = SupplierRequest::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->when($search, fn (Builder $query): Builder => $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('supplier_name', 'like', "%{$search}%")
                    ->orWhereHas('items', fn (Builder $itemQuery): Builder => $itemQuery->where('product_name', 'like', "%{$search}%"));
            }))
            ->latest('shared_at')
            ->paginate(12)
            ->withQueryString();

        return view('supplier-requests.index', compact('supplierRequests', 'search'));
    }

    public function show(SupplierRequest $supplierRequest, Request $request): View
    {
        $this->ensureCreator($supplierRequest, $request);

        return view('supplier-requests.show', ['supplierRequest' => $supplierRequest->load('items')]);
    }

    public function print(SupplierRequest $supplierRequest, Request $request): View
    {
        $this->ensureCreator($supplierRequest, $request);

        return view('supplier-requests.print', ['supplierRequest' => $supplierRequest->load('items')]);
    }

    public function excel(SupplierRequest $supplierRequest, Request $request): StreamedResponse
    {
        $this->ensureCreator($supplierRequest, $request);
        $supplierRequest->load('items');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Заявка');
        $sheet->fromArray(['Поставщик', $supplierRequest->supplier_name], null, 'A1');
        $sheet->fromArray(['Дата', $supplierRequest->shared_at->format('d.m.Y H:i')], null, 'A2');
        $sheet->fromArray(['Товар', 'Количество', 'Цена за единицу, TJS', 'Сумма, TJS'], null, 'A4');

        foreach ($supplierRequest->items as $index => $item) {
            $sheet->fromArray([$item->product_name, $item->quantity, (float) $item->unit_price, (float) $item->line_total], null, 'A'.($index + 5));
        }

        $totalRow = $supplierRequest->items->count() + 5;
        $sheet->setCellValue("C{$totalRow}", 'Итого');
        $sheet->setCellValue("D{$totalRow}", (float) $supplierRequest->total);
        $sheet->getStyle('A4:D4')->getFont()->setBold(true);
        $sheet->getStyle("C{$totalRow}:D{$totalRow}")->getFont()->setBold(true);
        foreach (range('A', 'D') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'zayavka-'.Str::slug($supplierRequest->supplier_name).'-'.$supplierRequest->id.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function ensureCreator(SupplierRequest $supplierRequest, Request $request): void
    {
        abort_unless($supplierRequest->user_id === $request->user()->id, 404);
    }
}
