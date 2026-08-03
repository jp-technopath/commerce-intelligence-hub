<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Role $role): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Role $role): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->isSuperAdmin();
    }
}
