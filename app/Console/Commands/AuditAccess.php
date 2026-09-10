<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oapteka:audit-access')]
#[Description('Report unknown roles that would block RBAC migration')]
class AuditAccess extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $unknownUsers = User::query()->whereNotIn('role', array_map(fn (UserRole $role) => $role->value, UserRole::cases()))->get(['id', 'email', 'role']);
        if ($unknownUsers->isEmpty()) {
            $this->info('Роли пользователей совместимы с RBAC.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Email', 'Неизвестная роль'], $unknownUsers->map(fn (User $user) => [$user->id, $user->email, $user->getRawOriginal('role')]));
        $this->error('Исправьте неизвестные роли вручную. Команда не меняет доступы.');

        return self::FAILURE;
    }
}
