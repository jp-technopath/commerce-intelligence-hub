<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ClientFindingConfiguration extends Model
{
    use HasFactory;

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'client_id',
        'version',
        'configuration_json',
        'schema_version',
        'rules_count',
        'status',
        'created_by',
        'activated_at',
    ];

    protected $casts = [
        'configuration_json' => 'array',
        'version'            => 'integer',
        'rules_count'        => 'integer',
        'activated_at'       => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ARCHIVED);
    }

    public function scopeForClient(Builder $query, int|Client $client): Builder
    {
        $clientId = $client instanceof Client ? $client->id : $client;
        return $query->where('client_id', $clientId);
    }

    /**
     * Activate this configuration version, archiving any previously active version.
     */
    public function activate(): bool
    {
        return DB::transaction(function () {
            // Archive any currently active configurations for this client
            static::where('client_id', $this->client_id)
                ->where('id', '!=', $this->id)
                ->where('status', self::STATUS_ACTIVE)
                ->update(['status' => self::STATUS_ARCHIVED]);

            $this->status       = self::STATUS_ACTIVE;
            $this->activated_at = now();

            return $this->save();
        });
    }

    /**
     * Rollback from this configuration to the immediately preceding version.
     */
    public function rollback(): ?self
    {
        return DB::transaction(function () {
            $previous = static::where('client_id', $this->client_id)
                ->where('version', '<', $this->version)
                ->orderBy('version', 'desc')
                ->first();

            if (! $previous) {
                return null;
            }

            // Archive current
            $this->status = self::STATUS_ARCHIVED;
            $this->save();

            // Activate previous
            $previous->status       = self::STATUS_ACTIVE;
            $previous->activated_at = now();
            $previous->save();

            return $previous;
        });
    }

    /**
     * Get the next version number for a client.
     */
    public static function nextVersionForClient(int $clientId): int
    {
        $max = static::where('client_id', $clientId)->max('version');
        return ($max ?? 0) + 1;
    }
}
