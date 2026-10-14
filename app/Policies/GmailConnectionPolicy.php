<?php

namespace App\Policies;

use App\Models\GmailConnection;
use App\Models\User;

class GmailConnectionPolicy
{
    public function view(User $user, GmailConnection $connection): bool
    {
        return $user->isAdminMode() && $user->id === $connection->user_id;
    }
}
