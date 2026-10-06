<?php

namespace App\Services\MeetingAgent;

use App\Models\ClientMeeting;

class MeetingContextResolver
{
    /**
     * Resolves the primary client contact name using a structured hierarchy:
     * 1. Explicit meeting primary contact from metadata / configuration.
     * 2. First named external attendee.
     * 3. Fallback token: '[Not Provided]'.
     */
    public function resolveClientContactName(ClientMeeting $meeting): string
    {
        // 1. Explicit meeting primary contact if configured in metadata
        $metadata = $meeting->metadata ?? [];
        if (! empty($metadata['primary_contact_name']) && is_string($metadata['primary_contact_name'])) {
            return trim($metadata['primary_contact_name']);
        }

        // 2. First named external attendee
        $externalAttendees = $meeting->external_attendees ?? [];
        if (is_array($externalAttendees)) {
            foreach ($externalAttendees as $attendee) {
                if (is_array($attendee) && ! empty($attendee['name']) && is_string($attendee['name'])) {
                    $name = trim($attendee['name']);
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        }

        // 3. Fallback token
        return '[Not Provided]';
    }

    /**
     * Format attendees into a comma-delimited string with internal and external indicators.
     */
    public function resolveAttendeesString(ClientMeeting $meeting): string
    {
        $internal = collect($meeting->internal_attendees ?? [])
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => trim($name) . ' (internal)');

        $external = collect($meeting->external_attendees ?? [])
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => trim($name) . ' (external)');

        $all = $internal->merge($external)->values();

        return $all->isNotEmpty() ? $all->implode(', ') : 'No attendees listed';
    }

    /**
     * Builds the complete dynamic variable dictionary for pre-meeting prep.
     */
    public function buildMeetingPrepVariables(ClientMeeting $meeting, array $jiraSnapshot): array
    {
        $client = $meeting->client;

        return [
            'client_name'         => $client?->name ?? 'Unknown Client',
            'client_contact_name' => $this->resolveClientContactName($meeting),
            'client_industry'     => $client?->industry ?? 'unknown',
            'client_platform'     => $client?->platform_type ?? 'unknown',
            'meeting_title'       => $meeting->title ?? '',
            'meeting_date'        => $meeting->meeting_start_at?->format('l, F j, Y') ?? 'TBD',
            'meeting_time'        => $meeting->meeting_start_at?->format('g:i A') ?? '',
            'attendees'           => $this->resolveAttendeesString($meeting),
            'jira_data'           => json_encode($jiraSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    /**
     * Builds the complete dynamic variable dictionary for post-meeting follow-up.
     */
    public function buildMeetingFollowUpVariables(ClientMeeting $meeting, string $notes, ?string $transcript = null): array
    {
        $client = $meeting->client;

        return [
            'client_name'         => $client?->name ?? 'Unknown Client',
            'client_contact_name' => $this->resolveClientContactName($meeting),
            'meeting_title'       => $meeting->title ?? '',
            'meeting_date'        => $meeting->meeting_start_at?->format('l, F j, Y') ?? 'TBD',
            'attendees'           => $this->resolveAttendeesString($meeting),
            'notes'               => $notes,
            'transcript'          => $transcript ?? '',
        ];
    }
}
