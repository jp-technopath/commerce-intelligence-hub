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
        // 1. pm_connections (Customer PM Scope)
        Schema::create('pm_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('jira');
            $table->string('name');
            $table->string('external_workspace_id')->nullable();
            $table->json('configuration_json')->nullable();
            $table->json('status_mappings_json')->nullable();
            $table->foreignId('default_sync_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // 2. pm_projects
        Schema::create('pm_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pm_connection_id')->constrained('pm_connections')->cascadeOnDelete();
            $table->string('name');
            $table->string('external_project_id')->nullable();
            $table->string('external_project_key');
            $table->string('custom_filter_jql')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. pm_work_items
        Schema::create('pm_work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pm_connection_id')->constrained('pm_connections')->cascadeOnDelete();
            $table->foreignId('pm_project_id')->nullable()->constrained('pm_projects')->nullOnDelete();
            $table->string('external_item_id');
            $table->string('external_item_key')->index();
            $table->string('summary');
            $table->text('description')->nullable();
            $table->string('item_type')->default('Task');
            $table->string('external_status')->default('To Do');
            $table->string('normalized_delivery_status')->default('planned');
            $table->integer('estimated_seconds')->default(0);
            $table->integer('time_spent_seconds')->default(0);
            $table->string('assignee_name')->nullable();
            $table->date('target_due_date')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['pm_connection_id', 'external_item_id']);
        });

        // 4. pm_worklogs
        Schema::create('pm_worklogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pm_connection_id')->constrained('pm_connections')->cascadeOnDelete();
            $table->foreignId('pm_work_item_id')->constrained('pm_work_items')->cascadeOnDelete();
            $table->string('external_worklog_id');
            $table->string('author_name')->nullable();
            $table->integer('time_spent_seconds')->default(0);
            $table->timestamp('worklog_started_at')->index();
            $table->timestamp('external_created_at')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['pm_connection_id', 'external_worklog_id']);
        });

        // 5. forge_estimate_versions
        Schema::create('forge_estimate_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_work_item_id')->constrained('pm_work_items')->cascadeOnDelete();
            $table->integer('version');
            $table->integer('estimated_seconds');
            $table->integer('external_estimate_at_submission')->default(0);
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->text('po_notes')->nullable();
            $table->decimal('cost_impact_amount', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['pm_work_item_id', 'version']);
        });

        // 6. forge_approval_events
        Schema::create('forge_approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_version_id')->constrained('forge_estimate_versions')->cascadeOnDelete();
            $table->string('event_type'); // submitted, approved, revision_requested, reapproval_required, withdrawn
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 7. customer_attention_items
        Schema::create('customer_attention_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // estimate_approval, estimate_reapproval, action_item_overdue, task_blocked, upcoming_decision, ai_risk
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('action_url')->nullable();
            $table->string('severity')->default('info'); // urgent, warning, info
            $table->string('source_type')->default('system'); // jira, meeting, email, system, ai_agent
            $table->string('source_id')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_attention_items');
        Schema::dropIfExists('forge_approval_events');
        Schema::dropIfExists('forge_estimate_versions');
        Schema::dropIfExists('pm_worklogs');
        Schema::dropIfExists('pm_work_items');
        Schema::dropIfExists('pm_projects');
        Schema::dropIfExists('pm_connections');
    }
};
