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
        'implemented',
        'implemented_at',
        'estimated_impact',
        'actual_impact',
        'outcome_notes',
    ];

    protected $casts = [
        'implemented'      => 'boolean',
        'implemented_at'   => 'datetime',
        'estimated_impact' => 'decimal:2',
        'actual_impact'    => 'decimal:2',
    ];

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }
}
