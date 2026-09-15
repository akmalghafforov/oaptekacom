<?php

namespace App\Services\PriceList;

use App\Models\PriceListImport;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategorizationReportExporter
{
    public function export(PriceListImport $import): StreamedResponse
    {
        return response()->streamDownload(function () use ($import): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['source_row', 'source_filename', 'source_worksheet', 'original_product_name', 'normalized_product_name', 'category_codes', 'category_labels', 'confidence', 'status', 'category_evidence']);
            $import->rows()->orderBy('source_row')->each(function ($row) use ($stream): void {
                $accepted = collect($row->category_candidates ?? [])->where('decision', 'accepted');
                fputcsv($stream, [$row->source_row, $row->source_filename, $row->source_worksheet, $row->original_product_name, $row->normalized_product_name, implode(', ', $row->assigned_categories ?? []), $accepted->pluck('label')->implode(', '), $row->categorization_confidence, $row->categorization_status, json_encode($row->category_candidates, JSON_UNESCAPED_UNICODE)]);
            });
            fclose($stream);
        }, 'categorization-review-'.$import->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
