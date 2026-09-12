<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSupplierImportProfileRequest;
use App\Models\Organization;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Services\AuditLogger;
use App\Services\PriceList\ProfileValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupplierImportProfileController extends Controller
{
    public function edit(int $supplier): View
    {
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $profile = $organization->importProfile ?? new SupplierImportProfile(['name' => 'Основной профиль', 'file_type' => 'xlsx', 'configuration' => ProfileValidator::defaults()]);

        $senderEmails = $organization->senderAddresses()->orderBy('normalized_email')->pluck('email')->implode("\n");

        return view('admin.supplier-import-profiles.edit', compact('organization', 'profile', 'senderEmails'));
    }

    public function update(UpdateSupplierImportProfileRequest $request, int $supplier, ProfileValidator $validator, AuditLogger $audit): RedirectResponse
    {
        $organization = Organization::query()->where('type', OrganizationType::Wholesaler)->findOrFail($supplier);
        $configuration = $validator->validate($request->validated('configuration'));
        $emails = collect(preg_split('/\R/u', (string) $request->validated('sender_emails')))->map(fn (string $email): string => mb_strtolower(trim($email)))->filter()->unique()->values();
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || SupplierSenderAddress::query()->where('normalized_email', $email)->where('supplier_organization_id', '!=', $organization->id)->exists()) {
                throw ValidationException::withMessages(['sender_emails' => 'Адрес '.$email.' неверен или уже назначен другому поставщику.']);
            }
        }
        $profile = $organization->importProfile;
        $before = $profile?->only(['name', 'file_type', 'configuration']) ?? [];
        $profile = SupplierImportProfile::updateOrCreate(['supplier_organization_id' => $organization->id], ['name' => $request->validated('name'), 'file_type' => $request->validated('file_type'), 'configuration' => $configuration, 'sample_metadata' => $request->validated('sample_metadata'), 'updated_by' => $request->user()->id, 'is_active' => true]);
        $organization->senderAddresses()->whereNotIn('normalized_email', $emails)->delete();
        foreach ($emails as $email) {
            SupplierSenderAddress::updateOrCreate(['normalized_email' => $email], ['email' => $email, 'supplier_organization_id' => $organization->id]);
        }
        $audit->log('supplier_import_profile.updated', $profile, $before, $profile->only(['name', 'file_type', 'configuration']));

        return back()->with('success', 'Профиль импорта сохранён.');
    }
}
