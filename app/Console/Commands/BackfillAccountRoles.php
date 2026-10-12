<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RoleProvisioningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('oapteka:backfill-account-roles {--dry-run : Validate and report without persisting changes} {--chunk=100 : Number of users per chunk}')]
#[Description('Provision account roles and linked organizations, reporting invalid primary accounts')]
class BackfillAccountRoles extends Command
{
    public function handle(RoleProvisioningService $provisioning): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (! $chunk) {
            $this->error('Chunk must be between 1 and 1000.');

            return self::FAILURE;
        }
        $assignments = 0;
        $organizations = 0;
        $errors = 0;
        $activated = 0;
        $plannedActivations = [];
        $plannedSupplierPharmacies = [];
        User::query()->chunkById($chunk, function (Collection $users) use ($provisioning, &$assignments, &$organizations, &$errors, &$plannedSupplierPharmacies, &$plannedActivations, &$activated): void {
            foreach ($users as $user) {
                try {
                    $counts = $this->option('dry-run')
                        ? $provisioning->inspect($user, $plannedSupplierPharmacies, $plannedActivations)
                        : $provisioning->provision($user);
                    $assignments += $counts['assignments'];
                    $organizations += $counts['organizations'];
                    $activated += $counts['activated'];
                } catch (\LogicException $exception) {
                    $errors++;
                    $this->error($exception->getMessage());
                }
            }
        });
        $this->info(($this->option('dry-run') ? 'Dry run: ' : '')."assignments={$assignments}, organizations={$organizations}, activated={$activated}, errors={$errors}");

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
