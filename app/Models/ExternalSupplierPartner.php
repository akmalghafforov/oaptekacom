<?php

namespace App\Models;

use Database\Factories\ExternalSupplierPartnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalSupplierPartner extends Model
{
    /** @use HasFactory<ExternalSupplierPartnerFactory> */
    use HasFactory;

    protected $guarded = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function directoryMetadata(array $payload): array
    {
        $metadata = array_diff_key($payload, array_flip(['source', 'external_id', 'name', 'phones']));
        if (! is_string($metadata['city'] ?? null) || trim($metadata['city']) === '') {
            $metadata['city'] = $this->metadata['city'] ?? null;
        }

        return $metadata;
    }

    protected function casts(): array
    {
        return ['phones' => 'array', 'metadata' => 'array', 'discovered_at' => 'datetime', 'profile_configuration' => 'array', 'profile_active' => 'boolean'];
    }
}
