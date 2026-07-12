<?php

namespace App\Console\Commands;

use App\Jobs\MeetingAgent\ScanUpcomingClientMeetings;
use Illuminate\Console\Command;

class ScanUpcomingMeetingsCommand extends Command
{
    protected $signature = 'customer-meetings:scan-upcoming';

    protected $description = 'Scan connected Google Calendars for upcoming customer meetings';

    public function handle(): int
    {
        $this->info('Dispatching calendar scan job...');

        ScanUpcomingClientMeetings::dispatch();

        $this->info('Calendar scan job dispatched to the "meetings" queue.');

        return self::SUCCESS;
    }
}
