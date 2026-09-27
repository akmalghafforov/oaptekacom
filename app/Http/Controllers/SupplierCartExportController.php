<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\SupplierCartExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierCartExportController extends Controller
{
    public function pdf(Organization $supplier, Request $request, SupplierCartExport $export): Response
    {
        return response($export->pdf($request->user(), $supplier), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="oapteka-supplier-'.$supplier->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function excel(Organization $supplier, Request $request, SupplierCartExport $export): StreamedResponse
    {
        $spreadsheet = $export->excel($request->user(), $supplier);

        return response()->streamDownload(function () use ($spreadsheet): void {
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, 'oapteka-supplier-'.$supplier->id.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
