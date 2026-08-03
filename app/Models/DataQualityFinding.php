<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataQualityFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'integration_id',
        'finding_type',
        'affected_metric',
        'severity',
        'reporting_start',
        'reporting_end',
        'detection_rule',
        'supporting_values_json',
        'recommended_investigation',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
        'status',
        'calculation_version',
    ];

    protected $casts = [
        'reporting_start'        => 'datetime',
        'reporting_end'          => 'datetime',
        'first_detected_at'      => 'datetime',
        'last_detected_at'       => 'datetime',
        'resolved_at'            => 'datetime',
        'supporting_values_json' => 'array',
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
