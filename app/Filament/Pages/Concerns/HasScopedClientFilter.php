<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts the "Customer Account Filter" dropdown (and the client id it
 * drives via session) to only the clients the current user is actually
 * allowed to see.
 *
 * Super admins keep unrestricted access to every client. Everyone else
 * (client_user, and any other scoped role) is limited to
 * Auth::user()->getAssignedClientIds() - the same scoping already used by
 * ClientResource, ClientMeetingResource, IntegrationResource, etc.
 */
trait HasScopedClientFilter
{
    /**
     * Clients the current user is allowed to select, already scoped.
     */
    protected function scopedClientsQuery(): Builder
    {
        $user = auth()->user();
        $query = Client::query();

        if ($user && ! $user->isSuperAdmin()) {
            $query->whereIn('id', $user->getAssignedClientIds());
        }

        return $query;
    }

    /**
     * The client id to default to when there is no valid selection yet -
     * the current user's first assigned client (or, for super admins, the
     * first client in the system).
     */
    protected function defaultScopedClientId(): ?int
    {
        return $this->scopedClientsQuery()->orderBy('name')->first()?->id;
    }

    /**
     * Whether the given client id is within the current user's scope.
     * Used to reject a stale/tampered session value or a crafted Livewire
     * property update that bypasses the dropdown's own options.
     */
    protected function isClientIdInScope(?int $clientId): bool
    {
        if (! $clientId) {
            return false;
        }

        return $this->scopedClientsQuery()->whereKey($clientId)->exists();
    }

    /**
     * Resolve the client id to use: prefer the current session value if
     * it's still in scope, otherwise fall back to the user's default
     * assigned client. Also re-syncs the session so a stale/out-of-scope
     * value never lingers.
     */
    protected function resolveScopedClientId(): ?int
    {
        $sessionClientId = session('current_client_id');

        $clientId = $this->isClientIdInScope($sessionClientId)
            ? (int) $sessionClientId
            : $this->defaultScopedClientId();

        session(['current_client_id' => $clientId]);

        return $clientId;
    }
}
