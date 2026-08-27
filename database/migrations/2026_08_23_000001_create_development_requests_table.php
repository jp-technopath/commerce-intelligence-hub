<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('development_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_request_id')->nullable()->constrained('development_requests')->nullOnDelete();
            $table->string('request_type'); // e.g., 'investigation', 'development', 'qa', etc.
            $table->string('state')->default('draft'); // Backed by DevelopmentRequestStatus enum
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status_reason')->nullable(); // Reason for current state
            $table->string('environment_key'); // Required context: identifies environment
            $table->string('source_type'); // e.g., 'manual', 'api', 'webhook', 'system'
            $table->string('source_id')->nullable(); // e.g., webhook run ID, API client identifier
            $table->string('priority')->default('medium'); // e.g., 'low', 'medium', 'high', 'critical'
            $table->string('correlation_identifier')->unique(); // Unique request identifier for tracing
            $table->string('active_run_correlation_id')->nullable(); // Lightweight correlation to current/active run
            $table->json('jira_snapshot')->nullable(); // Snapshot of selected Jira issue/request
            $table->foreignId('pm_work_item_id')->nullable()->constrained('pm_work_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'state']);
            $table->index(['project_id', 'state']);
            $table->index(['owner_user_id', 'state']);
            $table->index(['parent_request_id']);
            $table->index(['active_run_correlation_id']);
            $table->index(['state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('development_requests');
    }
};
