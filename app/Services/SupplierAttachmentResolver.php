<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\SupplierSenderAddress;
use Illuminate\Database\Eloquent\Collection;

class SupplierAttachmentResolver
{
    /** @return Collection<int, Organization> */
    public function matchingSuppliers(string $sender, string $filename): Collection
    {
        return new Collection(SupplierSenderAddress::with('supplier.importProfile')
            ->where('normalized_email', mb_strtolower(trim($sender)))
            ->get()->map(fn (SupplierSenderAddress $address): ?Organization => $address->supplier)
            ->filter(function (?Organization $supplier) use ($filename): bool {
                if (! $supplier) {
                    return false;
                }
                $filter = $supplier->importProfile?->attachment_filename_filter;

                return $filter === null || $filter === '' || mb_stripos($filename, $filter, 0, 'UTF-8') !== false;
            })->values()->all());
    }

    public function eligibilityReason(Organization $supplier, string $filename): ?string
    {
        $profile = $supplier->importProfile;

        return match (true) {
            $supplier->status !== 'active' || $supplier->type !== OrganizationType::Wholesaler => 'inactive_supplier',
            ! $profile => 'missing_profile',
            ! $profile->is_active => 'inactive_profile',
            strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $profile->file_type->value => 'format_mismatch',
            default => null,
        };
    }
}
