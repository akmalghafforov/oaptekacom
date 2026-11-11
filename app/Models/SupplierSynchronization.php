<?php

namespace App\Models;

use Database\Factories\SupplierSynchronizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierSynchronization extends Model
{
    /** @use HasFactory<SupplierSynchronizationFactory> */
    use HasFactory;

    protected $guarded = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    /** @return list<string> */
    public function enabledSources(): array
    {
        return collect($this->sources)->filter(fn (array $source): bool => (bool) $source['enabled'])
            ->sortBy('priority')->keys()->values()->all();
    }

    /** @return array<string, array{enabled: bool, priority: int}> */
    public static function defaultSources(): array
    {
        return ['email' => ['enabled' => true, 'priority' => 1], 'icorp' => ['enabled' => true, 'priority' => 2], 'pricelist_tj' => ['enabled' => true, 'priority' => 3]];
    }

    protected function casts(): array
    {
        return ['sources' => 'array', 'automatic_enabled' => 'boolean', 'settings_confirmed' => 'boolean', 'paused' => 'boolean', 'next_check_at' => 'datetime', 'last_activated_at' => 'datetime'];
    }
}
