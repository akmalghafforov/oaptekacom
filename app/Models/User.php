<?php

namespace App\Models;

use App\Enums\AccountMode;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Services\AccountModeContext;
use App\Services\RoleProvisioningService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Fillable(['name', 'email', 'phone', 'password', 'theme'])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, string> */
    protected $attributes = ['subscription_plan' => 'free'];

    public function save(array $options = []): bool
    {
        if ((! $this->exists || $this->isDirty(['role', 'organization_id'])) && Schema::hasTable('user_role_assignments')) {
            return DB::transaction(function () use ($options): bool {
                $primaryOrganizationChanged = $this->exists && $this->isDirty('organization_id') && ! $this->isDirty('role');
                $saved = parent::save($options);
                if ($saved && $primaryOrganizationChanged) {
                    $this->roleAssignments()->where('role', $this->role)->update(['organization_id' => $this->isAdmin() ? null : $this->organization_id]);
                }
                if ($saved && ($this->isAdmin() || $this->organization_id !== null)) {
                    app(RoleProvisioningService::class)->provision($this);
                }

                return $saved;
            });
        }

        return parent::save($options);
    }

    public function gmailConnection(): HasOne
    {
        return $this->hasOne(GmailConnection::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    public function activeMode(): AccountMode
    {
        $context = app(AccountModeContext::class);

        return config('account-modes.enabled') && $context->userId === $this->id && $context->mode
            ? $context->mode : AccountMode::forRole($this->role);
    }

    public function isAdminMode(): bool
    {
        return $this->activeMode() === AccountMode::Admin;
    }

    public function isSupplierMode(): bool
    {
        return $this->activeMode() === AccountMode::Supplier;
    }

    public function isPharmacyMode(): bool
    {
        return $this->activeMode() === AccountMode::Pharmacy;
    }

    public function activeOrganization(): ?Organization
    {
        $context = app(AccountModeContext::class);
        if (config('account-modes.enabled') && $context->userId === $this->id && $context->mode) {
            return $context->organization;
        }

        return $this->organization;
    }

    public function activeOrganizationId(): ?int
    {
        return $this->activeOrganization()?->id;
    }

    /** @return list<int> */
    public function purchaseOrganizationIds(): array
    {
        $ids = [$this->activeOrganizationId()];
        if ($this->isPharmacyMode() && $this->isWholesaler()) {
            $ids[] = $this->organization_id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function supplierRequests(): HasMany
    {
        return $this->hasMany(SupplierRequest::class);
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
        return config('account-modes.enabled') ? $this->isPharmacyMode() : (! $this->isWholesaler() || $this->active_trade_mode === TradeMode::Buyer);
    }

    public function canSupply(): bool
    {
        return config('account-modes.enabled') ? $this->isSupplierMode() : ($this->isWholesaler() && $this->active_trade_mode === TradeMode::Supplier);
    }

    public function defaultLandingRouteName(): string
    {
        return config('account-modes.enabled') ? ($this->isPharmacyMode() ? 'catalog' : 'dashboard') : (! $this->isAdmin() && $this->canBuy() ? 'catalog' : 'dashboard');
    }

    public function hasUnlimitedSubscriptionAccess(): bool
    {
        return $this->isAdmin() || $this->isWholesaler();
    }

    public function hasActiveSubscription(): bool
    {
        if ($this->is_blocked) {
            return false;
        }

        if ($this->isWholesaler() && ($this->organization?->status !== 'active' || $this->organization?->setup_required)) {
            return false;
        }

        if ($this->isAdminMode()) {
            return $this->hasUnlimitedSubscriptionAccess();
        }

        if ($this->activeOrganization()?->status !== 'active' || $this->activeOrganization()?->setup_required) {
            return false;
        }

        if ($this->hasUnlimitedSubscriptionAccess()) {
            return true;
        }

        return $this->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->whereIn('plan', [SubscriptionPlan::Base, SubscriptionPlan::Premium])
            ->whereDate('starts_on', '<=', now('Asia/Dushanbe')->toDateString())
            ->whereDate('ends_on', '>=', now('Asia/Dushanbe')->toDateString())
            ->exists();
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
