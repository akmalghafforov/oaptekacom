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
        if ($this->usesExclusiveSource()) {
            return in_array($this->selected_source, array_keys(self::defaultSources()), true) ? [$this->selected_source] : [];
        }

        return collect($this->sources)->filter(fn (array $source): bool => (bool) $source['enabled'])
            ->sortBy('priority')->keys()->values()->all();
    }

    public function usesExclusiveSource(): bool
    {
        return ! $this->usesNewestAvailable() && ($this->selected_source !== null || ($this->source_conversion !== null && ! isset($this->source_conversion['rolled_back_at'])) || config('price-list-imports.exclusive_sources'));
    }

    public function usesNewestAvailable(): bool
    {
        return $this->selection_mode === 'newest_available';
    }

    /** @return array<string, array{enabled: bool, priority: int}> */
    public static function defaultSources(): array
    {
        return ['email' => ['enabled' => true, 'priority' => 1], 'icorp' => ['enabled' => true, 'priority' => 2], 'pricelist_tj' => ['enabled' => true, 'priority' => 3]];
    }

    protected function casts(): array
    {
        return ['source_conversion' => 'array', 'sources' => 'array', 'automatic_enabled' => 'boolean', 'settings_confirmed' => 'boolean', 'paused' => 'boolean', 'next_check_at' => 'datetime', 'last_activated_at' => 'datetime'];
    }
}
