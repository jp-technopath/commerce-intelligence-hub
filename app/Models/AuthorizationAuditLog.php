<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorizationAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id',
        'target_user_id',
        'action',
        'role_id',
        'client_id',
        'project_id',
        'changes',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
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

    /**
     * Helper to record an audit log entry.
     */
    public static function record(
        User $targetUser,
        string $action,
        ?User $actor = null,
        ?Role $role = null,
        ?Client $client = null,
        ?Project $project = null,
        ?array $changes = null
    ): self {
        return static::create([
            'actor_id'       => $actor?->id ?? auth()->id(),
            'target_user_id' => $targetUser->id,
            'action'         => $action,
            'role_id'        => $role?->id,
            'client_id'      => $client?->id,
            'project_id'     => $project?->id,
            'changes'        => $changes,
            'ip_address'     => request()?->ip(),
            'user_agent'     => request()?->userAgent(),
        ]);
    }
}
