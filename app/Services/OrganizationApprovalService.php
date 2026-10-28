<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationApprovalService
{
    public function approve(Organization $organization, User $actor, ?string $telegramId = null): Organization
    {
        return DB::transaction(function () use ($organization, $actor, $telegramId): Organization {
            $pendingOrganization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organization->id);
            if ($pendingOrganization->status !== 'pending') {
                throw ValidationException::withMessages(['organization' => 'Организация больше не ожидает одобрения. Обновите страницу.']);
            }
            $isPharmacy = $pendingOrganization->type === OrganizationType::Pharmacy;
            if (! $isPharmacy && $pendingOrganization->setup_required) {
                throw ValidationException::withMessages(['organization' => 'Владелец должен сохранить данные организации в профиле рабочего режима.']);
            }

            $organizationUser = $pendingOrganization->assignedUsers()
                ->when(! $isPharmacy, fn ($query) => $query->where(fn ($query) => $query->whereNotNull('phone_verified_at')->orWhere('users.role', UserRole::Admin)))
                ->lockForUpdate()
                ->first();
            $sourceSupplier = $pendingOrganization->sourceSupplierOrganization()->lockForUpdate()->first();
            if (! $isPharmacy && ! $organizationUser) {
                throw ValidationException::withMessages(['organization' => 'Для одобрения организации необходимо подтверждение телефона по SMS.']);
            }
            if (! $isPharmacy && $sourceSupplier && $sourceSupplier->status !== 'active') {
                throw ValidationException::withMessages(['organization' => 'Сначала одобрите связанного поставщика: '.$sourceSupplier->name.'.']);
            }

            $before = $pendingOrganization->only('status');
            $pendingOrganization->update($isPharmacy ? ['status' => 'active', 'setup_required' => false] : ['status' => 'active']);
            $organizationUser?->forceFill(array_filter([
                'approved_at' => now(),
                'subscription_plan' => $organizationUser->isCustomer() ? SubscriptionPlan::Free : null,
            ], fn (mixed $value): bool => $value !== null))->save();
            app(AuditLogger::class)->log('organization.approved', $pendingOrganization, $before, $pendingOrganization->only('status'), $actor);

            app(TelegramSupplierReviews::class)->complete($pendingOrganization, 'approved', $actor, $telegramId);

            return $pendingOrganization;
        });
    }
}
