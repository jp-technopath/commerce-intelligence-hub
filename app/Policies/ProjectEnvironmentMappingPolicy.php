<?php

namespace App\Policies;

use App\Models\ProjectEnvironmentMapping;
use App\Models\User;

class ProjectEnvironmentMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, ProjectEnvironmentMapping $mapping): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, ProjectEnvironmentMapping $mapping): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, ProjectEnvironmentMapping $mapping): bool
    {
        return false;
    }
}
