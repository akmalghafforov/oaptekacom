<?php

namespace App\Services;

use App\Enums\AccountMode;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AccountModeContext
{
    public ?int $userId = null;

    public ?AccountMode $mode = null;

    public ?Organization $organization = null;

    public ?string $version = null;

    public function reset(): void
    {
        $this->userId = null;
        $this->mode = null;
        $this->organization = null;
        $this->version = null;
    }

    public function resolve(Request $request): void
    {
        $user = $request->user();
        $this->userId = $user->id;
        $stored = $request->session()->get('account_mode', []);
        $mode = is_array($stored) && ($stored['user_id'] ?? null) === $user->id
            && is_string($stored['mode'] ?? null) ? AccountMode::tryFrom($stored['mode']) : null;
        $assignment = $mode ? $this->assignment($user, $mode) : null;
        if (! $assignment) {
            $mode = AccountMode::forRole($user->role);
            $assignment = $this->assignment($user, $mode);
            abort_unless($assignment, 403, 'Роли аккаунта требуют настройки.');
            $stored = ['user_id' => $user->id, 'mode' => $mode->value, 'version' => (string) Str::uuid()];
        }
        if (! is_string($stored['version'] ?? null) || ! Str::isUuid($stored['version']) || ($stored['organization_id'] ?? null) !== $assignment->organization_id) {
            $stored['version'] = (string) Str::uuid();
        }
        $stored['organization_id'] = $assignment->organization_id;
        $this->mode = $mode;
        $this->organization = $assignment->organization;
        $this->version = $stored['version'];
        $request->session()->put('account_mode', $stored);
    }

    public function switchTo(Request $request, AccountMode $mode): void
    {
        $assignment = $this->assignment($request->user(), $mode);
        abort_unless($assignment, 403);
        if ($mode === $this->mode) {
            return;
        }
        $this->mode = $mode;
        $this->organization = $assignment->organization;
        $this->version = (string) Str::uuid();
        $request->session()->put('account_mode', ['user_id' => $this->userId, 'mode' => $mode->value, 'organization_id' => $assignment->organization_id, 'version' => $this->version]);
    }

    public function assignment(User $user, AccountMode $mode): ?UserRoleAssignment
    {
        $assignment = $user->roleAssignments()->with('organization')->where('role', $mode->role())->first();
        if (! $assignment) {
            return null;
        }
        if ($mode->role() === $user->role && $assignment->organization_id !== ($user->isAdmin() ? null : $user->organization_id)) {
            return null;
        }
        if ($mode === AccountMode::Admin) {
            return $assignment->organization_id === null ? $assignment : null;
        }
        $type = $mode === AccountMode::Supplier ? OrganizationType::Wholesaler : OrganizationType::Pharmacy;

        return $assignment->organization?->type === $type ? $assignment : null;
    }
}
