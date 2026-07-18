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
        $settings = isset($this->account) ? ($this->account->settings_json ?? []) : [];

        $scanMode = $settings['scan_mode'] ?? 'auto';
        
        $includeKeywords = $settings['include_keywords'] ?? null;
        if ($includeKeywords === null) {
            $includeKeywords = ['#client', '#customer', '#customer-meeting'];
            $configHashtag = config('meeting_agent.calendar.include_hashtag');
            if ($configHashtag && ! in_array(strtolower($configHashtag), array_map('strtolower', $includeKeywords), true)) {
                $includeKeywords[] = $configHashtag;
            }
        }

        $excludeKeywords = $settings['exclude_keywords'] ?? [];
        $skipInternal = $settings['skip_internal'] ?? false;
        $skipWithoutExternal = $settings['skip_without_external'] ?? false;

        $title = $event->getSummary() ?? '';
        $description = $event->getDescription() ?? '';
        $titleAndDesc = $title . ' ' . $description;

        $companyDomains = config('meeting_agent.calendar.company_domains', []);

        // 1. Explicit inclusion hashtags take absolute highest priority (override exclusions and attendee skips)
        foreach ($includeKeywords as $hashtag) {
            if (empty($hashtag)) {
                continue;
            }
            if (stripos($titleAndDesc, $hashtag) !== false) {
                return true;
            }
        }

        // 2. Check exclusion keywords/regex
        if (! empty($excludeKeywords)) {
            foreach ($excludeKeywords as $kw) {
                if (empty($kw)) {
                    continue;
                }
                // Support regex if formatted like /pattern/i, otherwise use literal case-insensitive match
                if (str_starts_with($kw, '/') && preg_match('/\/[a-zA-Z]*$/', $kw)) {
                    if (@preg_match($kw, $title)) {
                        return false;
                    }
                } else {
                    if (stripos($title, $kw) !== false) {
                        return false;
                    }
                }
            }
        } else {
            // Fallback to static exclusion patterns from config if no custom keywords are saved
            $excludePatterns = config('meeting_agent.calendar.exclude_patterns', []);
            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $title)) {
                    return false;
                }
            }
        }

        // Parse attendees to check internal/external status
        $attendees = $event->getAttendees() ?? [];
        $hasExternalAttendee = false;
        $hasOnlyInternal = true;

        foreach ($attendees as $attendee) {
            $email = $attendee->getEmail() ?? '';
            $domain = substr(strrchr($email, '@'), 1);

            if ($domain) {
                $isInternal = in_array(strtolower($domain), array_map('strtolower', $companyDomains), true);
                if (! $isInternal) {
                    $hasExternalAttendee = true;
                    $hasOnlyInternal = false;
                }
            }
        }

        // 3. Skip meetings without external attendees (if option is enabled)
        if ($skipWithoutExternal && ! $hasExternalAttendee) {
            return false;
        }

        // 4. Skip internal-only meetings (if option is enabled)
        if ($skipInternal && ! empty($attendees) && $hasOnlyInternal) {
            return false;
        }

        // 5. Scanning mode logic
        if ($scanMode === 'hashtag') {
            // Must contain at least one inclusion keyword / hashtag
            // (We already checked hashtags in Step 1, but if we got here, none matched)
            return false;
        }

        // Check for known client names in title/description
        $knownClients = Client::pluck('name')->filter()->toArray();
        foreach ($knownClients as $clientName) {
            if (stripos($titleAndDesc, $clientName) !== false) {
                return true;
            }
        }

        // If has external attendees, scan it!
        if ($hasExternalAttendee) {
            return true;
        }

        // By default, if there are no external attendees and no keywords matched, return true unless skip_without_external is enabled (which we handled above)
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

        $existingMeeting = ClientMeeting::where('scanned_by_user_id', $this->user->id)
            ->where('google_calendar_id', 'primary')
            ->where('google_event_id', $event->getId())
            ->first();

        $projectKey = $existingMeeting?->project_key;
        if (empty($projectKey) && $clientId) {
            $clientModel = Client::find($clientId);
            if ($clientModel && ! empty($clientModel->jira_project_key)) {
                $projectKey = $clientModel->jira_project_key;
            }
        }

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
                'project_key'        => $projectKey,
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
