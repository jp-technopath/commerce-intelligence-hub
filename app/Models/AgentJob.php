<?php

namespace App\Models;

use App\Enums\AgentJobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_identifier',
        'development_request_id',
        'project_environment_mapping_id',
        'correlation_identifier',
        'role',
        'status',
        'worker_service_account_email',
        'payload',
        'payload_hash',
        'attempt',
        'available_at',
        'claimed_at',
        'claimed_by_worker_identity',
        'claim_request_identifier',
        'lease_token_hash',
        'lease_token_ciphertext',
        'lease_expires_at',
        'last_heartbeat_at',
        'progress_percent',
        'progress_stage',
        'progress_message',
        'result',
        'failure',
        'cancellation_requested_at',
        'cancellation_reason',
        'cancelled_at',
        'completed_at',
        'details_pruned_at',
    ];

    protected $hidden = [
        'lease_token_hash',
        'lease_token_ciphertext',
    ];

    protected $casts = [
        'status' => AgentJobStatus::class,
        'payload' => 'array',
        'result' => 'array',
        'failure' => 'array',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'details_pruned_at' => 'datetime',
    ];

    public function developmentRequest(): BelongsTo
    {
        return $this->belongsTo(DevelopmentRequest::class);
    }

    public function projectEnvironmentMapping(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironmentMapping::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentJobEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'job_identifier';
    }
}
