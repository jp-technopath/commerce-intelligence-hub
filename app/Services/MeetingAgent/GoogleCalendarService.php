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
    /**
     * Scan and sync upcoming and recent client meetings into the database.
     *
     * @return Collection<int, ClientMeeting> Affected meeting records
     */
    public function syncUpcomingClientMeetings(): Collection
    {
        $scanDays = (int) config('meeting_agent.calendar.scan_days_ahead', 7);
        // Include past 14 days so past/completed meetings are recovered and updated
        $from = now()->subDays(14)->startOfDay();
        $to = now()->addDays($scanDays)->endOfDay();

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

        $summary = $event->getSummary() ?? 'Untitled Meeting';

        // Try to match a client by external attendee email domain or event title
        $clientId = $this->matchClient($externalAttendees, $summary);

        // Determine status
        $status = MeetingStatus::Detected;
        if ($event->getStatus() === 'cancelled') {
            $status = MeetingStatus::Canceled;
        } elseif ($clientId === null) {
            $status = MeetingStatus::NeedsMapping;
        }

        // Parse start/end times in UTC
        $startDateTime = $event->getStart()?->getDateTime() ?? $event->getStart()?->getDate();
        $endDateTime = $event->getEnd()?->getDateTime() ?? $event->getEnd()?->getDate();
        $startCarbon = $startDateTime ? Carbon::parse($startDateTime)->setTimezone('UTC') : null;
        $endCarbon = $endDateTime ? Carbon::parse($endDateTime)->setTimezone('UTC') : null;
        $timezone = $event->getStart()?->getTimeZone() ?? config('app.timezone', 'UTC');

        $iCalUid = $event->getICalUID();
        $eventId = $event->getId();
        $existingMeeting = null;

        // 1. Primary Match: exact Google Event ID (global across all users scanning the same event)
        if ($eventId) {
            $existingMeeting = ClientMeeting::where('google_event_id', $eventId)->first();
        }

        // 2. Secondary Match: iCalUID (exact or base prefix) + start time window (+/- 15 min for timezone/occurrence safety)
        if (! $existingMeeting && $iCalUid && $startCarbon) {
            $baseUid = explode('_R', $iCalUid)[0];
            $existingMeeting = ClientMeeting::where(function ($q) use ($iCalUid, $baseUid) {
                    $q->where('google_ical_uid', $iCalUid)
                      ->orWhere('google_ical_uid', 'like', $baseUid . '%');
                })
                ->whereBetween('meeting_start_at', [
                    $startCarbon->copy()->subMinutes(15),
                    $startCarbon->copy()->addMinutes(15)
                ])
                ->first();
        }

        // 3. Fallback Match: exact title & start time window (+/- 15 min)
        if (! $existingMeeting && $startCarbon) {
            $existingMeeting = ClientMeeting::whereRaw('LOWER(title) = ?', [strtolower(trim($summary))])
                ->whereBetween('meeting_start_at', [
                    $startCarbon->copy()->subMinutes(15),
                    $startCarbon->copy()->addMinutes(15)
                ])
                ->first();
        }

        // Resolve event organizer and internal owner
        $organizerEmail = $event->getOrganizer()?->getEmail() ?? $event->getCreator()?->getEmail();
        $organizerUser = null;
        if ($organizerEmail) {
            $organizerUser = User::whereRaw('LOWER(email) = ?', [strtolower(trim($organizerEmail))])->first();
        }

        // Determine owner ID: prefer event organizer User, then internal organizer attendee (e.g. Nour), then internal attendee User, then scanner
        if ($organizerUser) {
            $ownerId = $organizerUser->id;
        } else {
            $matchedUser = null;

            // Prioritize Nour if in internal attendees
            $nourUser = User::whereIn('email', ['nour@technopath.co', 'nour@technopath.ai'])->first();
            $internalEmails = array_map(fn ($a) => strtolower(trim($a['email'] ?? '')), $internalAttendees);
            if ($nourUser && (in_array('nour@technopath.co', $internalEmails, true) || in_array('nour@technopath.ai', $internalEmails, true))) {
                $matchedUser = $nourUser;
            } else {
                foreach ($internalAttendees as $intAtt) {
                    if (! empty($intAtt['email'])) {
                        $u = User::whereRaw('LOWER(email) = ?', [strtolower(trim($intAtt['email']))])->first();
                        if ($u) {
                            $matchedUser = $u;
                            break;
                        }
                    }
                }
            }

            $ownerId = $matchedUser ? $matchedUser->id : $this->user->id;
        }

        // Extract meeting links and organizer metadata
        $meetLink = $event->getHangoutLink() ?? $event->getConferenceData()?->getEntryPoints()[0]?->getUri();
        $htmlLink = $event->getHtmlLink();
        $eventMeta = array_filter([
            'organizer_email' => $organizerEmail,
            'creator_email'   => $event->getCreator()?->getEmail(),
            'meet_link'       => $meetLink,
            'html_link'       => $htmlLink,
        ]);

        if ($existingMeeting) {
            // Merge attendee lists across attendees' scans
            $mergedExternal = $this->mergeAttendees($existingMeeting->external_attendees ?? [], $externalAttendees);
            $mergedInternal = $this->mergeAttendees($existingMeeting->internal_attendees ?? [], $internalAttendees);

            $projectKey = $existingMeeting->project_key;
            $targetClientId = $existingMeeting->client_id ?? $clientId;

            if (empty($projectKey) && $targetClientId) {
                $clientModel = Client::find($targetClientId);
                if ($clientModel && ! empty($clientModel->jira_project_key)) {
                    $projectKey = $clientModel->jira_project_key;
                }
            }

            // Update owner_id if organizerUser is found, otherwise keep existing owner_id if already set
            $finalOwnerId = $organizerUser ? $organizerUser->id : ($existingMeeting->internal_owner_id ?? $ownerId);
            $mergedMeta = array_merge($existingMeeting->metadata ?? [], $eventMeta);

            $existingMeeting->update([
                'google_ical_uid'    => $iCalUid ?: $existingMeeting->google_ical_uid,
                'google_event_id'    => $event->getId(),
                'google_calendar_id' => 'primary',
                'title'              => $summary,
                'meeting_start_at'   => $startCarbon ?? $existingMeeting->meeting_start_at,
                'meeting_end_at'     => $endCarbon ?? $existingMeeting->meeting_end_at,
                'timezone'           => $timezone,
                'client_id'          => $targetClientId,
                'project_key'        => $projectKey,
                'internal_owner_id'  => $finalOwnerId,
                'external_attendees' => $mergedExternal,
                'internal_attendees' => $mergedInternal,
                'metadata'           => $mergedMeta,
                'status'             => ($existingMeeting->status === MeetingStatus::Canceled || $status === MeetingStatus::Canceled) ? $status : $existingMeeting->status,
            ]);

            return $existingMeeting;
        }

        $projectKey = null;
        if ($clientId) {
            $clientModel = Client::find($clientId);
            if ($clientModel && ! empty($clientModel->jira_project_key)) {
                $projectKey = $clientModel->jira_project_key;
            }
        }

        return ClientMeeting::create([
            'scanned_by_user_id' => $this->user->id,
            'google_calendar_id' => 'primary',
            'google_event_id'    => $event->getId(),
            'google_ical_uid'    => $iCalUid,
            'title'              => $summary,
            'meeting_start_at'   => $startCarbon,
            'meeting_end_at'     => $endCarbon,
            'timezone'           => $timezone,
            'client_id'          => $clientId,
            'project_key'        => $projectKey,
            'internal_owner_id'  => $ownerId,
            'external_attendees' => $externalAttendees,
            'internal_attendees' => $internalAttendees,
            'metadata'           => $eventMeta,
            'status'             => $status,
            'source'             => MeetingSource::GoogleCalendar,
        ]);
    }

    /**
     * Helper to merge attendee lists, deduplicating by email address.
     */
    private function mergeAttendees(array $existing, array $new): array
    {
        $byEmail = [];
        foreach ($existing as $item) {
            if (! empty($item['email'])) {
                $byEmail[strtolower($item['email'])] = $item;
            }
        }
        foreach ($new as $item) {
            if (! empty($item['email'])) {
                $emailLower = strtolower($item['email']);
                if (! isset($byEmail[$emailLower]) || empty($byEmail[$emailLower]['name'])) {
                    $byEmail[$emailLower] = $item;
                }
            }
        }
        return array_values($byEmail);
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
