<?php

namespace App\Policies;

use App\Models\PromotionItem;
use App\Models\User;

class PromotionItemPolicy
{
    public function view(User $user, PromotionItem $item): bool
    {
        return $user->can('view', $item->promotion);
    }

    public function update(User $user, PromotionItem $item): bool
    {
        return $user->can('update', $item->promotion);
    }

    public function delete(User $user, PromotionItem $item): bool
    {
        return $this->update($user, $item);
    }
}
