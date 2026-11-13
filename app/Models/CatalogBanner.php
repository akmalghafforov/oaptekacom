<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogBanner extends Model
{
    use HasFactory;

    public const IMAGE_SIZES = ['desktop' => [1440, 480], 'tablet' => [1024, 512], 'mobile' => [720, 540]];

    protected $guarded = ['id', 'impressions'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'duration_seconds' => 'integer', 'impressions' => 'integer'];
    }

    #[Scope]
    protected function eligible(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function whatsappUrl(): string
    {
        return 'https://wa.me/'.$this->whatsapp_number.($this->whatsapp_message ? '?text='.rawurlencode($this->whatsapp_message) : '');
    }
}
