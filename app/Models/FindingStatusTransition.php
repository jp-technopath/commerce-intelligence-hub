<?php

namespace App\Models;

use App\Enums\FindingExecutionStatus;
use App\Enums\FindingOutcomeStatus;
use App\Enums\FindingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FindingStatusTransition extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'finding_id',
        'from_status',
        'to_status',
        'from_execution_status',
        'to_execution_status',
        'from_outcome_status',
        'to_outcome_status',
        'user_id',
        'user_name',
        'transition_source',
        'notes',
        'business_outcome',
        'created_at',
    ];

    protected $casts = [
        'from_status'           => FindingStatus::class,
        'to_status'             => FindingStatus::class,
        'from_execution_status' => FindingExecutionStatus::class,
        'to_execution_status'   => FindingExecutionStatus::class,
        'from_outcome_status'   => FindingOutcomeStatus::class,
        'to_outcome_status'     => FindingOutcomeStatus::class,
        'created_at'            => 'datetime',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
