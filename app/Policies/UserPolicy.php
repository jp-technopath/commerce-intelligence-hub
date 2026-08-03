<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view_any')
            || $user->hasRole(Role::ROLE_CUSTOMER_ADMIN)
            || $user->isSuperAdmin();
    }

    public function view(User $user, User $targetUser): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Customer Admins can only view users assigned to the same client(s)
        if ($user->hasRole(Role::ROLE_CUSTOMER_ADMIN)) {
            $actorClientIds = $user->getAssignedClientIds();
            $targetClientIds = $targetUser->getAssignedClientIds();

            $sharedClients = array_intersect($actorClientIds, $targetClientIds);
            return count($sharedClients) > 0;
        }

        return $user->id === $targetUser->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.create') || $user->hasRole(Role::ROLE_CUSTOMER_ADMIN);
    }

    public function update(User $user, User $targetUser): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Privilege Escalation Protection: User cannot modify their own roles/scope
        if ($user->id === $targetUser->id && ! $user->isSuperAdmin()) {
            // Cannot modify self
            return false;
        }

        // Customer Admin scope check
        if ($user->hasRole(Role::ROLE_CUSTOMER_ADMIN)) {
            $actorClientIds = $user->getAssignedClientIds();
            $targetClientIds = $targetUser->getAssignedClientIds();

            $sharedClients = array_intersect($actorClientIds, $targetClientIds);
            return count($sharedClients) > 0;
        }

        return false;
    }

    public function delete(User $user, User $targetUser): bool
    {
        // Cannot delete oneself
        if ($user->id === $targetUser->id) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        // Protection against removing final Customer Admin
        if (static::isFinalCustomerAdmin($targetUser)) {
            return false;
        }

        if ($user->hasRole(Role::ROLE_CUSTOMER_ADMIN)) {
            $actorClientIds = $user->getAssignedClientIds();
            $targetClientIds = $targetUser->getAssignedClientIds();

            $sharedClients = array_intersect($actorClientIds, $targetClientIds);
            return count($sharedClients) > 0;
        }

        return false;
    }

    /**
     * Check if actor can assign a specific role to a target user.
     */
    public static function canAssignRole(User $actor, string $roleName, ?int $clientId = null): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        // Customer Admins CANNOT assign internal roles!
        $internalRoles = [
            Role::ROLE_SUPER_ADMIN,
            'Super Admin',
            Role::ROLE_PRODUCT_OWNER,
            Role::ROLE_ANALYST,
            Role::ROLE_ENGINEER,
        ];

        if ($actor->hasRole(Role::ROLE_CUSTOMER_ADMIN)) {
            if (in_array($roleName, $internalRoles)) {
                return false;
            }

            // Customer Admin can only assign customer roles for their assigned clients
            if ($clientId && ! in_array($clientId, $actor->getAssignedClientIds())) {
                return false;
            }

            return in_array($roleName, [Role::ROLE_CUSTOMER_ADMIN, Role::ROLE_CLIENT_USER]);
        }

        return false;
    }

    /**
     * Check if targetUser is the final active Customer Admin for any of their assigned clients.
     */
    public static function isFinalCustomerAdmin(User $targetUser): bool
    {
        $targetClientIds = $targetUser->getAssignedClientIds(Role::ROLE_CUSTOMER_ADMIN);

        foreach ($targetClientIds as $clientId) {
            if ($clientId === '*') {
                continue;
            }

            $otherAdminsCount = UserRoleAssignment::currentlyActive()
                ->whereHas('role', fn ($q) => $q->where('name', Role::ROLE_CUSTOMER_ADMIN))
                ->where('client_id', $clientId)
                ->where('user_id', '!=', $targetUser->id)
                ->count();

            if ($otherAdminsCount === 0) {
                return true; // Target user is the last remaining Customer Admin for this client
            }
        }

        return false;
    }
}
