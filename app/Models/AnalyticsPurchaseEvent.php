<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsPurchaseEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'integration_id',
        'source',
        'transaction_id',
        'event_date',
        'event_timestamp',
        'tracked_revenue',
        'currency',
        'user_pseudo_id',
        'is_duplicate',
        'duplicate_reason',
        'source_updated_at',
        'collected_at',
        'metadata_json',
    ];

    protected $casts = [
        'event_date'        => 'date',
        'event_timestamp'   => 'datetime',
        'source_updated_at' => 'datetime',
        'collected_at'      => 'datetime',
        'tracked_revenue'   => 'decimal:2',
        'is_duplicate'      => 'boolean',
        'metadata_json'     => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
