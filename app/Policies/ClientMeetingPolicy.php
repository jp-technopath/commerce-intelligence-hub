<?php

namespace App\Policies;

use App\Models\ClientMeeting;
use App\Models\User;

class ClientMeetingPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasPermission('meetings.view_any')
            || $user->hasPermission('meetings.view');
    }

    public function view(User $user, ClientMeeting $meeting): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($meeting->internal_owner_id !== null && $meeting->internal_owner_id === $user->id) {
            return true;
        }

        if ($meeting->client_id) {
            $assignedClientIds = $user->getAssignedClientIds();
            $isAssigned = in_array('*', $assignedClientIds) || in_array($meeting->client_id, $assignedClientIds);

            return $isAssigned && $user->hasPermission('meetings.view', $meeting->client_id);
        }

        return $user->hasPermission('meetings.view_any') || $user->hasPermission('meetings.view');
    }

    public function create(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isClientOnly()) {
            return false;
        }

        return $user->hasPermission('meetings.create');
    }

    public function update(User $user, ClientMeeting $meeting): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isClientOnly()) {
            return false;
        }

        if ($meeting->internal_owner_id !== null && $meeting->internal_owner_id === $user->id) {
            return true;
        }

        if ($meeting->client_id) {
            $assignedClientIds = $user->getAssignedClientIds();
            $isAssigned = in_array('*', $assignedClientIds) || in_array($meeting->client_id, $assignedClientIds);

            return $isAssigned && $user->hasPermission('meetings.update', $meeting->client_id);
        }

        return $user->hasPermission('meetings.update');
    }

    public function delete(User $user, ClientMeeting $meeting): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isClientOnly()) {
            return false;
        }

        if ($meeting->client_id) {
            $assignedClientIds = $user->getAssignedClientIds();
            $isClientAssigned = in_array('*', $assignedClientIds) || in_array($meeting->client_id, $assignedClientIds);

            return $isClientAssigned && $user->hasPermission('meetings.delete', $meeting->client_id);
        }

        return $user->hasPermission('meetings.delete');
    }
}
