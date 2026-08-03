<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserRoleAssignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'role_id',
        'client_id',
        'project_id',
        'repository_id',
        'repository_url',
        'created_by',
        'starts_at',
        'expires_at',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'notes',
    ];

    protected $casts = [
        'starts_at'      => 'datetime',
        'expires_at'     => 'datetime',
        'deactivated_at' => 'datetime',
        'is_active'      => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    /**
     * Scope to return currently active and non-expired assignments.
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            });
    }

    /**
     * Scope for a specific client.
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope for a specific project.
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Deactivate this assignment safely preserving audit history.
     */
    public function deactivate(?int $deactivatedBy = null, ?string $reason = null): void
    {
        $this->update([
            'is_active'      => false,
            'deactivated_at' => now(),
            'deactivated_by' => $deactivatedBy,
            'notes'          => $reason ? ($this->notes ? $this->notes . "\n" . $reason : $reason) : $this->notes,
        ]);
    }
}
