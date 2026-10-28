<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAdminOrganizationRequest;
use App\Http\Requests\UpdateOrganizationAccountRequest;
use App\Http\Requests\UpdatePriceListVisibilityRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationApprovalService;
use App\Services\TelegramSupplierReviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrganizationDirectoryController extends Controller
{
    public function pharmacies(Request $request): View
    {
        return $this->index($request, OrganizationType::Pharmacy);
    }

    public function providers(Request $request): View
    {
        return $this->index($request, OrganizationType::Wholesaler);
    }

    public function showPharmacy(Organization $organization): View
    {
        return $this->show($organization, OrganizationType::Pharmacy);
    }

    public function showProvider(Organization $organization): View
    {
        return $this->show($organization, OrganizationType::Wholesaler);
    }

    public function editPharmacy(Organization $organization): View
    {
        return $this->edit($organization, OrganizationType::Pharmacy);
    }

    public function editProvider(Organization $organization): View
    {
        return $this->edit($organization, OrganizationType::Wholesaler);
    }

    public function updatePharmacy(UpdateAdminOrganizationRequest $request, Organization $organization, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->update($request, $organization, OrganizationType::Pharmacy, $auditLogger);
    }

    public function updateProvider(UpdateAdminOrganizationRequest $request, Organization $organization, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->update($request, $organization, OrganizationType::Wholesaler, $auditLogger);
    }

    public function updatePriceListVisibility(UpdatePriceListVisibilityRequest $request, Organization $organization, AuditLogger $auditLogger): RedirectResponse
    {
        $this->organizationOfType($organization, OrganizationType::Wholesaler);
        DB::transaction(function () use ($request, $organization, $auditLogger): void {
            $supplier = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $before = $supplier->only('price_list_hidden');
            $supplier->fill($request->validated());
            if ($supplier->isDirty('price_list_hidden')) {
                $supplier->save();
                $auditLogger->log('organization.price_list_visibility_updated', $supplier, $before, $supplier->only('price_list_hidden'));
            }
        });

        return redirect()->route('admin.providers.show', $organization)->with('success', 'Видимость прайс-листа обновлена.');
    }

    public function updatePharmacyAccount(UpdateOrganizationAccountRequest $request, Organization $organization, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->updateAccount($request, $organization, $user, OrganizationType::Pharmacy, $auditLogger);
    }

    public function updateProviderAccount(UpdateOrganizationAccountRequest $request, Organization $organization, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->updateAccount($request, $organization, $user, OrganizationType::Wholesaler, $auditLogger);
    }

    public function archivePharmacy(Organization $organization, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->archive($organization, OrganizationType::Pharmacy, $auditLogger);
    }

    public function archiveProvider(Organization $organization, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->archive($organization, OrganizationType::Wholesaler, $auditLogger);
    }

    private function index(Request $request, OrganizationType $type): View
    {
        $sorts = ['name' => 'name', 'status' => 'status', 'created_at' => 'created_at'];
        $sort = $request->string('sort')->toString();
        $column = $sorts[$sort] ?? 'created_at';
        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        $organizations = Organization::query()
            ->where('type', $type)
            ->when($request->filled('name'), fn ($query) => $query->where('name', 'like', '%'.$request->string('name')->toString().'%'))
            ->when($request->filled('email'), fn ($query) => $query->whereHas('assignedUsers', fn ($users) => $users->where('email', 'like', '%'.$request->string('email')->toString().'%')))
            ->when($request->filled('phone'), function ($query) use ($request) {
                $phone = $request->string('phone')->toString();

                $query->where(function ($phoneQuery) use ($phone) {
                    $phoneQuery->where('phone', 'like', '%'.$phone.'%')
                        ->orWhereHas('assignedUsers', fn ($users) => $users->where('phone', 'like', '%'.$phone.'%'));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('created_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('created_from')))
            ->when($request->filled('created_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('created_to')))
            ->with('assignedUsers')
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.organizations.index', [
            'organizations' => $organizations,
            'type' => $type,
            'sort' => $sorts[$sort] ?? 'created_at',
            'direction' => $direction,
        ]);
    }

    private function show(Organization $organization, OrganizationType $type): View
    {
        $organization = $this->organizationOfType($organization, $type);
        $organization->load('assignedUsers');

        return view('admin.organizations.show', compact('organization', 'type'));
    }

    private function edit(Organization $organization, OrganizationType $type): View
    {
        $organization = $this->organizationOfType($organization, $type);
        $organization->load('assignedUsers');

        return view('admin.organizations.edit', compact('organization', 'type'));
    }

    private function update(UpdateAdminOrganizationRequest $request, Organization $organization, OrganizationType $type, AuditLogger $auditLogger): RedirectResponse
    {
        $organization = $this->organizationOfType($organization, $type);
        DB::transaction(function () use ($request, $organization, $type, $auditLogger): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $before = $organization->only(['name', 'city', 'phone', 'status', 'supplier_mode', 'default_discount_percent', 'minimum_order', 'delivery_conditions', 'contact_name', 'contact_email', 'whatsapp_phone', 'additional_phones']);
            $data = $request->validated();
            if (array_key_exists('default_discount_percent', $data)) {
                $data['default_discount_percent'] ??= '0.00';
            }
            if ($type === OrganizationType::Wholesaler) {
                $data['additional_phones'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $data['additional_phones'] ?? '')))));
            } else {
                unset($data['contact_name'], $data['contact_email'], $data['whatsapp_phone'], $data['additional_phones']);
            }
            if ($type !== OrganizationType::Pharmacy) {
                abort_if($organization->setup_required && $data['status'] === 'active', 422);
                $sourceSupplier = Organization::query()->where('linked_pharmacy_organization_id', $organization->id)->first();
                abort_if($data['status'] === 'active' && $sourceSupplier && $sourceSupplier->status !== 'active', 422);
            }
            $previousStatus = $organization->status;
            $approvingPendingSupplier = $type === OrganizationType::Wholesaler && $previousStatus === 'pending' && $data['status'] === 'active';
            if ($approvingPendingSupplier) {
                $profileData = $data;
                unset($profileData['status']);
                $organization->update($profileData);
                app(OrganizationApprovalService::class)->approve($organization, $request->user());
            } else {
                $organization->update($data);
            }
            if ($previousStatus !== $data['status'] && ! $approvingPendingSupplier) {
                app(TelegramSupplierReviews::class)->complete($organization, 'overridden', $request->user());
            }
            $auditLogger->log('organization.updated_by_admin', $organization, $before, $organization->fresh()->only(array_keys($before)));
        });

        return redirect()->route($this->routePrefix($type).'.show', $organization)->with('success', 'Данные организации сохранены.');
    }

    private function updateAccount(UpdateOrganizationAccountRequest $request, Organization $organization, User $user, OrganizationType $type, AuditLogger $auditLogger): RedirectResponse
    {
        $organization = $this->organizationOfType($organization, $type);
        abort_unless($organization->assignedUsers()->whereKey($user->id)->exists(), 404);
        abort_if($user->isAdmin() || $user->organization_id !== $organization->id, 403);

        $before = $user->only(['name', 'email', 'phone', 'is_blocked']);
        $user->forceFill($request->validated())->save();
        $auditLogger->log('organization.account_updated_by_admin', $user, $before, $user->fresh()->only(array_keys($before)));

        return redirect()->route($this->routePrefix($type).'.edit', $organization)->with('success', 'Учётная запись сохранена.');
    }

    private function archive(Organization $organization, OrganizationType $type, AuditLogger $auditLogger): RedirectResponse
    {
        $organization = $this->organizationOfType($organization, $type);

        DB::transaction(function () use ($organization, $auditLogger): void {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $users = $lockedOrganization->users()->where('role', '!=', UserRole::Admin)->lockForUpdate()->get();
            $before = $lockedOrganization->only('status');
            $lockedOrganization->update(['status' => 'blocked']);
            app(TelegramSupplierReviews::class)->complete($lockedOrganization, 'overridden', request()->user());
            $auditLogger->log('organization.archived', $lockedOrganization, $before, $lockedOrganization->only('status'));

            foreach ($users as $user) {
                $before = $user->only('is_blocked');
                $user->forceFill(['is_blocked' => true])->save();
                $auditLogger->log('organization.account_archived', $user, $before, $user->only('is_blocked'));
            }
        });

        return redirect()->route($this->routePrefix($type).'.index')->with('success', 'Организация архивирована, все связанные учётные записи заблокированы.');
    }

    private function organizationOfType(Organization $organization, OrganizationType $type): Organization
    {
        abort_unless($organization->type === $type, 404);

        return $organization;
    }

    private function roleFor(OrganizationType $type): UserRole
    {
        return $type === OrganizationType::Pharmacy ? UserRole::Pharmacy : UserRole::Wholesaler;
    }

    private function routePrefix(OrganizationType $type): string
    {
        return $type === OrganizationType::Pharmacy ? 'admin.pharmacies' : 'admin.providers';
    }
}
