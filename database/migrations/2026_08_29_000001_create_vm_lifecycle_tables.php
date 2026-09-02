<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vm_runtime_states', function (Blueprint $table) {
            $table->id();
            $table->string('target_key', 64)->unique();
            $table->string('gcp_project_id');
            $table->string('gcp_zone');
            $table->string('vm_name');
            $table->string('status')->default('unknown');
            $table->string('worker_identifier')->nullable();
            $table->string('worker_state')->nullable();
            $table->timestamp('last_worker_heartbeat_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('idle_since')->nullable();
            $table->timestamp('start_requested_at')->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->string('last_operation_id')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('manual_override_action')->nullable();
            $table->timestamp('manual_override_at')->nullable();
            $table->foreignId('manual_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'idle_since']);
            $table->index('last_worker_heartbeat_at');
        });

        Schema::table('development_requests', function (Blueprint $table) {
            $table->string('execution_target_key', 64)->nullable()->after('project_environment_mapping_id');
            $table->timestamp('vm_startup_deadline_at')->nullable()->after('execution_target_key');
            $table->timestamp('vm_ready_at')->nullable()->after('vm_startup_deadline_at');

            $table->index(['execution_target_key', 'state']);
        });

        Schema::create('vm_lifecycle_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_runtime_state_id')->constrained('vm_runtime_states')->restrictOnDelete();
            $table->foreignId('development_request_id')->nullable()->constrained('development_requests')->nullOnDelete();
            $table->string('action');
            $table->string('outcome');
            $table->string('gcp_operation_id')->nullable();
            $table->string('actor_type');
            $table->string('actor_label')->nullable();
            $table->text('reason')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['vm_runtime_state_id', 'created_at']);
            $table->index(['development_request_id', 'created_at']);
            $table->index(['action', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vm_lifecycle_actions');

        Schema::table('development_requests', function (Blueprint $table) {
            $table->dropIndex(['execution_target_key', 'state']);
            $table->dropColumn(['execution_target_key', 'vm_startup_deadline_at', 'vm_ready_at']);
        });

        Schema::dropIfExists('vm_runtime_states');
    }
};
