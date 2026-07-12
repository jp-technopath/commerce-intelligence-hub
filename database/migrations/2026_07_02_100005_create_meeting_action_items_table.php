<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_meeting_id')->constrained('client_meetings')->cascadeOnDelete();
            $table->foreignId('meeting_follow_up_id')->nullable()->constrained('meeting_follow_ups')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('owner_name')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status')->default('open');
            $table->string('source')->default('manual');
            $table->string('jira_issue_key')->nullable();
            $table->boolean('is_customer_facing')->default(true);
            $table->timestamps();

            $table->index('client_meeting_id');
            $table->index('status');
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_action_items');
    }
};
