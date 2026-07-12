<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_follow_ups', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('gmail_draft_id');
            $table->string('email_to')->nullable()->after('email_sent_at');
            $table->json('email_cc')->nullable()->after('email_to');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_follow_ups', function (Blueprint $table) {
            $table->dropColumn(['email_sent_at', 'email_to', 'email_cc']);
        });
    }
};
