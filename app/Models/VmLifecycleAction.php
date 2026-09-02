<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VmLifecycleAction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'vm_runtime_state_id',
        'development_request_id',
        'action',
        'outcome',
        'gcp_operation_id',
        'actor_type',
        'actor_label',
        'reason',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('VM lifecycle actions are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('VM lifecycle actions are append-only.');
        });
    }

    public function runtimeState(): BelongsTo
    {
        return $this->belongsTo(VmRuntimeState::class, 'vm_runtime_state_id');
    }

    public function developmentRequest(): BelongsTo
    {
        return $this->belongsTo(DevelopmentRequest::class);
    }
}
