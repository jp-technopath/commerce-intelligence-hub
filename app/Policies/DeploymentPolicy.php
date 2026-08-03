<?php

namespace App\Policies;

use App\Models\Deployment;
use App\Models\User;
use App\Services\ApprovalService;

class DeploymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('deployments.view_any')
            || count($user->getAssignedProjectIds()) > 0;
    }

    public function view(User $user, Deployment $deployment): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedProjectIds = $user->getAssignedProjectIds();

        return in_array('*', $assignedProjectIds)
            || in_array($deployment->project_id, $assignedProjectIds);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('deployments.create');
    }

    public function update(User $user, Deployment $deployment): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedProjectIds = $user->getAssignedProjectIds();
        $isAssigned = in_array('*', $assignedProjectIds) || in_array($deployment->project_id, $assignedProjectIds);

        return $isAssigned && $user->hasPermission('deployments.update', $deployment->project?->client_id, $deployment->project_id);
    }

    public function delete(User $user, Deployment $deployment): bool
    {
        return $user->isSuperAdmin();
    }

    public function approve(User $user, Deployment $deployment): bool
    {
        return ApprovalService::canApprove($user, $deployment, 'production_deployment');
    }
}
