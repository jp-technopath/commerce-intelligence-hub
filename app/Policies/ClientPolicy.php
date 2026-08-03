<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('clients.view_any')
            || count($user->getAssignedClientIds()) > 0;
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedIds = $user->getAssignedClientIds();

        return in_array('*', $assignedIds) || in_array($client->id, $assignedIds);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedIds = $user->getAssignedClientIds();
        $isAssigned = in_array('*', $assignedIds) || in_array($client->id, $assignedIds);

        return $isAssigned && $user->hasPermission('clients.update', $client->id);
    }

    public function delete(User $user, Client $client): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedIds = $user->getAssignedClientIds();
        $isAssigned = in_array('*', $assignedIds) || in_array($client->id, $assignedIds);

        return $isAssigned && $user->hasPermission('clients.delete', $client->id);
    }
}
