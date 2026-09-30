<?php

namespace App\Services\MeetingAgent;

use App\Models\ClientMeeting;

class MeetingContentPresenter
{
    /**
     * Parse and structure the recommended agenda.
     *
     * @return array{
     *     items: array<int, array{
     *         number: int,
     *         title: string,
     *         detail: ?string,
     *         duration: ?string,
     *         minutes: ?int,
     *         raw: string
     *     }>,
     *     count: int,
     *     total_duration: ?string
     * }
     */
    public static function parseAgenda(?string $rawAgenda): array
    {
        if (empty($rawAgenda)) {
            return [
                'items'          => [],
                'count'          => 0,
                'total_duration' => null,
            ];
        }

        // Split on newlines
        $lines = preg_split('/\r\n|\r|\n/', trim($rawAgenda));
        $rawItems = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $rawItems[] = $line;
            }
        }

        // Handle case where items are crammed on 1 line: "1. Foo (5 min) 2. Bar (10 min)"
        if (count($rawItems) === 1 && preg_match('/(?:\s|^)\d+[\.\)]\s/', $rawItems[0])) {
            $split = preg_split('/(?<=\))\s+(?=\d+[\.\)])|(?<=\w)\s+(?=\d+[\.\)]\s)/', $rawItems[0]);
            if (count($split) > 1) {
                $rawItems = array_values(array_filter(array_map('trim', $split)));
            }
        }

        $items = [];
        $totalMinutes = 0;

        foreach ($rawItems as $index => $raw) {
            $parsed = self::parseAgendaItem($raw, $index + 1);
            if ($parsed['minutes']) {
                $totalMinutes += $parsed['minutes'];
            }
            $items[] = $parsed;
        }

        return [
            'items'          => $items,
            'count'          => count($items),
            'total_duration' => $totalMinutes > 0 ? "{$totalMinutes} min" : null,
        ];
    }

    protected static function parseAgendaItem(string $raw, int $fallbackNumber): array
    {
        $item = trim($raw);
        $number = $fallbackNumber;

        if (preg_match('/^(\d+)[\.\)]\s*(.*)$/u', $item, $matches)) {
            $number = (int) $matches[1];
            $item = trim($matches[2]);
        }

        $duration = null;
        $minutes = null;

        if (preg_match('/\s*\((~?\d+(?:[\x{2013}\-]\d+)?\s*(?:min(?:utes)?|hrs?))\)\s*$/iu', $item, $matches)) {
            $duration = $matches[1];
            $item = trim(substr($item, 0, -strlen($matches[0])));

            if (preg_match('/(\d+)\s*min/i', $duration, $minMatches)) {
                $minutes = (int) $minMatches[1];
            }
        }

        // Split title and detail by en-dash, em-dash, hyphen, or colon
        $parts = preg_split('/\s+(?:[\x{2013}\x{2014}\-–—])\s+|\s*:\s*/u', $item, 2);
        $title = $parts[0] ?? $item;
        $detail = $parts[1] ?? null;

        return [
            'number'   => $number,
            'title'    => $title,
            'detail'   => $detail,
            'duration' => $duration,
            'minutes'  => $minutes,
            'raw'      => $raw,
        ];
    }

    /**
     * Parse internal summary into health assessment, structured sections, and ticket breakdowns.
     */
    public static function parseInternalSummary(?string $rawSummary): array
    {
        if (empty($rawSummary)) {
            return [
                'health'      => null,
                'sections'    => [],
                'has_content' => false,
            ];
        }

        $text = trim($rawSummary);

        // 1. Health assessment extraction
        $health = null;
        if (preg_match('/PROJECT HEALTH:\s*(?:Overall\s*)?([A-Z\/\s]+?)\.\s*(.*?)(?=(?:\n[A-Z0-9\s\/\(\)\,\&]+:|\Z))/su', $text, $hm)) {
            $statusRaw = strtoupper(trim($hm[1]));
            $details = trim($hm[2]);

            $color = 'gray';
            if (str_contains($statusRaw, 'GREEN') && str_contains($statusRaw, 'AMBER')) {
                $color = 'warning';
            } elseif (str_contains($statusRaw, 'GREEN')) {
                $color = 'success';
            } elseif (str_contains($statusRaw, 'AMBER') || str_contains($statusRaw, 'YELLOW')) {
                $color = 'warning';
            } elseif (str_contains($statusRaw, 'RED')) {
                $color = 'danger';
            }

            // Ticket breakdown stats
            $ticketBreakdown = [];
            $totalTickets = null;
            if (preg_match('/(?:shows|total)\s+(\d+)\s+tickets?:?\s*([^\.]+)\./i', $details, $tm)) {
                $totalTickets = (int) $tm[1];
                $ticketBreakdown = array_map('trim', explode(',', trim($tm[2])));
            }

            $health = [
                'status'           => $statusRaw,
                'color'            => $color,
                'details'          => $details,
                'total_tickets'    => $totalTickets,
                'ticket_breakdown' => $ticketBreakdown,
            ];
        }

        // 2. Parse sections
        $rawSections = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $currentHeader = null;
        $currentLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            // Header line detection
            if (!preg_match('/^[\s\-\*•\d\.]/u', $trimmed) &&
                preg_match('/^([A-Z][A-Z0-9\s\/\(\)\,\&]+)(?:\s*(?:[\x{2013}\x{2014}\-–—]\s*[^:]+))?:\s*(.*)$/u', $trimmed, $m) &&
                strlen($m[1]) >= 4 &&
                strtoupper($m[1]) === $m[1] &&
                !str_starts_with($trimmed, 'PROJECT HEALTH:')
            ) {
                if ($currentHeader !== null) {
                    $rawSections[] = ['header' => $currentHeader, 'lines' => $currentLines];
                }
                $currentHeader = $m[1];
                $currentLines = [];
                if (!empty(trim($m[2]))) {
                    $currentLines[] = trim($m[2]);
                }
            } else {
                if ($currentHeader === null) {
                    // Only capture if not part of PROJECT HEALTH
                    if (!str_starts_with($trimmed, 'PROJECT HEALTH:')) {
                        $currentHeader = 'OVERVIEW';
                        $currentLines[] = $trimmed;
                    }
                } else {
                    $currentLines[] = $trimmed;
                }
            }
        }

        if ($currentHeader !== null && !empty($currentLines)) {
            $rawSections[] = ['header' => $currentHeader, 'lines' => $currentLines];
        }

        // Transform raw sections into presentation models
        $sections = [];
        foreach ($rawSections as $rawSec) {
            $header = trim($rawSec['header']);
            $secLines = $rawSec['lines'];

            if (str_starts_with(strtoupper($header), 'PROJECT HEALTH')) {
                continue;
            }

            $sectionMeta = self::categorizeSection($header);
            $parsedItems = [];
            $actionNotes = [];

            foreach ($secLines as $sLine) {
                if (preg_match('/^(?:Action|Hold reason|Note):\s*(.*)$/iu', $sLine, $am)) {
                    $actionNotes[] = $sLine;
                    continue;
                }

                $parsedTicket = self::parseTicketOrBulletLine($sLine);
                $parsedItems[] = $parsedTicket;
            }

            $sections[] = [
                'header'       => $header,
                'title'        => $sectionMeta['title'],
                'key'          => $sectionMeta['key'],
                'color'        => $sectionMeta['color'],
                'icon'         => $sectionMeta['icon'],
                'count'        => $sectionMeta['count'] ?? count(array_filter($parsedItems, fn($it) => !empty($it['ticket']))),
                'action_notes' => $actionNotes,
                'items'        => $parsedItems,
            ];
        }

        return [
            'health'      => $health,
            'sections'    => $sections,
            'has_content' => !empty($health) || !empty($sections),
        ];
    }

    /**
     * Categorize a section header and assign visual styling.
     */
    protected static function categorizeSection(string $header): array
    {
        $h = strtoupper($header);
        $count = null;

        if (preg_match('/\(([0-9]+)\)/', $header, $cm)) {
            $count = (int) $cm[1];
        }

        $cleanTitle = preg_replace('/\s*\([0-9]+\)/', '', $header);
        $cleanTitle = trim(preg_replace('/[\x{2013}\x{2014}\-–—].*$/u', '', $cleanTitle));

        if (str_contains($h, 'COMPLETED')) {
            return [
                'title' => $cleanTitle ?: 'Completed Items',
                'key'   => 'completed',
                'color' => 'emerald',
                'icon'  => 'heroicon-m-check-circle',
                'count' => $count,
            ];
        }

        if (str_contains($h, 'REVIEW') || str_contains($h, 'QA') || str_contains($h, 'STAGING')) {
            return [
                'title' => $cleanTitle ?: 'Ready for Review / QA on Staging',
                'key'   => 'qa',
                'color' => 'purple',
                'icon'  => 'heroicon-m-clipboard-document-check',
                'count' => $count,
            ];
        }

        if (str_contains($h, 'DEPLOYMENT') || str_contains($h, 'DEPLOY')) {
            return [
                'title' => $cleanTitle ?: 'Ready for Deployment',
                'key'   => 'deployment',
                'color' => 'teal',
                'icon'  => 'heroicon-m-rocket-launch',
                'count' => $count,
            ];
        }

        if (str_contains($h, 'IN PROGRESS') || str_contains($h, 'READY FOR DEV') || str_contains($h, 'ACTIVE')) {
            return [
                'title' => $cleanTitle ?: 'In Progress & Active Work',
                'key'   => 'in_progress',
                'color' => 'blue',
                'icon'  => 'heroicon-m-arrow-path',
                'count' => $count,
            ];
        }

        if (str_contains($h, 'BLOCKER') || str_contains($h, 'RISK') || str_contains($h, 'HOLD') || str_contains($h, 'BACKLOG')) {
            return [
                'title' => $cleanTitle ?: 'Blockers & Risks',
                'key'   => 'blockers',
                'color' => 'amber',
                'icon'  => 'heroicon-m-exclamation-triangle',
                'count' => $count,
            ];
        }

        if (str_contains($h, 'CUSTOMER INPUT') || str_contains($h, 'NEEDS CUSTOMER')) {
            return [
                'title' => $cleanTitle ?: 'Items Needing Customer Input',
                'key'   => 'customer_input',
                'color' => 'indigo',
                'icon'  => 'heroicon-m-question-mark-circle',
                'count' => $count,
            ];
        }

        if (str_contains($h, 'TALKING POINT') || str_contains($h, 'RECOMMENDATION')) {
            return [
                'title' => $cleanTitle ?: 'Talking Points & Discussion Items',
                'key'   => 'talking_points',
                'color' => 'slate',
                'icon'  => 'heroicon-m-chat-bubble-bottom-center-text',
                'count' => $count,
            ];
        }

        return [
            'title' => $cleanTitle ?: 'Additional Items',
            'key'   => 'other',
            'color' => 'gray',
            'icon'  => 'heroicon-m-bars-3-bottom-left',
            'count' => $count,
        ];
    }

    /**
     * Parse an individual ticket line or bullet item.
     */
    protected static function parseTicketOrBulletLine(string $line): array
    {
        $raw = $line;
        $cleanLine = preg_replace('/^[\s\-\*•\d\.]+\s*/u', '', trim($line));

        $ticket = null;
        $priority = null;
        $priorityColor = 'slate';
        $owner = null;
        $status = null;
        $title = $cleanLine;
        $notes = null;

        if (preg_match('/^([A-Z0-9]+-\d+)\s*(.*)$/u', $cleanLine, $matches)) {
            $ticket = $matches[1];
            $rest = trim($matches[2]);

            // Check priority: (Highest), (High), (Medium), (Low), (Critical)
            if (preg_match('/^\((Highest|High|Medium|Low|Lowest|Critical|Blocker)\)\s*(.*)$/iu', $rest, $pm)) {
                $priority = ucfirst(strtolower($pm[1]));
                $rest = trim($pm[2]);

                $priorityColor = match (strtolower($priority)) {
                    'highest', 'critical', 'blocker' => 'rose',
                    'high'                            => 'amber',
                    'medium'                          => 'blue',
                    default                           => 'slate',
                };
            }

            // Split title and status/owner/notes by dash
            $parts = preg_split('/\s+(?:[\x{2013}\x{2014}\-–—])\s+/u', $rest, 2);
            if (count($parts) === 2) {
                $title = $parts[0];
                $after = $parts[1];

                // Check for status + owner: "Done 30 Sep (Nour)", "In Progress (Donia)", "Ready for Dev (Donia)"
                if (preg_match('/^(Done|In Progress|Ready for Dev|Ready for Deployment|On Hold|QA|To Do|Closed|Resolved)(?:\s+[\w\s\d]+)?(?:\s*\(([^\)]+)\))?\.?\s*(.*)$/iu', $after, $sm)) {
                    $status = trim($sm[1]);
                    if (!empty($sm[2])) $owner = trim($sm[2]);
                    if (!empty($sm[3])) $notes = trim($sm[3]);
                } else {
                    $notes = $after;
                }
            } else {
                if (preg_match('/^(.*?)\s*\(([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\)\.?$/u', $rest, $om)) {
                    $title = $om[1];
                    $owner = $om[2];
                } else {
                    $title = $rest;
                }
            }
        }

        return [
            'ticket'         => $ticket,
            'priority'       => $priority,
            'priority_color' => $priorityColor,
            'title'          => $title,
            'status'         => $status,
            'owner'          => $owner,
            'notes'          => $notes,
            'raw'            => $raw,
        ];
    }

    /**
     * Parse decisions from MeetingFollowUp.
     */
    public static function parseDecisions(mixed $rawDecisions): array
    {
        if (empty($rawDecisions)) {
            return [];
        }

        $items = [];
        if (is_array($rawDecisions)) {
            foreach ($rawDecisions as $item) {
                if (is_string($item)) {
                    foreach (preg_split('/\r\n|\r|\n/', trim($item)) as $sub) {
                        $cleaned = preg_replace('/^[\s\-\*•\d\.]+\s*/u', '', trim($sub));
                        if ($cleaned !== '') $items[] = $cleaned;
                    }
                }
            }
        } elseif (is_string($rawDecisions)) {
            foreach (preg_split('/\r\n|\r|\n/', trim($rawDecisions)) as $line) {
                $cleaned = preg_replace('/^[\s\-\*•\d\.]+\s*/u', '', trim($line));
                if ($cleaned !== '') $items[] = $cleaned;
            }
        }

        return $items;
    }
}
