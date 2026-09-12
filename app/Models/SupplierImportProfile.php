<?php

namespace App\Models;

use App\Enums\SupplierFileType;
use Database\Factories\SupplierImportProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierImportProfile extends Model
{
    /** @use HasFactory<SupplierImportProfileFactory> */
    use HasFactory;

    protected $guarded = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return ['file_type' => SupplierFileType::class, 'is_active' => 'boolean', 'configuration' => 'array', 'sample_metadata' => 'array'];
    }
}
