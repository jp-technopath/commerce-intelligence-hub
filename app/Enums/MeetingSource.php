<?php

namespace App\Enums;

enum MeetingSource: string
{
    case Manual         = 'manual';
    case GoogleCalendar = 'google_calendar';

    public function label(): string
    {
        return match ($this) {
            self::Manual         => 'Manual',
            self::GoogleCalendar => 'Google Calendar',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Manual         => 'gray',
            self::GoogleCalendar => 'primary',
        };
    }
}
