<?php

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Services\SupplierAutomaticSetup;
use App\Services\SupplierVersions;
use Illuminate\Console\Command;

class SetupSupplierAutomaticUpdates extends Command
{
    protected $signature = 'suppliers:setup-automatic {--apply : Create missing external profiles and enable eligible suppliers}';

    protected $description = 'Report every supplier and configure approved daily-version updates (dry run by default)';

    public function handle(SupplierAutomaticSetup $setup, SupplierVersions $versions): int
    {
        if ($this->option('apply') && ! $versions->enabled()) {
            $this->error('Prepare the daily-version rollout before applying automatic setup.');

            return self::FAILURE;
        }
        $this->info($this->option('apply') ? 'Applying automatic setup.' : 'Dry run; no changes will be saved.');
        $rows = Organization::where('type', OrganizationType::Wholesaler)->orderBy('id')->get()->map(function (Organization $supplier) use ($setup): array {
            $result = $setup->configure($supplier, (bool) $this->option('apply'));

            return [$supplier->id, $supplier->name, $result['status'], $result['reason']];
        });
        $this->table(['ID', 'Supplier', 'Status', 'Reason'], $rows);

        return self::SUCCESS;
    }
}
