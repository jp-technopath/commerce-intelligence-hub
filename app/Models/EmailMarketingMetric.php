<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMarketingMetric extends Model
{
    protected $fillable = [
        'client_id',
        'date',
        'source',
        'type',
        'channel',
        'campaign_name',
        'flow_id',
        'recipients',
        'opens',
        'clicks',
        'conversions',
        'revenue',
        'unsubscribes',
        'bounces',
        'open_rate',
        'click_rate',
        'metadata_json',
    ];

    protected $casts = [
        'date'          => 'date',
        'revenue'       => 'decimal:2',
        'open_rate'     => 'decimal:4',
        'click_rate'    => 'decimal:4',
        'metadata_json' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
