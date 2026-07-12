<?php

namespace App\Jobs\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Services\MeetingAgent\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scan all active Google Workspace accounts for upcoming client meetings.
 * Runs per-user and catches exceptions to continue processing remaining accounts.
 */
class ScanUpcomingClientMeetings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('meetings');
    }

    public function handle(): void
    {
        $accounts = ConnectedAccount::where('provider', 'google_workspace')
            ->where('status', ConnectedAccountStatus::Active)
            ->with('user')
            ->get();

        Log::info('ScanUpcomingClientMeetings: starting scan', [
            'account_count' => $accounts->count(),
        ]);

        foreach ($accounts as $account) {
            try {
                $service = new GoogleCalendarService($account->user);
                $meetings = $service->syncUpcomingClientMeetings();

                Log::info('ScanUpcomingClientMeetings: synced meetings for user', [
                    'user_id'       => $account->user_id,
                    'meetings_found' => $meetings->count(),
                ]);
            } catch (\Exception $e) {
                Log::error('ScanUpcomingClientMeetings: failed for user', [
                    'user_id' => $account->user_id,
                    'error'   => $e->getMessage(),
                ]);
                // Continue to next user — don't let one failure stop the scan
            }
        }
    }
}
