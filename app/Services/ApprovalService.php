<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ApprovalService
{
    /**
     * Determine if a user can approve a given record for a specific approval type.
     */
    public static function canApprove(User $user, Model $record, string $approvalType): bool
    {
        // 1. Super Admin can override standard approval restrictions unless explicit self-approval rule applies
        if ($user->isSuperAdmin()) {
            // Even Super Admin cannot approve their own PR or deployment if they implemented it
            return ! static::isSelfAuthorOrImplementer($user, $record);
        }

        // 2. Rule: Prevent user from approving their own work
        if (static::isSelfAuthorOrImplementer($user, $record)) {
            return false;
        }

        // 3. Approval type specific authorization
        return match ($approvalType) {
            'business_scope', 'business_outcome' => static::canApproveBusinessScope($user, $record),
            'technical_release', 'technical_safety' => static::canApproveTechnicalRelease($user, $record),
            'pull_request' => static::canApprovePullRequest($user, $record),
            'agent_run' => static::canApproveAgentRun($user, $record),
            'customer_uat' => static::canApproveCustomerUat($user, $record),
            'production_deployment' => static::canApproveProductionDeployment($user, $record),
            default => false,
        };
    }

    /**
     * Check if the user is the author, creator, implementer, or deployer of the record.
     */
    public static function isSelfAuthorOrImplementer(User $user, Model $record): bool
    {
        $userId = $user->id;
        $userName = strtolower(trim($user->name ?? ''));

        $authorFields = [
            'user_id',
            'author_id',
            'created_by',
            'implemented_by_id',
            'deployed_by_id',
            'started_by_id',
            'owner_id',
            'deployed_by',
        ];

        foreach ($authorFields as $field) {
            if (isset($record->{$field})) {
                if (is_numeric($record->{$field}) && (int) $record->{$field} === (int) $userId) {
                    return true;
                }
                if (is_string($record->{$field}) && $userName !== '' && strtolower(trim($record->{$field})) === $userName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Business scope approval (Product Owner or Customer Admin for assigned scope).
     */
    protected static function canApproveBusinessScope(User $user, Model $record): bool
    {
        $clientId = $record->client_id ?? $record->project?->client_id;
        $projectId = $record->project_id ?? ($record instanceof \App\Models\Project ? $record->id : null);

        return $user->hasPermission('projects.approve', $clientId, $projectId)
            || $user->hasRole([Role::ROLE_PRODUCT_OWNER, Role::ROLE_CUSTOMER_ADMIN], $clientId, $projectId);
    }

    /**
     * Technical release approval (Engineer or Super Admin only).
     */
    protected static function canApproveTechnicalRelease(User $user, Model $record): bool
    {
        $clientId = $record->client_id ?? $record->project?->client_id;
        $projectId = $record->project_id ?? ($record instanceof \App\Models\Project ? $record->id : null);

        // Product Owners and Customer Admins CANNOT give technical release safety approval
        if ($user->hasRole([Role::ROLE_PRODUCT_OWNER, Role::ROLE_CUSTOMER_ADMIN, Role::ROLE_CLIENT_USER])) {
            // Unless they also hold Engineer role for this project
            if (! $user->hasRole(Role::ROLE_ENGINEER, $clientId, $projectId)) {
                return false;
            }
        }

        return $user->hasPermission('deployments.approve', $clientId, $projectId);
    }

    /**
     * Pull Request approval (Requires different Engineer review).
     */
    protected static function canApprovePullRequest(User $user, Model $record): bool
    {
        $projectId = $record->project_id ?? null;
        $clientId = $record->client_id ?? $record->project?->client_id;

        return $user->hasPermission('pull_requests.approve', $clientId, $projectId)
            && $user->hasRole(Role::ROLE_ENGINEER, $clientId, $projectId);
    }

    /**
     * Agent Run review/approval (User who started/supervised agent run cannot independently approve it).
     */
    protected static function canApproveAgentRun(User $user, Model $record): bool
    {
        $projectId = $record->project_id ?? null;
        $clientId = $record->client_id ?? $record->project?->client_id;

        return $user->hasPermission('agent_runs.approve', $clientId, $projectId);
    }

    /**
     * Customer UAT Approval.
     */
    protected static function canApproveCustomerUat(User $user, Model $record): bool
    {
        $clientId = $record->client_id ?? $record->project?->client_id;

        return $user->hasPermission('testing.approve', $clientId)
            && $user->hasRole([Role::ROLE_CLIENT_USER, Role::ROLE_CUSTOMER_ADMIN, Role::ROLE_PRODUCT_OWNER], $clientId);
    }

    /**
     * Production Deployment Approval (Requires both technical approval AND non-self-implementer).
     */
    protected static function canApproveProductionDeployment(User $user, Model $record): bool
    {
        $clientId = $record->client_id ?? $record->project?->client_id;
        $projectId = $record->project_id ?? null;

        return $user->hasPermission('deployments.approve', $clientId, $projectId);
    }
}
