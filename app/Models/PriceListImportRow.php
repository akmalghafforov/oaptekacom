<?php

namespace App\Models;

use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use Database\Factories\PriceListImportRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListImportRow extends Model
{
    /** @use HasFactory<PriceListImportRowFactory> */
    use HasFactory;

    protected $guarded = [];

    public function import(): BelongsTo
    {
        return $this->belongsTo(PriceListImport::class, 'price_list_import_id');
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    protected function casts(): array
    {
        return ['raw_values' => 'array', 'parsed_values' => 'array', 'errors' => 'array', 'warnings' => 'array', 'categorization_evidence' => 'array', 'disposition' => PriceListRowDisposition::class, 'planned_action' => PriceListRowAction::class];
    }
}
