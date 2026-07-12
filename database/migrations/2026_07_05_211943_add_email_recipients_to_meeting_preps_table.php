<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_preps', function (Blueprint $table) {
            $table->string('email_to')->nullable()->after('email_sent_at');
            $table->json('email_cc')->nullable()->after('email_to');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_preps', function (Blueprint $table) {
            $table->dropColumn(['email_to', 'email_cc']);
        });
    }
};
