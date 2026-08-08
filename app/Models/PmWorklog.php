<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmWorklog extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'pm_connection_id',
        'pm_work_item_id',
        'external_worklog_id',
        'author_name',
        'time_spent_seconds',
        'worklog_started_at',
        'external_created_at',
        'external_updated_at',
        'last_synced_at',
    ];

    protected $casts = [
        'worklog_started_at'  => 'datetime',
        'external_created_at' => 'datetime',
        'external_updated_at' => 'datetime',
        'last_synced_at'      => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PmConnection::class, 'pm_connection_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(PmWorkItem::class, 'pm_work_item_id');
    }

    public function getTimeSpentHoursAttribute(): float
    {
        return round($this->time_spent_seconds / 3600, 1);
    }
}
