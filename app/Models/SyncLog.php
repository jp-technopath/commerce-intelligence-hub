<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_id',
        'status',
        'records_processed',
        'error_message',
        'metadata_json',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status'       => SyncStatus::class,
        'metadata_json' => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function durationInSeconds(): ?int
    {
        if ($this->started_at && $this->completed_at) {
            return (int) $this->started_at->diffInSeconds($this->completed_at);
        }

        return null;
    }
}
