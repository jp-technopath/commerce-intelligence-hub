<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_preps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_meeting_id')->constrained('client_meetings')->cascadeOnDelete();
            $table->string('jira_project_key')->nullable();
            $table->text('jira_jql')->nullable();
            $table->longText('internal_summary')->nullable();
            $table->string('generated_status_email_subject')->nullable();
            $table->longText('generated_status_email_body')->nullable();
            $table->string('edited_status_email_subject')->nullable();
            $table->longText('edited_status_email_body')->nullable();
            $table->json('recommended_agenda')->nullable();
            $table->json('jira_snapshot')->nullable();
            $table->string('gmail_draft_id')->nullable();
            $table->string('google_doc_id')->nullable();
            $table->string('google_doc_url')->nullable();
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
        Schema::dropIfExists('meeting_preps');
    }
};
