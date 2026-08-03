<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\ApprovalService;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('projects.view_any')
            || count($user->getAssignedProjectIds()) > 0;
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedProjectIds = $user->getAssignedProjectIds();

        return in_array('*', $assignedProjectIds)
            || in_array($project->id, $assignedProjectIds);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedProjectIds = $user->getAssignedProjectIds();
        $isAssigned = in_array('*', $assignedProjectIds) || in_array($project->id, $assignedProjectIds);

        return $isAssigned && $user->hasPermission('projects.update', $project->client_id, $project->id);
    }

    public function delete(User $user, Project $project): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $assignedProjectIds = $user->getAssignedProjectIds();
        $isAssigned = in_array('*', $assignedProjectIds) || in_array($project->id, $assignedProjectIds);

        return $isAssigned && $user->hasPermission('projects.delete', $project->client_id, $project->id);
    }

    public function approve(User $user, Project $project): bool
    {
        return ApprovalService::canApprove($user, $project, 'business_scope');
    }
}
