<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'provider',
        'name',
        'external_workspace_id',
        'configuration_json',
        'status_mappings_json',
        'default_sync_user_id',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'configuration_json'   => 'array',
        'status_mappings_json' => 'array',
        'is_active'            => 'boolean',
        'last_synced_at'       => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function defaultSyncUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_sync_user_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(PmProject::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(PmWorkItem::class);
    }

    public function worklogs(): HasMany
    {
        return $this->hasMany(PmWorklog::class);
    }

    /**
     * Check if the background sync identity user has a healthy/active ConnectedAccount.
     */
    public function getSyncIdentityHealthAttribute(): array
    {
        if (! $this->default_sync_user_id) {
            return [
                'is_healthy' => false,
                'message'    => 'No default sync identity user configured.',
            ];
        }

        $user = $this->defaultSyncUser;
        if (! $user) {
            return [
                'is_healthy' => false,
                'message'    => 'Configured default sync user no longer exists.',
            ];
        }

        $connectedAccount = $user->jiraAccount();
        if (! $connectedAccount || $connectedAccount->needsReconnect()) {
            return [
                'is_healthy' => false,
                'user_name'  => $user->name,
                'message'    => "Sync identity ({$user->name}) requires reconnection.",
            ];
        }

        return [
            'is_healthy' => true,
            'user_name'  => $user->name,
            'message'    => "Connected as {$user->name}",
        ];
    }

    /**
     * Get the stable Jira site / cloud identity for this connection.
     */
    public function getJiraSiteIdentity(): string
    {
        if (! empty($this->external_workspace_id)) {
            $parsed = parse_url($this->external_workspace_id, PHP_URL_HOST);
            return $parsed ?: $this->external_workspace_id;
        }

        if (! empty($this->configuration_json['site_url'])) {
            $parsed = parse_url($this->configuration_json['site_url'], PHP_URL_HOST);
            if ($parsed) {
                return $parsed;
            }
        }

        if (! empty($this->configuration_json['cloud_id'])) {
            return (string) $this->configuration_json['cloud_id'];
        }

        if ($this->default_sync_user_id && $this->defaultSyncUser) {
            try {
                $account = $this->defaultSyncUser->jiraAccount();
                $cloudId = $account?->getCredential('cloud_id');
                if ($cloudId) {
                    return $cloudId;
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        $baseUrl = config('meeting_agent.jira.base_url');
        if ($baseUrl) {
            $parsed = parse_url($baseUrl, PHP_URL_HOST);
            if ($parsed) {
                return $parsed;
            }
        }

        return 'technopath.atlassian.net';
    }
}
