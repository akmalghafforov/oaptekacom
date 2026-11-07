<?php

namespace App\Policies;

use App\Models\PromotionAttachment;
use App\Models\User;

class PromotionAttachmentPolicy
{
    public function view(User $user, PromotionAttachment $attachment): bool
    {
        return $user->can('view', $attachment->promotion);
    }

    public function update(User $user, PromotionAttachment $attachment): bool
    {
        return $user->can('update', $attachment->promotion);
    }

    public function delete(User $user, PromotionAttachment $attachment): bool
    {
        return $this->update($user, $attachment);
    }
}
