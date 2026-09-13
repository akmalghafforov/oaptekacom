<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAdminOrganizationRequest;
use App\Http\Requests\UpdateOrganizationAccountRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
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
            ->when($request->filled('email'), fn ($query) => $query->whereHas('users', fn ($users) => $users->where('email', 'like', '%'.$request->string('email')->toString().'%')))
            ->when($request->filled('phone'), function ($query) use ($request) {
                $phone = $request->string('phone')->toString();

                $query->where(function ($phoneQuery) use ($phone) {
                    $phoneQuery->where('phone', 'like', '%'.$phone.'%')
                        ->orWhereHas('users', fn ($users) => $users->where('phone', 'like', '%'.$phone.'%'));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('created_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('created_from')))
            ->when($request->filled('created_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('created_to')))
            ->with('users:id,organization_id,name,email,phone,is_blocked,role')
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
        $organization->load('users:id,organization_id,name,email,phone,is_blocked,role,created_at');

        return view('admin.organizations.show', compact('organization', 'type'));
    }

    private function edit(Organization $organization, OrganizationType $type): View
    {
        $organization = $this->organizationOfType($organization, $type);
        $organization->load('users:id,organization_id,name,email,phone,is_blocked,role,created_at');

        return view('admin.organizations.edit', compact('organization', 'type'));
    }

    private function update(UpdateAdminOrganizationRequest $request, Organization $organization, OrganizationType $type, AuditLogger $auditLogger): RedirectResponse
    {
        $organization = $this->organizationOfType($organization, $type);
        $before = $organization->only(['name', 'city', 'phone', 'status', 'supplier_mode', 'minimum_order', 'delivery_conditions']);
        $organization->update($request->validated());
        $auditLogger->log('organization.updated_by_admin', $organization, $before, $organization->fresh()->only(array_keys($before)));

        return redirect()->route($this->routePrefix($type).'.show', $organization)->with('success', 'Данные организации сохранены.');
    }

    private function updateAccount(UpdateOrganizationAccountRequest $request, Organization $organization, User $user, OrganizationType $type, AuditLogger $auditLogger): RedirectResponse
    {
        $organization = $this->organizationOfType($organization, $type);
        abort_unless($user->organization_id === $organization->id && $user->role === $this->roleFor($type), 404);

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
            $users = $lockedOrganization->users()->lockForUpdate()->get();
            $before = $lockedOrganization->only('status');
            $lockedOrganization->update(['status' => 'blocked']);
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
