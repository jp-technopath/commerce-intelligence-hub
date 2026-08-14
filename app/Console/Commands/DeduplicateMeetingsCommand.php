<?php

namespace App\Console\Commands;

use App\Models\ClientMeeting;
use App\Models\MeetingFollowUp;
use App\Models\MeetingPrep;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DeduplicateMeetingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meetings:deduplicate
                            {--dry-run : Output duplicate report without modifying database}
                            {--apply : Execute merge and delete duplicate meeting records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Report or merge duplicate ClientMeeting occurrences sharing iCalUID and start datetime.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apply = $this->option('apply');
        $dryRun = $this->option('dry-run') || ! $apply;

        $this->info($dryRun ? '=== MEETING DEDUPLICATION REPORT (DRY-RUN) ===' : '=== EXECUTING MEETING DEDUPLICATION ===');

        $meetings = ClientMeeting::whereNotNull('google_ical_uid')
            ->where('google_ical_uid', '!=', '')
            ->orderBy('meeting_start_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Group meetings by google_ical_uid + start_at date hour string (for timezone/window safety)
        $grouped = [];

        foreach ($meetings as $meeting) {
            $startStr = $meeting->meeting_start_at
                ? Carbon::parse($meeting->meeting_start_at)->format('Y-m-d H:i')
                : 'no_start_time';

            $key = $meeting->google_ical_uid . '|' . $startStr;
            $grouped[$key][] = $meeting;
        }

        $duplicateGroupsCount = 0;
        $totalMergedCount = 0;

        foreach ($grouped as $key => $groupMeetings) {
            if (count($groupMeetings) <= 1) {
                continue;
            }

            $duplicateGroupsCount++;
            [$iCalUid, $startStr] = explode('|', $key);

            $this->line('');
            $this->comment("Duplicate Group #{$duplicateGroupsCount}: iCalUID = [{$iCalUid}] | Start = [{$startStr}]");

            // Select retained meeting: prefer one with prep or followUp, else first created
            usort($groupMeetings, function ($a, $b) {
                $aScore = ($a->prep ? 2 : 0) + ($a->followUp ? 2 : 0);
                $bScore = ($b->prep ? 2 : 0) + ($b->followUp ? 2 : 0);
                if ($aScore !== $bScore) {
                    return $bScore <=> $aScore;
                }
                return $a->id <=> $b->id;
            });

            /** @var ClientMeeting $retained */
            $retained = array_shift($groupMeetings);
            /** @var ClientMeeting[] $duplicates */
            $duplicates = $groupMeetings;

            $this->info(" -> Retaining Record ID #{$retained->id}: \"{$retained->title}\" (Scanned by User #{$retained->scanned_by_user_id})");

            $mergedExternal = $retained->external_attendees ?? [];
            $mergedInternal = $retained->internal_attendees ?? [];

            foreach ($duplicates as $dup) {
                $totalMergedCount++;
                $this->warn(" -> Merging Duplicate Record ID #{$dup->id}: \"{$dup->title}\" (Scanned by User #{$dup->scanned_by_user_id})");

                // Check prep/follow-up items on duplicate
                if ($dup->prep) {
                    $this->line("    * Duplicate #{$dup->id} has MeetingPrep #{$dup->prep->id}");
                }
                if ($dup->followUp) {
                    $this->line("    * Duplicate #{$dup->id} has MeetingFollowUp #{$dup->followUp->id}");
                }

                $mergedExternal = $this->mergeAttendees($mergedExternal, $dup->external_attendees ?? []);
                $mergedInternal = $this->mergeAttendees($mergedInternal, $dup->internal_attendees ?? []);

                if ($apply) {
                    // Re-link preps/follow-ups if retained doesn't have one
                    if ($dup->prep && ! $retained->prep) {
                        MeetingPrep::where('client_meeting_id', $dup->id)->update(['client_meeting_id' => $retained->id]);
                    }
                    if ($dup->followUp && ! $retained->followUp) {
                        MeetingFollowUp::where('client_meeting_id', $dup->id)->update(['client_meeting_id' => $retained->id]);
                    }

                    // Delete duplicate record
                    $dup->delete();
                }
            }

            if ($apply) {
                $retained->update([
                    'external_attendees' => $mergedExternal,
                    'internal_attendees' => $mergedInternal,
                ]);
            }
        }

        $this->line('');
        $this->info("Deduplication Summary:");
        $this->info("- Total Duplicate Groups Identified: {$duplicateGroupsCount}");
        $this->info("- Total Duplicate Meeting Records " . ($apply ? "Merged & Deleted" : "Flagged for Merge") . ": {$totalMergedCount}");

        if ($dryRun && $duplicateGroupsCount > 0) {
            $this->comment("\nTo execute database merge and delete duplicate records, run:\nphp artisan meetings:deduplicate --apply");
        }

        return Command::SUCCESS;
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
}
