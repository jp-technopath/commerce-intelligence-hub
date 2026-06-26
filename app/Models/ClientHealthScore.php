<?php

namespace App\Models;

use App\Enums\RiskLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientHealthScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'date',
        'health_score',
        'risk_level',
        'score_breakdown_json',
    ];

    protected $casts = [
        'date'                => 'date',
        'health_score'        => 'integer',
        'risk_level'          => RiskLevel::class,
        'score_breakdown_json' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isHealthy(): bool
    {
        return $this->risk_level === RiskLevel::Healthy;
    }

    public function requiresAttention(): bool
    {
        return in_array($this->risk_level, [
            RiskLevel::AttentionNeeded,
            RiskLevel::AtRisk,
            RiskLevel::Critical,
        ]);
    }
}
