<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_key')->nullable();
            $table->string('google_calendar_id')->nullable();
            $table->string('google_event_id')->nullable();
            $table->string('google_ical_uid')->nullable();
            $table->foreignId('scanned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->timestamp('meeting_start_at');
            $table->timestamp('meeting_end_at')->nullable();
            $table->string('timezone')->default('UTC');
            $table->foreignId('internal_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('external_attendees')->nullable();
            $table->json('internal_attendees')->nullable();
            $table->string('status')->default('detected');
            $table->string('source')->default('manual');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('internal_owner_id');
            $table->index('status');
            $table->index('meeting_start_at');
            $table->index('scanned_by_user_id');
            $table->unique(
                ['scanned_by_user_id', 'google_calendar_id', 'google_event_id'],
                'client_meetings_scanner_calendar_event_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_meetings');
    }
};
