<?php

namespace App\Models;

use App\Enums\ConnectedAccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectedAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'authorized_email',
        'credentials_json',
        'settings_json',
        'granted_scopes',
        'status',
        'token_expires_at',
        'last_error',
    ];

    protected $casts = [
        'credentials_json' => 'encrypted:array',
        'settings_json'    => 'array',
        'granted_scopes'   => 'array',
        'status'           => ConnectedAccountStatus::class,
        'token_expires_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ConnectedAccountStatus::Active);
    }

    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function getCredential(string $key): mixed
    {
        return ($this->credentials_json ?? [])[$key] ?? null;
    }

    public function setCredential(string $key, mixed $value): void
    {
        $credentials = $this->credentials_json ?? [];
        $credentials[$key] = $value;
        $this->credentials_json = $credentials;
        $this->save();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->granted_scopes ?? [], true);
    }

    public function needsReconnect(): bool
    {
        return in_array($this->status, [
            ConnectedAccountStatus::Revoked,
            ConnectedAccountStatus::Expired,
            ConnectedAccountStatus::Error,
        ], true);
    }

    /**
     * Refresh the Jira OAuth token on demand if it is expired or close to expiration.
     */
    public function refreshJiraTokenIfNeeded(): ?string
    {
        if ($this->provider !== 'jira') {
            return null;
        }

        $credentials = $this->credentials_json ?? [];
        $accessToken = $credentials['access_token'] ?? null;
        $expiresAt = $this->token_expires_at;

        // If accessToken exists and is not expired (or within a 1-minute buffer), return it
        if ($accessToken && $expiresAt && $expiresAt->subMinute()->isFuture()) {
            return $accessToken;
        }

        $refreshToken = $credentials['refresh_token'] ?? null;
        if (! $refreshToken) {
            $this->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'No Jira refresh token found.',
            ]);
            throw new \RuntimeException('No Jira refresh token found for ConnectedAccount.');
        }

        $clientId = config('meeting_agent.jira.client_id');
        $clientSecret = config('meeting_agent.jira.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            $this->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Jira OAuth client credentials are not configured.',
            ]);
            throw new \RuntimeException('Jira OAuth client_id or client_secret is missing from configuration.');
        }

        $response = \Illuminate\Support\Facades\Http::post('https://auth.atlassian.com/oauth/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if (! $response->successful()) {
            $this->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Jira token refresh failed: ' . $response->body(),
            ]);
            throw new \RuntimeException('Failed to refresh Jira OAuth token: ' . $response->body());
        }

        $tokenData = $response->json();
        $newAccessToken = $tokenData['access_token'] ?? null;
        $newRefreshToken = $tokenData['refresh_token'] ?? $refreshToken;
        $expiresIn = $tokenData['expires_in'] ?? 3600;

        if (! $newAccessToken) {
            $this->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Jira token response did not contain an access token.',
            ]);
            throw new \RuntimeException('Jira token response did not contain an access token.');
        }

        $credentials['access_token'] = $newAccessToken;
        $credentials['refresh_token'] = $newRefreshToken;

        $this->update([
            'credentials_json' => $credentials,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'status'           => ConnectedAccountStatus::Active,
            'last_error'       => null,
        ]);

        return $newAccessToken;
    }
}
