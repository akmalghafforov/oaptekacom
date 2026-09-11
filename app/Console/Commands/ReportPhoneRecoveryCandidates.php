<?php

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounts:report-phone-recovery')]
#[Description('Lists blocked accounts whose organization phone can be reviewed for role-scoped recovery')]
class ReportPhoneRecoveryCandidates extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $candidates = User::query()
            ->with('organization')
            ->whereIn('role', [UserRole::Pharmacy, UserRole::Wholesaler])
            ->where('is_blocked', true)
            ->whereNull('phone')
            ->orderBy('id')
            ->get()
            ->filter(function (User $user): bool {
                $phone = PhoneNormalizer::normalize($user->organization?->phone);
                $expectedType = $user->role === UserRole::Pharmacy ? OrganizationType::Pharmacy : OrganizationType::Wholesaler;

                return $phone !== null
                    && $user->organization?->type === $expectedType
                    && ! User::query()->where('role', $user->role)->where('phone', $phone)->exists();
            });

        if ($candidates->isEmpty()) {
            $this->info('No phone-recovery candidates found.');

            return self::SUCCESS;
        }

        $this->table(['User ID', 'Role', 'Organization', 'Candidate phone'], $candidates->map(fn (User $user): array => [$user->id, $user->role->value, $user->organization?->name, PhoneNormalizer::normalize($user->organization?->phone)]));
        $this->warn('Review each account and use the admin phone-remediation action to restore it. This command makes no changes.');

        return self::SUCCESS;
    }
}
