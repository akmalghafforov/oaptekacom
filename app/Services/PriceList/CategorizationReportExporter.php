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
            fputcsv($stream, ['source_row', 'source_filename', 'source_worksheet', 'original_product_name', 'normalized_product_name', 'category', 'keyword', 'matched_source_text', 'confidence', 'status', 'rule_evidence']);
            $import->rows()->orderBy('source_row')->each(function ($row) use ($stream): void {
                fputcsv($stream, [$row->source_row, $row->source_filename, $row->source_worksheet, $row->original_product_name, $row->normalized_product_name, $row->assigned_category, $row->matched_keyword, $row->matched_source_text, $row->categorization_confidence, $row->categorization_status, json_encode($row->categorization_evidence, JSON_UNESCAPED_UNICODE)]);
            });
            fclose($stream);
        }, 'categorization-review-'.$import->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
