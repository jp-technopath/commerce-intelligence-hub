<?php

namespace App\Services\PM;

use App\Models\ConnectedAccount;
use App\Models\User;

class IdentityResolverService
{
    /**
     * Known Atlassian Account ID to Forge User ID mappings.
     */
    public static function getJiraAccountMappings(): array
    {
        return [
            '557058:32896f1a-9055-4a58-a03b-0bd86412a2db' => 2, // Jean Paul Hanna
            '712020:7fcac433-e79a-4587-8eda-11ef61607fc2' => 4, // Nour eldin ayman
            '64007b8f896d10ebd471046d'                    => 3, // Donia Khaled
            '557058:a2618e39-f9da-44c4-8d36-5ca32df0785a' => 5, // Nick Barretta
        ];
    }

    /**
     * Resolve a Forge User ID from an external provider account ID, verified email, or display name.
     */
    public static function resolveUserId(
        string $provider,
        ?string $externalAccountId,
        ?string $email = null,
        ?string $displayName = null
    ): ?int {
        if (! $externalAccountId && ! $email && ! $displayName) {
            return null;
        }

        // 1. Primary: Match external_account_id on ConnectedAccount for this provider
        if ($externalAccountId) {
            // First check known hardcoded account ID mappings for Jira
            if ($provider === 'jira') {
                $knownMappings = self::getJiraAccountMappings();
                if (isset($knownMappings[$externalAccountId])) {
                    return $knownMappings[$externalAccountId];
                }
            }

            $connected = ConnectedAccount::where('provider', $provider)
                ->where('external_account_id', $externalAccountId)
                ->first();

            if ($connected && $connected->user_id) {
                return $connected->user_id;
            }

            // Also check settings_json['account_id']
            $connectedJson = ConnectedAccount::where('provider', $provider)
                ->where('settings_json->account_id', $externalAccountId)
                ->first();

            if ($connectedJson && $connectedJson->user_id) {
                $connectedJson->update(['external_account_id' => $externalAccountId]);
                return $connectedJson->user_id;
            }
        }

        // 2. Secondary: Match verified email against internal users table and aliases
        if ($email) {
            $cleanEmail = strtolower(trim($email));
            $emailAliases = [
                'jp@technopath.co'        => 2,
                'jeanpaulhanna@gmail.com' => 2,
                'nour@technopath.co'      => 4,
                'nour.ayman91@gmail.com'  => 4,
                'nour@the-raisin.com'     => 4,
                'nour@technopath.ai'      => 4,
                'donia@technopath.co'     => 3,
                'doniaakhaled@gmail.com'  => 3,
                'nick@technopath.co'      => 5,
            ];

            $matchedUserId = $emailAliases[$cleanEmail] ?? User::where('email', $cleanEmail)->value('id');

            if ($matchedUserId) {
                self::linkConnectedAccountId($provider, $matchedUserId, $externalAccountId);
                return $matchedUserId;
            }
        }

        // 3. Tertiary: Match display name against known aliases or users table
        if ($displayName) {
            $cleanName = strtolower(trim($displayName));
            $nameAliases = [
                'jean paul hanna'  => 2,
                'jean paul'        => 2,
                'nour eldin ayman' => 4,
                'nour ayman'       => 4,
                'donia khaled'     => 3,
                'donia attwa'      => 3,
                'nick barretta'    => 5,
            ];

            $matchedUserId = $nameAliases[$cleanName] ?? null;

            if (! $matchedUserId) {
                $matchedUserId = User::whereRaw('LOWER(name) = ?', [$cleanName])->value('id');
            }

            if ($matchedUserId) {
                self::linkConnectedAccountId($provider, $matchedUserId, $externalAccountId);
                return $matchedUserId;
            }
        }

        return null;
    }

    /**
     * Resolve effective user model for aliased logins (e.g. personal Google accounts).
     */
    public static function resolveEffectiveUser(User $user): User
    {
        // Google OAuth user jeanpaulhanna@gmail.com (id: 6) aliases to Jean Paul Hanna (id: 2)
        if ($user->id === 6 || strtolower($user->email) === 'jeanpaulhanna@gmail.com') {
            return User::where('email', 'jp@technopath.co')->first() ?? User::find(2) ?? $user;
        }

        // Nour PO account (id: 7) aliases to primary engineer account (id: 4)
        if ($user->id === 7 || strtolower($user->email) === 'nour@technopath.ai') {
            return User::where('email', 'nour@technopath.co')->first() ?? User::find(4) ?? $user;
        }

        return $user;
    }

    /**
     * Link an external account ID to a user's connected account if not already set.
     */
    protected static function linkConnectedAccountId(string $provider, int $userId, ?string $externalAccountId): void
    {
        if (! $externalAccountId) {
            return;
        }

        $account = ConnectedAccount::where('user_id', $userId)
            ->where('provider', $provider)
            ->first();

        if ($account && empty($account->external_account_id)) {
            $account->update(['external_account_id' => $externalAccountId]);
        }
    }
}
