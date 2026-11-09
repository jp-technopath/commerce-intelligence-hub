<?php

namespace App\Enums;

enum TargetBusinessRole: string
{
    case Sales             = 'sales';
    case Marketing         = 'marketing';
    case Engineering       = 'engineering';
    case Analytics         = 'analytics';
    case ProjectManagement = 'pm';

    public function label(): string
    {
        return match ($this) {
            self::Sales             => 'Sales & Account Management',
            self::Marketing         => 'Growth Marketing',
            self::Engineering       => 'Engineering',
            self::Analytics         => 'Analytics',
            self::ProjectManagement => 'Project Management',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sales             => 'success',
            self::Marketing         => 'info',
            self::Engineering       => 'primary',
            self::Analytics         => 'warning',
            self::ProjectManagement => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Sales             => 'heroicon-o-briefcase',
            self::Marketing         => 'heroicon-o-megaphone',
            self::Engineering       => 'heroicon-o-code-bracket',
            self::Analytics         => 'heroicon-o-chart-pie',
            self::ProjectManagement => 'heroicon-o-clipboard-document-list',
        };
    }
}
