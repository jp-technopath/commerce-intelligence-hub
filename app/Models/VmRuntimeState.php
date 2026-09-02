<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class VmRuntimeState extends Model
{
    use HasFactory;

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_STARTING = 'starting';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WORKER_READY = 'worker_ready';

    public const STATUS_STOPPING = 'stopping';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'target_key',
        'gcp_project_id',
        'gcp_zone',
        'vm_name',
        'status',
        'worker_identifier',
        'worker_state',
        'last_worker_heartbeat_at',
        'last_activity_at',
        'idle_since',
        'start_requested_at',
        'stop_requested_at',
        'last_operation_id',
        'last_error_code',
        'manual_override_action',
        'manual_override_at',
        'manual_override_by',
    ];

    protected $casts = [
        'last_worker_heartbeat_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'idle_since' => 'datetime',
        'start_requested_at' => 'datetime',
        'stop_requested_at' => 'datetime',
        'manual_override_at' => 'datetime',
    ];

    public function lifecycleActions(): HasMany
    {
        return $this->hasMany(VmLifecycleAction::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('VM runtime state is retained for lifecycle audit history.');
        });
    }
}
