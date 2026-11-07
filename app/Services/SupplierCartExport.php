<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Organization;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class SupplierCartExport
{
    /** @return array{logoPath: string, website: string, websiteUrl: string, invitation: string, email: string, emerald: string, paleEmerald: string} */
    private function branding(): array
    {
        return [
            'logoPath' => public_path('images/oapteka-logo-header.png'),
            'website' => 'oapteka.app',
            'websiteUrl' => 'https://oapteka.app',
            'invitation' => 'Отправьте ваш прайс-лист на upload@oapteka.app.',
            'email' => 'upload@oapteka.app',
            'emerald' => '047857',
            'paleEmerald' => 'ECFDF5',
        ];
    }

    public function __construct(private SupplierDiscountPrice $prices, private CartMaintenance $maintenance) {}

    /** @return array{excluded: int, buyer: string, supplier: string, generatedAt: string, items: array<int, array{name: string, quantity: int, price: string, total: string}>, total: string} */
    public function data(User $user, Organization $supplier): array
    {
        return $this->maintenance->transaction($user, [$supplier->id], fn (): array => $this->validatedData($user, $supplier->fresh()));
    }

    private function validatedData(User $user, Organization $supplier): array
    {
        abort_unless($user->canBuy(), 403);
        abort_if($supplier->price_list_hidden, 404);
        $items = CartItem::query()->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))
            ->where(fn ($query) => $query->whereHas('offer', fn ($query) => $query->where('organization_id', $supplier->id))
                ->orWhereHas('promotionItem.promotion', fn ($query) => $query->where('organization_id', $supplier->id)))
            ->with('offer.organization', 'promotionItem.promotion.organization')->orderBy('id')->lockForUpdate()->get();
        abort_if($items->isEmpty(), 404);
        $excluded = $items->count() - $this->maintenance->eligible($items)->count();
        $items = $this->maintenance->eligible($items);
        abort_if($items->isEmpty(), 422, 'Нет доступных позиций для отправки.');
        $rows = $items->map(fn (CartItem $item): array => [
            'name' => $item->snapshot['medicine'], 'quantity' => (int) $item->quantity,
            'price' => (string) $item->unit_price, 'total' => $this->prices->lineTotal($item->quantity, (string) $item->unit_price),
        ])->all();

        return [
            'buyer' => $user->activeOrganization()?->name ?? $user->name, 'supplier' => $supplier->name,
            'excluded' => $excluded, 'generatedAt' => now('Asia/Dushanbe')->format('d.m.Y H:i'), 'items' => $rows,
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
        $brand = $this->branding();
        $logo = 'data:image/png;base64,'.base64_encode(file_get_contents($brand['logoPath']));
        $pdf->loadHtml(view('orders.supplier-cart-pdf', [...$data, 'brand' => $brand, 'logo' => $logo])->render(), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    public function excel(User $user, Organization $supplier): Spreadsheet
    {
        $data = $this->data($user, $supplier);
        $brand = $this->branding();
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Заявка');
        foreach (['Покупатель: '.$data['buyer'], 'Поставщик: '.$data['supplier'], 'Дата: '.$data['generatedAt'].' · Исключено недоступных позиций: '.$data['excluded']] as $index => $text) {
            $sheet->setCellValueExplicit('A'.($index + 2), $text, DataType::TYPE_STRING);
            $sheet->mergeCells('A'.($index + 2).':D'.($index + 2));
        }
        $sheet->setShowGridlines(false);
        $sheet->setPrintGridlines(false);
        $spreadsheet->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(11)->getColor()->setRGB('172B26');
        $sheet->mergeCells('C1:D1');
        $sheet->setCellValue('C1', $brand['website']);
        $sheet->getCell('C1')->getHyperlink()->setUrl($brand['websiteUrl']);
        $sheet->getStyle('C1')->getAlignment()->setHorizontal('right');
        $sheet->getStyle('C1')->getFont()->getColor()->setRGB($brand['emerald']);
        $sheet->getRowDimension(1)->setRowHeight(54);
        $drawing = new Drawing;
        $drawing->setName('OAPTEKA');
        $drawing->setDescription('OAPTEKA');
        $drawing->setPath($brand['logoPath']);
        $drawing->setHeight(48);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetY(6);
        $drawing->setWorksheet($sheet);
        $sheet->mergeCells('A5:D5');
        $sheet->setCellValue('A5', 'Предварительная заявка');
        $sheet->getRowDimension(5)->setRowHeight(36);
        $sheet->getStyle('A5')->getFont()->setSize(18)->setBold(true)->getColor()->setRGB($brand['emerald']);
        foreach ([2, 3, 4] as $row) {
            $sheet->getRowDimension($row)->setRowHeight(12 + 17 * ceil(mb_strlen($sheet->getCell("A{$row}")->getValue()) / 90));
            $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F7F5');
        }
        $sheet->fromArray(['Товар', 'Количество', 'Цена, TJS', 'Сумма, TJS'], null, 'A6');
        foreach ($data['items'] as $index => $item) {
            $row = $index + 7;
            $lineCount = array_sum(array_map(fn (string $line): int => max(1, (int) ceil(mb_strlen($line) / 45)), explode("\n", $item['name'])));
            $sheet->getRowDimension($row)->setRowHeight(12 + 17 * $lineCount);
            if ($index % 2 === 1) {
                $sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F7F5');
            }
            $sheet->setCellValueExplicit("A{$row}", $item['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", $item['quantity'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("C{$row}", (float) $item['price'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("D{$row}", (float) $item['total'], DataType::TYPE_NUMERIC);
        }
        $totalRow = count($data['items']) + 7;
        $sheet->setCellValue("C{$totalRow}", 'Итого, TJS');
        $sheet->setCellValueExplicit("D{$totalRow}", (float) $data['total'], DataType::TYPE_NUMERIC);
        $sheet->getStyle("C7:D{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A7:A{$totalRow}")->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension('A')->setWidth(58);
        foreach (['B', 'C', 'D'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(22);
        }
        foreach (['A6:D6', "C{$totalRow}:D{$totalRow}"] as $range) {
            $sheet->getStyle($range)->getFont()->setBold(true);
        }
        $sheet->getStyle("A1:D{$totalRow}")->getAlignment()->setVertical('center');
        $sheet->getStyle("B7:D{$totalRow}")->getAlignment()->setHorizontal('right');
        $sheet->getStyle('A6:D6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brand['emerald']);
        $sheet->getStyle('A6:D6')->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getRowDimension(6)->setRowHeight(28);
        $sheet->getStyle("A{$totalRow}:D{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brand['paleEmerald']);
        $sheet->getRowDimension($totalRow)->setRowHeight(32);
        $invitationRow = $totalRow + 2;
        $sheet->mergeCells("A{$invitationRow}:D{$invitationRow}");
        $sheet->setCellValue("A{$invitationRow}", $brand['invitation']);
        $sheet->getCell("A{$invitationRow}")->getHyperlink()->setUrl('mailto:'.$brand['email']);
        $sheet->getStyle("A{$invitationRow}:D{$invitationRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brand['paleEmerald']);
        $sheet->getStyle("A{$invitationRow}")->getFont()->getColor()->setRGB($brand['emerald']);
        $sheet->getStyle("A{$invitationRow}")->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->getRowDimension($invitationRow)->setRowHeight(36);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(6, 6)
            ->setPrintArea("A1:D{$invitationRow}");
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.35)->setRight(0.35);
        $sheet->freezePane('B7');

        return $spreadsheet;
    }
}
