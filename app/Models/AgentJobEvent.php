<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AgentJobEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agent_job_id',
        'operation',
        'event_type',
        'worker_identity',
        'request_identifier',
        'request_payload_hash',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function agentJob(): BelongsTo
    {
        return $this->belongsTo(AgentJob::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Agent job events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Agent job events are append-only.');
        });
    }
}
