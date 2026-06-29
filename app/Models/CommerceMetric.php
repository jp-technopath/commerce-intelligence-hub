<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'date',
        'source',
        'revenue',
        'orders',
        'items_sold',
        'conversion_rate',
        'average_order_value',
        'aov',
        'sessions',
        'active_users',
        'new_customers',
        'returning_customers',
        'source_breakdown_json',
        'device_breakdown_json',
        'metadata_json',
    ];

    protected $casts = [
        'date'                  => 'date',
        'revenue'               => 'decimal:2',
        'conversion_rate'       => 'decimal:4',
        'average_order_value'   => 'decimal:2',
        'aov'                   => 'decimal:2',
        'source_breakdown_json' => 'array',
        'device_breakdown_json' => 'array',
        'metadata_json'         => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
