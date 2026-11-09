<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationOutcome extends Model
{
    use HasFactory;

    protected $fillable = [
        'recommendation_id',
        'finding_id',
        'implemented',
        'implemented_at',
        'estimated_impact',
        'actual_impact',
        'observed_revenue',
        'baseline_expected_revenue',
        'actual_incremental_impact',
        'attribution_method',
        'attribution_window_days',
        'orders_count',
        'is_zero_outcome',
        'matched_orders_json',
        'methodology_notes',
        'confidence_score',
        'verified_at',
        'outcome_notes',
    ];

    protected $casts = [
        'implemented'                 => 'boolean',
        'is_zero_outcome'             => 'boolean',
        'implemented_at'              => 'datetime',
        'verified_at'                 => 'datetime',
        'estimated_impact'            => 'decimal:2',
        'actual_impact'               => 'decimal:2',
        'observed_revenue'            => 'decimal:2',
        'baseline_expected_revenue'   => 'decimal:2',
        'actual_incremental_impact'   => 'decimal:2',
        'confidence_score'            => 'decimal:2',
        'attribution_window_days'     => 'integer',
        'orders_count'                => 'integer',
        'matched_orders_json'         => 'array',
    ];

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }
}
