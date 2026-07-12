<?php

namespace App\Filament\Resources\ClientMeetingResource\Widgets;

use App\Models\ConnectedAccount;
use Filament\Widgets\Widget;

class GoogleWorkspaceStatusWidget extends Widget
{
    protected static string $view = 'filament.resources.client-meeting-resource.widgets.google-workspace-status';

    protected int|string|array $columnSpan = 'full';

    /**
     * Determine the Google Workspace status for the current user.
     *
     * Returns an array with:
     *  - status: 'not_connected' | 'needs_reconnect' | 'missing_scopes' | 'connected'
     *  - email: the authorized email (if connected)
     *  - last_error: last error message (if needs reconnect)
     *  - missing_scopes: human-readable labels of missing scopes
     *  - connect_url: route to connect / reconnect
     *  - revoke_url: route to disconnect
     */
    public function getWorkspaceStatus(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $account = $user->connectedAccounts()
            ->where('provider', 'google_workspace')
            ->first();

        if (! $account) {
            return [
                'status'      => 'not_connected',
                'connect_url' => route('google.workspace.connect'),
            ];
        }

        if ($account->needsReconnect()) {
            return [
                'status'      => 'needs_reconnect',
                'email'       => $account->authorized_email,
                'last_error'  => $account->last_error,
                'connect_url' => route('google.workspace.connect'),
            ];
        }

        // Check required scopes
        $requiredScopes = [
            'Calendar (read-only)' => config('meeting_agent.google.scopes.calendar_readonly'),
            'Gmail (compose)'     => config('meeting_agent.google.scopes.gmail_compose'),
            'Google Drive (file)' => config('meeting_agent.google.scopes.drive_file'),
            'Google Drive (read-only)' => config('meeting_agent.google.scopes.drive_readonly'),
        ];

        $missingScopes = [];
        foreach ($requiredScopes as $label => $scope) {
            if (! $account->hasScope($scope)) {
                $missingScopes[] = $label;
            }
        }

        if (! empty($missingScopes)) {
            return [
                'status'         => 'missing_scopes',
                'email'          => $account->authorized_email,
                'missing_scopes' => $missingScopes,
                'connect_url'    => route('google.workspace.connect'),
            ];
        }

        return [
            'status'     => 'connected',
            'email'      => $account->authorized_email,
            'revoke_url' => route('google.workspace.revoke'),
        ];
    }
}
