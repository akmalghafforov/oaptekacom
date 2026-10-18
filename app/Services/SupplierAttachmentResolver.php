<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\SupplierSenderAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class SupplierAttachmentResolver
{
    /** @return Collection<int, Organization> */
    public function matchingSuppliers(string $sender, string $filename): Collection
    {
        $normalizedFilename = mb_strtolower(Str::squish($filename), 'UTF-8');

        return new Collection(SupplierSenderAddress::with('supplier.importProfile')
            ->where('normalized_email', mb_strtolower(trim($sender)))
            ->get()->map(fn (SupplierSenderAddress $address): ?Organization => $address->supplier)
            ->filter(function (?Organization $supplier) use ($normalizedFilename): bool {
                if (! $supplier) {
                    return false;
                }
                $filter = mb_strtolower(Str::squish($supplier->importProfile?->attachment_filename_filter ?? ''), 'UTF-8');

                return str_starts_with($normalizedFilename, $filter);
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
