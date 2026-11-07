<?php

namespace App\Models;

use App\Enums\PromotionConditionMatch;
use App\Enums\PromotionPurchaseScope;
use App\Enums\PromotionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PromotionAttachment::class);
    }

    public function quoteRequests(): HasMany
    {
        return $this->hasMany(PromotionQuoteRequest::class);
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(PromotionPrize::class)->orderBy('id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(PromotionContribution::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(PromotionParticipant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = now('Asia/Dushanbe')->toDateString();

        return $query->where('status', PromotionStatus::Published)->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)
            ->whereHas('organization', fn (Builder $query): Builder => $query->where('status', 'active')->where('setup_required', false)->where('price_list_hidden', false));
    }

    public function isActive(): bool
    {
        $today = now('Asia/Dushanbe')->toDateString();

        return $this->status === PromotionStatus::Published && $this->starts_on->toDateString() <= $today && $this->ends_on->toDateString() >= $today
            && $this->organization->status === 'active' && ! $this->organization->setup_required && ! $this->organization->price_list_hidden;
    }

    protected function casts(): array
    {
        return ['purchase_scope' => PromotionPurchaseScope::class, 'condition_match' => PromotionConditionMatch::class, 'minimum_total' => 'decimal:2', 'minimum_order_value' => 'decimal:2', 'minimum_order_count' => 'integer', 'status' => PromotionStatus::class, 'starts_on' => 'date', 'ends_on' => 'date', 'first_published_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
