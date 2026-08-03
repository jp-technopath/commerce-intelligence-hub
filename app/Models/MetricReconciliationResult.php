<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricReconciliationResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'reporting_start',
        'reporting_end',
        'adobe_transaction_count',
        'ga4_transaction_count',
        'matched_transaction_count',
        'missing_in_ga4_count',
        'missing_in_adobe_count',
        'duplicate_ga4_count',
        'adobe_net_revenue',
        'ga4_tracked_revenue',
        'absolute_difference',
        'percentage_difference',
        'validation_status',
        'calculation_version',
        'metadata_json',
    ];

    protected $casts = [
        'reporting_start'       => 'datetime',
        'reporting_end'         => 'datetime',
        'adobe_net_revenue'     => 'decimal:2',
        'ga4_tracked_revenue'   => 'decimal:2',
        'absolute_difference'   => 'decimal:2',
        'percentage_difference' => 'decimal:4',
        'metadata_json'         => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
