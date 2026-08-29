<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerApiRequest extends Model
{
    protected $fillable = [
        'request_identifier',
        'worker_identity',
        'operation',
        'request_payload_hash',
        'response_status',
        'response_body_ciphertext',
        'requested_at',
        'expires_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
