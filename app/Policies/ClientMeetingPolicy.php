<?php

namespace App\Policies;

use App\Models\ClientMeeting;
use App\Models\User;

class ClientMeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClientMeeting $meeting): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($meeting->internal_owner_id === $user->id || $meeting->internal_owner_id === null) {
            return true;
        }

        if ($meeting->client_id) {
            $assignedClientIds = $user->getAssignedClientIds();
            return (in_array('*', $assignedClientIds) || in_array($meeting->client_id, $assignedClientIds))
                && $user->hasPermission('meetings.view', $meeting->client_id);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ClientMeeting $meeting): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($meeting->internal_owner_id === $user->id || $meeting->internal_owner_id === null) {
            return true;
        }

        if ($meeting->client_id) {
            $assignedClientIds = $user->getAssignedClientIds();
            return (in_array('*', $assignedClientIds) || in_array($meeting->client_id, $assignedClientIds))
                && $user->hasPermission('meetings.update', $meeting->client_id);
        }

        return false;
    }

    public function delete(User $user, ClientMeeting $meeting): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedClientIds = $user->getAssignedClientIds();
        $isClientAssigned = in_array('*', $assignedClientIds) || in_array($meeting->client_id, $assignedClientIds);

        return $isClientAssigned && $user->hasPermission('meetings.delete', $meeting->client_id);
    }
}
