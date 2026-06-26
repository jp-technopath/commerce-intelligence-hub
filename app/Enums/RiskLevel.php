<?php

namespace App\Enums;

enum RiskLevel: string
{
    case Healthy         = 'healthy';
    case AttentionNeeded = 'attention_needed';
    case AtRisk          = 'at_risk';
    case Critical        = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Healthy         => 'Healthy',
            self::AttentionNeeded => 'Attention Needed',
            self::AtRisk          => 'At Risk',
            self::Critical        => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy         => 'success',
            self::AttentionNeeded => 'warning',
            self::AtRisk          => 'danger',
            self::Critical        => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Healthy         => 'heroicon-o-check-circle',
            self::AttentionNeeded => 'heroicon-o-exclamation-triangle',
            self::AtRisk          => 'heroicon-o-exclamation-circle',
            self::Critical        => 'heroicon-o-fire',
        };
    }

    public static function fromScore(int $score): self
    {
        $levels = config('intelligence.risk_levels');

        return match (true) {
            $score >= $levels['healthy']          => self::Healthy,
            $score >= $levels['attention_needed'] => self::AttentionNeeded,
            $score >= $levels['at_risk']          => self::AtRisk,
            default                               => self::Critical,
        };
    }
}
