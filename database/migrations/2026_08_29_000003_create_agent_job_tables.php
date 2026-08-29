<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('job_identifier')->unique();
            $table->foreignId('development_request_id')->constrained('development_requests')->cascadeOnDelete();
            $table->foreignId('project_environment_mapping_id')->nullable()->constrained('project_environment_mappings')->nullOnDelete();
            $table->string('correlation_identifier');
            $table->string('role');
            $table->string('status')->default('queued');
            $table->string('worker_service_account_email');
            $table->json('payload')->nullable();
            $table->string('payload_hash', 64);
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by_worker_identity')->nullable();
            $table->string('claim_request_identifier')->nullable();
            $table->string('lease_token_hash', 64)->nullable();
            $table->text('lease_token_ciphertext')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->string('progress_stage')->nullable();
            $table->text('progress_message')->nullable();
            $table->json('result')->nullable();
            $table->json('failure')->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('details_pruned_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['development_request_id', 'role', 'attempt'],
                'agent_job_request_role_attempt_unique'
            );
            $table->index(
                ['worker_service_account_email', 'status', 'available_at'],
                'agent_job_worker_claim_idx'
            );
            $table->index(['development_request_id', 'status']);
            $table->index('correlation_identifier');
            $table->index('lease_expires_at');
        });

        Schema::create('agent_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_job_id')->constrained('agent_jobs')->cascadeOnDelete();
            $table->string('operation');
            $table->string('event_type');
            $table->string('worker_identity')->nullable();
            $table->uuid('request_identifier')->nullable();
            $table->string('request_payload_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['agent_job_id', 'operation', 'request_identifier'],
                'agent_job_event_request_unique'
            );
            $table->index(['agent_job_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });

        Schema::create('worker_api_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_identifier')->unique();
            $table->string('worker_identity');
            $table->string('operation');
            $table->string('request_payload_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body_ciphertext')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['worker_identity', 'requested_at']);
            $table->index('expires_at');
        });

        Schema::create('worker_api_security_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->string('worker_identity')->nullable();
            $table->uuid('request_identifier')->nullable();
            $table->string('operation')->nullable();
            $table->string('reason_code');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_type', 'created_at']);
            $table->index(['worker_identity', 'created_at']);
            $table->index('request_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_api_security_events');
        Schema::dropIfExists('worker_api_requests');
        Schema::dropIfExists('agent_job_events');
        Schema::dropIfExists('agent_jobs');
    }
};
