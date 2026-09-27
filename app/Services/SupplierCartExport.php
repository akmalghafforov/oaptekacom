<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Organization;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class SupplierCartExport
{
    public function __construct(private SupplierDiscountPrice $prices) {}

    /** @return array{buyer: string, supplier: string, generatedAt: string, items: array<int, array{name: string, quantity: int, price: string, total: string}>, total: string} */
    public function data(User $user, Organization $supplier): array
    {
        abort_unless($user->canBuy(), 403);
        $items = CartItem::query()->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
            ->whereHas('offer', fn ($query) => $query->where('organization_id', $supplier->id))->orderBy('id')->get();
        abort_if($items->isEmpty(), 404);
        $rows = $items->map(fn (CartItem $item): array => [
            'name' => $item->snapshot['medicine'], 'quantity' => (int) $item->quantity,
            'price' => (string) $item->unit_price, 'total' => $this->prices->lineTotal($item->quantity, (string) $item->unit_price),
        ])->all();

        return [
            'buyer' => $user->organization?->name ?? $user->name, 'supplier' => $supplier->name,
            'generatedAt' => now('Asia/Dushanbe')->format('d.m.Y H:i'), 'items' => $rows,
            'total' => array_reduce($rows, fn (string $total, array $row): string => bcadd($total, $row['total'], 2), '0.00'),
        ];
    }

    public function pdf(User $user, Organization $supplier): string
    {
        $data = $this->data($user, $supplier);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('orders.supplier-cart-pdf', $data)->render(), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    public function excel(User $user, Organization $supplier): Spreadsheet
    {
        $data = $this->data($user, $supplier);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Заявка');
        foreach (['OAPTEKA - Предварительная заявка', 'Покупатель: '.$data['buyer'], 'Поставщик: '.$data['supplier'], 'Дата: '.$data['generatedAt']] as $index => $text) {
            $sheet->setCellValueExplicit('A'.($index + 1), $text, DataType::TYPE_STRING);
            $sheet->mergeCells('A'.($index + 1).':D'.($index + 1));
        }
        $sheet->fromArray(['Товар', 'Количество', 'Цена, TJS', 'Сумма, TJS'], null, 'A6');
        foreach ($data['items'] as $index => $item) {
            $row = $index + 7;
            $sheet->setCellValueExplicit("A{$row}", $item['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", $item['quantity'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("C{$row}", (float) $item['price'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("D{$row}", (float) $item['total'], DataType::TYPE_NUMERIC);
        }
        $totalRow = count($data['items']) + 7;
        $sheet->setCellValue("C{$totalRow}", 'Итого, TJS');
        $sheet->setCellValueExplicit("D{$totalRow}", (float) $data['total'], DataType::TYPE_NUMERIC);
        $sheet->getStyle("C7:D{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A1:D{$totalRow}")->getAlignment()->setVertical('top');
        $sheet->getStyle("A7:A{$totalRow}")->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(65);
        foreach (['B', 'C', 'D'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(20);
        }
        foreach (['A1:D1', 'A6:D6', "C{$totalRow}:D{$totalRow}"] as $range) {
            $sheet->getStyle($range)->getFont()->setBold(true);
        }
        $sheet->freezePane('B7');

        return $spreadsheet;
    }
}
