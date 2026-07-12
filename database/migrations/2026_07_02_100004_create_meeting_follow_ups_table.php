<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_meeting_id')->constrained('client_meetings')->cascadeOnDelete();
            $table->longText('raw_notes')->nullable();
            $table->longText('transcript_text')->nullable();
            $table->longText('summary')->nullable();
            $table->string('generated_followup_email_subject')->nullable();
            $table->longText('generated_followup_email_body')->nullable();
            $table->string('edited_followup_email_subject')->nullable();
            $table->longText('edited_followup_email_body')->nullable();
            $table->json('suggested_action_items')->nullable();
            $table->json('decisions')->nullable();
            $table->json('open_questions')->nullable();
            $table->string('gmail_draft_id')->nullable();
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->text('ai_error')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_follow_ups');
    }
};
