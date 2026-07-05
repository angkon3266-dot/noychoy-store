<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for the Meta Integration module. Registered as the gate
 * ability "meta.access" in AppServiceProvider. Only the Super Admin (role
 * "admin") is permitted; managers and staff are excluded.
 */
class MetaPolicy
{
    public function access(User $user): bool
    {
        return $user->role === 'admin';
    }
}
