<?php

namespace App\Policies;

use App\Models\Finding;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\VisibilityService;

class FindingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('findings.view_any')
            || count($user->getAssignedClientIds()) > 0;
    }

    public function view(User $user, Finding $finding): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedClientIds = $user->getAssignedClientIds();
        $isClientAssigned = in_array('*', $assignedClientIds) || in_array($finding->client_id, $assignedClientIds);

        if (! $isClientAssigned) {
            return false;
        }

        // Must pass visibility classification check
        return VisibilityService::canViewRecord($user, $finding);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('findings.create');
    }

    public function update(User $user, Finding $finding): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedClientIds = $user->getAssignedClientIds();
        $isClientAssigned = in_array('*', $assignedClientIds) || in_array($finding->client_id, $assignedClientIds);

        return $isClientAssigned
            && $user->hasPermission('findings.update', $finding->client_id)
            && VisibilityService::canViewRecord($user, $finding);
    }

    public function delete(User $user, Finding $finding): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedClientIds = $user->getAssignedClientIds();
        $isClientAssigned = in_array('*', $assignedClientIds) || in_array($finding->client_id, $assignedClientIds);

        return $isClientAssigned && $user->hasPermission('findings.delete', $finding->client_id);
    }

    public function approve(User $user, Finding $finding): bool
    {
        return ApprovalService::canApprove($user, $finding, 'business_scope');
    }
}
