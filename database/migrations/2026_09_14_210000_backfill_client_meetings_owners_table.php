<?php

use App\Models\ClientMeeting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $usersByEmail = User::whereNotNull('email')->get()->keyBy(fn ($u) => strtolower(trim($u->email)));

        ClientMeeting::chunk(50, function ($meetings) use ($usersByEmail) {
            foreach ($meetings as $m) {
                $internalAttendees = $m->internal_attendees ?? [];
                $metadata = $m->metadata ?? [];
                $organizerEmail = strtolower(trim($metadata['organizer_email'] ?? ''));

                $matchedOwnerId = null;
                if (! empty($organizerEmail) && isset($usersByEmail[$organizerEmail])) {
                    $matchedOwnerId = $usersByEmail[$organizerEmail]->id;
                } else {
                    foreach ($internalAttendees as $att) {
                        $email = strtolower(trim($att['email'] ?? ''));
                        if (isset($usersByEmail[$email])) {
                            $matchedOwnerId = $usersByEmail[$email]->id;
                            break;
                        }
                    }
                }

                if ($matchedOwnerId && $m->internal_owner_id !== $matchedOwnerId) {
                    $m->update(['internal_owner_id' => $matchedOwnerId]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal necessary for data backfill
    }
};
