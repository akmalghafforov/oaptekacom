<?php

namespace Database\Seeders;

use App\Services\SupplierEmailExclusions;
use App\Services\SupplierInboxEvidence;
use Illuminate\Database\Seeder;

class SupplierExternalFetchExclusionsSeeder extends Seeder
{
    public function run(SupplierInboxEvidence $inbox, SupplierEmailExclusions $exclusions): void
    {
        $inbox->reconcileStored();
        $exclusions->assess(seed: true);
        $exclusions->seedApprovedHistory();
    }
}
