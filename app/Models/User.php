<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password', 'theme', 'active_trade_mode'])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, string> */
    protected $attributes = ['subscription_plan' => 'free'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isWholesaler(): bool
    {
        return $this->role === UserRole::Wholesaler;
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Pharmacy;
    }

    public function canBuy(): bool
    {
        return ! $this->isWholesaler() || $this->active_trade_mode === TradeMode::Buyer;
    }

    public function canSupply(): bool
    {
        return $this->isWholesaler() && $this->active_trade_mode === TradeMode::Supplier;
    }

    public function hasActiveSubscription(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->organization?->status !== 'active') {
            return false;
        }

        return true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'subscription_plan' => SubscriptionPlan::class,
            'active_trade_mode' => TradeMode::class,
            'approved_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'totp_secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'is_blocked' => 'boolean',
            'password_change_required' => 'boolean',
        ];
    }
}
