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
}
