<?php

namespace App\Policies;

use App\Models\ClientMeeting;
use App\Models\User;

/**
 * Authorization policy for ClientMeeting resources.
 *
 * Admins have full access. Non-admin users can only view/update
 * meetings they own or unassigned meetings.
 */
class ClientMeetingPolicy
{
    /**
     * Any authenticated user can view the meetings list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Admins, the meeting owner, or unassigned meetings can be viewed.
     */
    public function view(User $user, ClientMeeting $meeting): bool
    {
        return $user->is_admin
            || $meeting->internal_owner_id === $user->id
            || $meeting->internal_owner_id === null;
    }

    /**
     * Any authenticated user can create meetings.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Admins, the meeting owner, or unassigned meetings can be updated.
     */
    public function update(User $user, ClientMeeting $meeting): bool
    {
        return $user->is_admin
            || $meeting->internal_owner_id === $user->id
            || $meeting->internal_owner_id === null;
    }

    /**
     * Only admins can delete meetings.
     */
    public function delete(User $user, ClientMeeting $meeting): bool
    {
        return $user->is_admin;
    }
}
