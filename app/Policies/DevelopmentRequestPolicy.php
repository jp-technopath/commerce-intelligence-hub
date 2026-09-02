<?php

namespace App\Policies;

use App\Enums\DevelopmentRequestStatus;
use App\Models\DevelopmentRequest;
use App\Models\User;

class DevelopmentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('development_requests.view_any')
            || count($user->getAssignedProjectIds()) > 0;
    }

    public function view(User $user, DevelopmentRequest $request): bool
    {
        return $this->isInScope($user, $request)
            && ($user->hasPermission('development_requests.view', $request->client_id, $request->project_id)
                || $user->hasPermission('development_requests.view_any', $request->client_id, $request->project_id));
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('development_requests.create');
    }

    public function update(User $user, DevelopmentRequest $request): bool
    {
        if (! in_array($request->state, [
            DevelopmentRequestStatus::Draft,
            DevelopmentRequestStatus::ChangesRequested,
        ], true)) {
            return false;
        }

        if (! $user->isSuperAdmin() && (int) $request->owner_user_id !== (int) $user->getKey()) {
            return false;
        }

        return $this->isInScope($user, $request)
            && ($user->hasPermission('development_requests.update', $request->client_id, $request->project_id)
                || $user->hasPermission('development_requests.create', $request->client_id, $request->project_id));
    }

    public function delete(User $user, DevelopmentRequest $request): bool
    {
        return $request->state === DevelopmentRequestStatus::Draft
            && ($user->isSuperAdmin() || (int) $request->owner_user_id === (int) $user->getKey())
            && $this->isInScope($user, $request)
            && $user->hasPermission('development_requests.delete', $request->client_id, $request->project_id);
    }

    public function approve(User $user, DevelopmentRequest $request): bool
    {
        return $this->isInScope($user, $request)
            && $user->hasPermission('development_requests.approve', $request->client_id, $request->project_id);
    }

    public function cancel(User $user, DevelopmentRequest $request): bool
    {
        return $this->isInScope($user, $request)
            && $user->hasPermission('development_requests.cancel', $request->client_id, $request->project_id);
    }

    private function isInScope(User $user, DevelopmentRequest $request): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $projectIds = $user->getAssignedProjectIds();
        $clientIds = $user->getAssignedClientIds();

        return in_array('*', $projectIds, true)
            || in_array((int) $request->project_id, array_map('intval', $projectIds), true)
            || in_array('*', $clientIds, true)
            || in_array((int) $request->client_id, array_map('intval', $clientIds), true);
    }
}
