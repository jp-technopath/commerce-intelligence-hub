<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'finding_id',
        'recommendation_text',
        'ai_summary',
        'confidence_reasoning',
        'model_used',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    public function outcome(): HasOne
    {
        return $this->hasOne(RecommendationOutcome::class);
    }
}
