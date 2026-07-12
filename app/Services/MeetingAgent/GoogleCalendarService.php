<?php

namespace App\Services\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Enums\MeetingSource;
use App\Enums\MeetingStatus;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Calendar as Google_Service_Calendar;
use Google\Service\Calendar\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Calendar integration service for scanning and syncing
 * upcoming client meetings from a user's connected workspace.
 */
class GoogleCalendarService
{
    private ConnectedAccount $account;
    private Google_Service_Calendar $calendarService;
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;

        $this->account = ConnectedAccount::where('user_id', $user->id)
            ->where('provider', 'google_workspace')
            ->where('status', ConnectedAccountStatus::Active)
            ->firstOrFail();

        $credentials = $this->account->credentials_json ?? [];
        $refreshToken = $credentials['refresh_token'] ?? null;

        if (! $refreshToken) {
            throw new RuntimeException("No refresh token found for user {$user->id} Google Workspace account.");
        }

        $client = $this->buildGoogleClient($refreshToken);
        $this->calendarService = new Google_Service_Calendar($client);
    }

    /**
     * Fetch upcoming calendar events within the given date range.
     */
    public function getUpcomingEvents(Carbon $from, Carbon $to): array
    {
        try {
            $events = $this->calendarService->events->listEvents('primary', [
                'timeMin'      => $from->toRfc3339String(),
                'timeMax'      => $to->toRfc3339String(),
                'singleEvents' => true,
                'orderBy'      => 'startTime',
                'maxResults'   => 250,
            ]);

            return $events->getItems() ?? [];
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleApiError($e);
            throw $e;
        }
    }

    /**
     * Determine if a calendar event is likely a client meeting.
     */
    public function isLikelyClientMeeting(Event $event): bool
    {
        $companyDomains = config('meeting_agent.calendar.company_domains', []);
        $excludePatterns = config('meeting_agent.calendar.exclude_patterns', []);
        $includeHashtag = config('meeting_agent.calendar.include_hashtag', '#client');

        $title = $event->getSummary() ?? '';
        $description = $event->getDescription() ?? '';
        $titleAndDesc = $title . ' ' . $description;

        // Check for explicit include hashtags
        $includeHashtags = ['#client', '#customer', '#customer-meeting'];
        if ($includeHashtag && ! in_array(strtolower($includeHashtag), $includeHashtags, true)) {
            $includeHashtags[] = $includeHashtag;
        }

        foreach ($includeHashtags as $hashtag) {
            if (stripos($titleAndDesc, $hashtag) !== false) {
                return true;
            }
        }

        // Check for known client names in title/description
        $knownClients = Client::pluck('name')->filter()->toArray();
        foreach ($knownClients as $clientName) {
            if (stripos($titleAndDesc, $clientName) !== false) {
                return true;
            }
        }

        // Check exclusion patterns
        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $title)) {
                return false;
            }
        }

        // Check for external attendees (not in company domains)
        $attendees = $event->getAttendees() ?? [];
        $hasExternalAttendee = false;

        foreach ($attendees as $attendee) {
            $email = $attendee->getEmail() ?? '';
            $domain = substr(strrchr($email, '@'), 1);

            if ($domain && ! in_array(strtolower($domain), array_map('strtolower', $companyDomains), true)) {
                $hasExternalAttendee = true;
                break;
            }
        }

        // By default, if the meeting has survived the exclusion patterns, sync it automatically!
        return true;
    }

    /**
     * Scan and sync upcoming client meetings into the database.
     *
     * @return Collection<int, ClientMeeting> Affected meeting records
     */
    public function syncUpcomingClientMeetings(): Collection
    {
        $scanDays = (int) config('meeting_agent.calendar.scan_days_ahead', 7);
        $from = now()->startOfDay();
        $to = now()->addDays($scanDays);

        $events = $this->getUpcomingEvents($from, $to);
        $affectedMeetings = collect();

        foreach ($events as $event) {
            if (! $this->isLikelyClientMeeting($event)) {
                continue;
            }

            try {
                $meeting = $this->upsertMeetingFromEvent($event);
                $affectedMeetings->push($meeting);
            } catch (\Exception $e) {
                Log::warning('GoogleCalendarService: failed to upsert meeting from event', [
                    'event_id' => $event->getId(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $affectedMeetings;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    private function buildGoogleClient(string $refreshToken): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setAccessType('offline');

        try {
            $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (\Exception $e) {
            $this->account->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Token refresh failed: ' . $e->getMessage(),
            ]);
            throw new RuntimeException("Failed to refresh Google token for user {$this->user->id}: " . $e->getMessage());
        }

        if ($client->isAccessTokenExpired()) {
            $this->account->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Access token expired after refresh attempt.',
            ]);
            throw new RuntimeException("Google access token expired after refresh for user {$this->user->id}.");
        }

        return $client;
    }

    private function upsertMeetingFromEvent(Event $event): ClientMeeting
    {
        $companyDomains = config('meeting_agent.calendar.company_domains', []);
        $attendees = $event->getAttendees() ?? [];

        $externalAttendees = [];
        $internalAttendees = [];

        foreach ($attendees as $attendee) {
            $email = $attendee->getEmail() ?? '';
            $name = $attendee->getDisplayName() ?? $email;
            $domain = substr(strrchr($email, '@'), 1);
            $isInternal = $domain && in_array(strtolower($domain), array_map('strtolower', $companyDomains), true);

            $entry = ['email' => $email, 'name' => $name];

            if ($isInternal) {
                $internalAttendees[] = $entry;
            } else {
                $externalAttendees[] = $entry;
            }
        }

        // Try to match a client by external attendee email domain or event title
        $clientId = $this->matchClient($externalAttendees, $event->getSummary() ?? '');

        // Determine status
        $status = MeetingStatus::Detected;
        if ($event->getStatus() === 'cancelled') {
            $status = MeetingStatus::Canceled;
        } elseif ($clientId === null) {
            $status = MeetingStatus::NeedsMapping;
        }

        // Parse start/end times
        $startDateTime = $event->getStart()?->getDateTime() ?? $event->getStart()?->getDate();
        $endDateTime = $event->getEnd()?->getDateTime() ?? $event->getEnd()?->getDate();
        $timezone = $event->getStart()?->getTimeZone() ?? config('app.timezone', 'UTC');

        $meeting = ClientMeeting::updateOrCreate(
            [
                'scanned_by_user_id' => $this->user->id,
                'google_calendar_id' => 'primary',
                'google_event_id'    => $event->getId(),
            ],
            [
                'google_ical_uid'    => $event->getICalUID(),
                'title'              => $event->getSummary() ?? 'Untitled Meeting',
                'meeting_start_at'   => $startDateTime ? Carbon::parse($startDateTime) : null,
                'meeting_end_at'     => $endDateTime ? Carbon::parse($endDateTime) : null,
                'timezone'           => $timezone,
                'client_id'          => $clientId,
                'internal_owner_id'  => $this->user->id,
                'external_attendees' => $externalAttendees,
                'internal_attendees' => $internalAttendees,
                'status'             => $status,
                'source'             => MeetingSource::GoogleCalendar,
            ]
        );

        return $meeting;
    }

    /**
     * Attempt to match a Client record by attendee email domain or event title.
     */
    private function matchClient(array $externalAttendees, string $title): ?int
    {
        // Try matching by client name in the event title
        $clients = Client::all(['id', 'name']);
        foreach ($clients as $client) {
            if (stripos($title, $client->name) !== false) {
                return $client->id;
            }
        }

        // Try matching by attendee email domain
        // (Would require a client_domains table or similar — fallback to null for now)
        // TODO: Implement domain-based client matching when client email domains are stored

        return null;
    }

    private function handleGoogleApiError(\Google\Service\Exception $e): void
    {
        if ($e->getCode() === 401 || $e->getCode() === 403) {
            $this->account->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Google API auth error (' . $e->getCode() . '): ' . $e->getMessage(),
            ]);
        }

        Log::error('GoogleCalendarService: Google API error', [
            'user_id' => $this->user->id,
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
        ]);
    }
}
