<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for the System Configuration module — Super Admin only.
 * Registered as the "system-config.access" gate in AppServiceProvider.
 */
class SystemConfigPolicy
{
    public function access(User $user): bool
    {
        return $user->role === 'admin';
    }
}
