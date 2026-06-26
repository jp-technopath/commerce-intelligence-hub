<?php

namespace App\Models;

use App\Enums\IntegrationStatus;
use App\Enums\IntegrationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'integration_type',
        'status',
        'credentials_json',
        'settings_json',
        'last_sync_at',
    ];

    protected $casts = [
        'integration_type'  => IntegrationType::class,
        'status'            => IntegrationStatus::class,
        'credentials_json'  => 'encrypted:array',
        'settings_json'     => 'array',
        'last_sync_at'      => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function latestSyncLog(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SyncLog::class)->latestOfMany();
    }

    /**
     * Get a specific credential value without exposing the full decrypted payload.
     * The encrypted:array cast handles decode automatically.
     */
    public function getCredential(string $key): mixed
    {
        return ($this->credentials_json ?? [])[$key] ?? null;
    }

    /**
     * Set a specific credential key without overwriting others.
     * The encrypted:array cast handles encode/encrypt automatically.
     */
    public function setCredential(string $key, mixed $value): void
    {
        $credentials       = $this->credentials_json ?? [];
        $credentials[$key] = $value;
        $this->credentials_json = $credentials;
        $this->save();
    }
}
