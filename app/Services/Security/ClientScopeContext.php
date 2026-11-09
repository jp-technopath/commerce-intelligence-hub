<?php

namespace App\Services\Security;

use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Fail-Closed Multi-Customer Security Context.
 * Ensures operations cannot proceed without an authenticated authorized user,
 * an explicit customer background execution context, or trusted system maintenance.
 */
class ClientScopeContext
{
    /**
     * Currently established background client ID.
     */
    protected static ?int $currentClientId = null;

    /**
     * Flag indicating trusted internal system maintenance mode.
     */
    protected static bool $isSystemMaintenance = false;

    /**
     * Reason or source for system maintenance mode.
     */
    protected static ?string $maintenanceReason = null;

    /**
     * Execute a callback within an explicitly scoped client context (e.g. queued jobs, cron workers).
     * Automatically cleans up context in finally block to prevent cross-tenant leakage.
     */
    public static function forClient(Client|int $client, callable $callback): mixed
    {
        $clientId = $client instanceof Client ? $client->id : (int) $client;
        $previousClientId = static::$currentClientId;
        $previousMaintenance = static::$isSystemMaintenance;
        $previousReason = static::$maintenanceReason;

        static::$currentClientId = $clientId;
        static::$isSystemMaintenance = false;
        static::$maintenanceReason = null;

        try {
            return $callback();
        } finally {
            static::$currentClientId = $previousClientId;
            static::$isSystemMaintenance = $previousMaintenance;
            static::$maintenanceReason = $previousReason;
        }
    }

    /**
     * Execute a callback within trusted internal system maintenance mode (e.g. migrations, global reconciliations).
     * Restricted to trusted internal operations.
     */
    public static function forSystemMaintenance(string $reason, callable $callback): mixed
    {
        $previousClientId = static::$currentClientId;
        $previousMaintenance = static::$isSystemMaintenance;
        $previousReason = static::$maintenanceReason;

        static::$currentClientId = null;
        static::$isSystemMaintenance = true;
        static::$maintenanceReason = $reason;

        try {
            return $callback();
        } finally {
            static::$currentClientId = $previousClientId;
            static::$isSystemMaintenance = $previousMaintenance;
            static::$maintenanceReason = $previousReason;
        }
    }

    /**
     * Reset all execution contexts. Call between queue jobs or worker cycles.
     */
    public static function reset(): void
    {
        static::$currentClientId = null;
        static::$isSystemMaintenance = false;
        static::$maintenanceReason = null;
    }

    /**
     * Get the currently active background client ID.
     */
    public static function getCurrentClientId(): ?int
    {
        return static::$currentClientId;
    }

    /**
     * Check if currently in trusted system maintenance mode.
     */
    public static function isSystemMaintenance(): bool
    {
        return static::$isSystemMaintenance;
    }

    /**
     * Get system maintenance reason if active.
     */
    public static function getMaintenanceReason(): ?string
    {
        return static::$maintenanceReason;
    }

    /**
     * Assert that the current execution context is authorized for the given client ID.
     * FAILS CLOSED:
     * - Requires an authenticated user authorized for the client, OR
     * - An explicit background execution context matching the client, OR
     * - Trusted system maintenance mode.
     *
     * @throws AuthorizationException
     */
    public static function assertAuthorizedForClient(Client|int $client, ?User $actor = null): void
    {
        $clientId = $client instanceof Client ? $client->id : (int) $client;

        // 1. Check system maintenance
        if (static::$isSystemMaintenance) {
            return;
        }

        // 2. Check explicit background execution context
        if (static::$currentClientId !== null && static::$currentClientId === $clientId) {
            return;
        }

        // 3. Check authenticated or explicit user
        $user = $actor ?? Auth::user();

        if ($user) {
            if ($user->canAccessClient($clientId)) {
                return;
            }

            throw new AuthorizationException(
                "User [{$user->name}] is not authorized for customer ID [{$clientId}]."
            );
        }

        // 4. Fail closed: Unauthenticated operations without background or system context cannot proceed
        throw new AuthorizationException(
            "Access denied to customer ID [{$clientId}]: Unauthenticated execution without valid client scope context."
        );
    }
}
