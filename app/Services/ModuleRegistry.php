<?php

namespace App\Services;

use App\Enums\ModuleKey;
use App\Models\ModuleSetting;
use App\Models\User;

class ModuleRegistry
{
    public function enabled(ModuleKey $module): bool
    {
        return ModuleSetting::query()->where('key', $module->value)->value('enabled') ?? true;
    }

    public function available(User $user, ModuleKey $module): bool
    {
        return $user->isAdmin() || $this->enabled($module);
    }
}
