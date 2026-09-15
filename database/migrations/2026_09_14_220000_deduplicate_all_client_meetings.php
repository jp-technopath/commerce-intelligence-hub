<?php

use App\Models\ClientMeeting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Deduplicate by google_event_id
        $eventGroups = DB::table('client_meetings')
            ->select('google_event_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('google_event_id')
            ->where('google_event_id', '!=', '')
            ->groupBy('google_event_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($eventGroups as $group) {
            $this->mergeMeetingGroup(
                ClientMeeting::where('google_event_id', $group->google_event_id)
                    ->orderByRaw('internal_owner_id IS NOT NULL DESC')
                    ->orderBy('id', 'asc')
                    ->get()
            );
        }

        // 2. Deduplicate by google_ical_uid and DATE(meeting_start_at)
        $icalGroups = DB::table('client_meetings')
            ->select('google_ical_uid', DB::raw('DATE(meeting_start_at) as meeting_date'), DB::raw('COUNT(*) as total'))
            ->whereNotNull('google_ical_uid')
            ->where('google_ical_uid', '!=', '')
            ->groupBy('google_ical_uid', DB::raw('DATE(meeting_start_at)'))
            ->having('total', '>', 1)
            ->get();

        foreach ($icalGroups as $group) {
            $this->mergeMeetingGroup(
                ClientMeeting::where('google_ical_uid', $group->google_ical_uid)
                    ->whereRaw('DATE(meeting_start_at) = ?', [$group->meeting_date])
                    ->orderByRaw('internal_owner_id IS NOT NULL DESC')
                    ->orderBy('id', 'asc')
                    ->get()
            );
        }
    }

    /**
     * Merge a collection of duplicate ClientMeeting models into one keeper record.
     */
    private function mergeMeetingGroup($meetings): void
    {
        if ($meetings->count() <= 1) {
            return;
        }

        $keeper = $meetings->first();
        $duplicates = $meetings->slice(1);

        $mergedExternal = $keeper->external_attendees ?? [];
        $mergedInternal = $keeper->internal_attendees ?? [];
        $mergedMetadata = $keeper->metadata ?? [];

        foreach ($duplicates as $dup) {
            // Merge attendees
            $mergedExternal = $this->combineAttendees($mergedExternal, $dup->external_attendees ?? []);
            $mergedInternal = $this->combineAttendees($mergedInternal, $dup->internal_attendees ?? []);
            $mergedMetadata = array_merge($dup->metadata ?? [], $mergedMetadata);

            // Fill missing fields on keeper
            if (empty($keeper->internal_owner_id) && $dup->internal_owner_id) {
                $keeper->internal_owner_id = $dup->internal_owner_id;
            }
            if (empty($keeper->client_id) && $dup->client_id) {
                $keeper->client_id = $dup->client_id;
            }
            if (empty($keeper->project_key) && $dup->project_key) {
                $keeper->project_key = $dup->project_key;
            }

            // Delete duplicate record
            $dup->delete();
        }

        $keeper->external_attendees = $mergedExternal;
        $keeper->internal_attendees = $mergedInternal;
        $keeper->metadata = $mergedMetadata;
        $keeper->save();
    }

    private function combineAttendees(array $existing, array $new): array
    {
        $seen = [];
        $result = [];

        foreach (array_merge($existing, $new) as $item) {
            $email = strtolower(trim($item['email'] ?? ''));
            if ($email && ! isset($seen[$email])) {
                $seen[$email] = true;
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deduplication cannot be safely reversed
    }
};
