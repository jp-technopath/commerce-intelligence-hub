<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WorkerApiSecurityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'worker_identity',
        'request_identifier',
        'operation',
        'reason_code',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Worker API security events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Worker API security events are append-only.');
        });
    }
}
