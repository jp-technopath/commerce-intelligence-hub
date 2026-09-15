<?php

use App\Models\ClientMeeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingFollowUp;
use App\Models\MeetingPrep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Deduplicate existing client_meetings records and re-link child records.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $meetings = ClientMeeting::orderBy('id', 'asc')->get();
            $grouped = [];

            foreach ($meetings as $meeting) {
                if (! empty($meeting->google_event_id)) {
                    $key = 'event:' . $meeting->google_event_id;
                } elseif (! empty($meeting->google_ical_uid)) {
                    $baseUid = explode('_R', $meeting->google_ical_uid)[0];
                    $startStr = $meeting->meeting_start_at ? $meeting->meeting_start_at->format('Y-m-d H:i') : 'null';
                    $key = 'ical:' . $baseUid . '|' . $startStr;
                } else {
                    $startStr = $meeting->meeting_start_at ? $meeting->meeting_start_at->format('Y-m-d H:i') : 'null';
                    $key = 'title:' . strtolower(trim($meeting->title)) . '|' . $startStr;
                }

                $grouped[$key][] = $meeting;
            }

            $mergedCount = 0;

            foreach ($grouped as $key => $items) {
                if (count($items) <= 1) {
                    continue;
                }

                // Pick primary record (prefer one with client_id set or prep/followup attached)
                usort($items, function ($a, $b) {
                    $scoreA = ($a->client_id ? 10 : 0) + ($a->prep ? 5 : 0) + ($a->followUp ? 5 : 0);
                    $scoreB = ($b->client_id ? 10 : 0) + ($b->prep ? 5 : 0) + ($b->followUp ? 5 : 0);
                    if ($scoreA !== $scoreB) {
                        return $scoreB <=> $scoreA; // higher score first
                    }
                    return $a->id <=> $b->id; // older ID first
                });

                /** @var ClientMeeting $primary */
                $primary = $items[0];

                for ($i = 1; $i < count($items); $i++) {
                    /** @var ClientMeeting $dup */
                    $dup = $items[$i];

                    // Merge client_id and project_key if missing on primary
                    if (empty($primary->client_id) && ! empty($dup->client_id)) {
                        $primary->client_id = $dup->client_id;
                    }
                    if (empty($primary->project_key) && ! empty($dup->project_key)) {
                        $primary->project_key = $dup->project_key;
                    }

                    // Merge external and internal attendees
                    $primary->external_attendees = $this->mergeAttendees(
                        $primary->external_attendees ?? [],
                        $dup->external_attendees ?? []
                    );
                    $primary->internal_attendees = $this->mergeAttendees(
                        $primary->internal_attendees ?? [],
                        $dup->internal_attendees ?? []
                    );

                    // Re-link preps
                    if (! MeetingPrep::where('client_meeting_id', $primary->id)->exists()) {
                        MeetingPrep::where('client_meeting_id', $dup->id)->update(['client_meeting_id' => $primary->id]);
                    }

                    // Re-link followups
                    if (! MeetingFollowUp::where('client_meeting_id', $primary->id)->exists()) {
                        MeetingFollowUp::where('client_meeting_id', $dup->id)->update(['client_meeting_id' => $primary->id]);
                    }

                    // Re-link action items
                    MeetingActionItem::where('client_meeting_id', $dup->id)
                        ->update(['client_meeting_id' => $primary->id]);

                    // Delete duplicate record
                    $dup->delete();
                    $mergedCount++;
                }

                $primary->save();
            }

            Log::info('Deduplicated client_meetings records', ['merged_count' => $mergedCount]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-reversible data cleanup
    }

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
};
