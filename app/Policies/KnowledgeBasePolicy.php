<?php

namespace App\Policies;

use App\Models\User;

class KnowledgeBasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('knowledge_base.view_any')
            || count($user->getAssignedClientIds()) > 0;
    }

    public function view(User $user, $record = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $clientId = $record->client_id ?? null;
        if ($clientId) {
            $assignedClientIds = $user->getAssignedClientIds();
            return in_array('*', $assignedClientIds) || in_array($clientId, $assignedClientIds);
        }

        return $user->hasPermission('knowledge_base.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('knowledge_base.create');
    }

    public function update(User $user, $record = null): bool
    {
        return $user->hasPermission('knowledge_base.update');
    }

    public function delete(User $user, $record = null): bool
    {
        return $user->hasPermission('knowledge_base.delete');
    }
}
