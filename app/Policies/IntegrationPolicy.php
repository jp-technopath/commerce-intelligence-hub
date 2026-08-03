<?php

namespace App\Policies;

use App\Models\Integration;
use App\Models\User;

class IntegrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('integrations.view_any')
            || count($user->getAssignedClientIds()) > 0;
    }

    public function view(User $user, Integration $integration): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedClientIds = $user->getAssignedClientIds();

        return in_array('*', $assignedClientIds) || in_array($integration->client_id, $assignedClientIds);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('integrations.create');
    }

    public function update(User $user, Integration $integration): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedClientIds = $user->getAssignedClientIds();
        $isAssigned = in_array('*', $assignedClientIds) || in_array($integration->client_id, $assignedClientIds);

        return $isAssigned && $user->hasPermission('integrations.update', $integration->client_id);
    }

    public function delete(User $user, Integration $integration): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedClientIds = $user->getAssignedClientIds();
        $isAssigned = in_array('*', $assignedClientIds) || in_array($integration->client_id, $assignedClientIds);

        return $isAssigned && $user->hasPermission('integrations.delete', $integration->client_id);
    }
}
