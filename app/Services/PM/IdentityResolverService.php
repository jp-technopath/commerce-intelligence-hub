<?php

namespace App\Services\PM;

use App\Models\ConnectedAccount;
use App\Models\User;

class IdentityResolverService
{
    /**
     * Resolve a Forge User ID from an external provider account ID or verified email.
     * Completely provider-independent.
     */
    public static function resolveUserId(string $provider, ?string $externalAccountId, ?string $email = null): ?int
    {
        if (! $externalAccountId && ! $email) {
            return null;
        }

        // 1. Primary: Match external_account_id on ConnectedAccount for this provider
        if ($externalAccountId) {
            $connected = ConnectedAccount::where('provider', $provider)
                ->where('external_account_id', $externalAccountId)
                ->first();

            if ($connected) {
                return $connected->user_id;
            }

            // Also check settings_json['account_id'] for accounts created before migration
            $connectedJson = ConnectedAccount::where('provider', $provider)
                ->where('settings_json->account_id', $externalAccountId)
                ->first();

            if ($connectedJson) {
                $connectedJson->update(['external_account_id' => $externalAccountId]);
                return $connectedJson->user_id;
            }
        }

        // 2. Secondary: Match verified email against internal users table
        if ($email) {
            $user = User::where('email', strtolower(trim($email)))->first();
            if ($user) {
                // If we also had an externalAccountId, we can associate it if user has a connected account
                if ($externalAccountId) {
                    $userAccount = ConnectedAccount::where('user_id', $user->id)
                        ->where('provider', $provider)
                        ->first();
                    if ($userAccount && empty($userAccount->external_account_id)) {
                        $userAccount->update(['external_account_id' => $externalAccountId]);
                    }
                }
                return $user->id;
            }
        }

        return null;
    }
}
