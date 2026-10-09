<?php

namespace App\Enums;

enum FindingCategory: string
{
    case Revenue       = 'Revenue';
    case Conversion    = 'Conversion';
    case Behavioral    = 'Behavioral';
    case Search        = 'Search';
    case Checkout      = 'Checkout';
    case Customer      = 'Customer';
    case Technical     = 'Technical';
    case Merchandising = 'Merchandising';

    // Operational & Delivery categories
    case WorkPriority       = 'WorkPriority';
    case WorkflowDelay      = 'WorkflowDelay';
    case ResponseTime       = 'ResponseTime';
    case ProjectRisk        = 'ProjectRisk';
    case TimeTracking       = 'TimeTracking';
    case MeetingPreparation = 'MeetingPreparation';
    case MeetingFollowUp    = 'MeetingFollowUp';

    public function label(): string
    {
        return match ($this) {
            self::Revenue            => 'Revenue',
            self::Conversion         => 'Conversion',
            self::Behavioral         => 'Behavioral',
            self::Search             => 'Search',
            self::Checkout           => 'Checkout',
            self::Customer           => 'Customer',
            self::Technical          => 'Technical',
            self::Merchandising      => 'Merchandising',
            self::WorkPriority       => 'Work Priority',
            self::WorkflowDelay      => 'Workflow Delay',
            self::ResponseTime       => 'Response Time',
            self::ProjectRisk        => 'Project Risk',
            self::TimeTracking       => 'Time Tracking',
            self::MeetingPreparation => 'Meeting Prep',
            self::MeetingFollowUp    => 'Meeting Follow-Up',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Revenue            => 'success',
            self::Conversion         => 'warning',
            self::Behavioral         => 'info',
            self::Search             => 'primary',
            self::Checkout           => 'danger',
            self::Customer           => 'secondary',
            self::Technical          => 'gray',
            self::Merchandising      => 'primary',
            self::WorkPriority       => 'primary',
            self::WorkflowDelay      => 'danger',
            self::ResponseTime       => 'warning',
            self::ProjectRisk        => 'danger',
            self::TimeTracking       => 'info',
            self::MeetingPreparation => 'warning',
            self::MeetingFollowUp    => 'info',
        };
    }
}
