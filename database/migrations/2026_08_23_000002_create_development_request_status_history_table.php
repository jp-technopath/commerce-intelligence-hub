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
        Schema::create('development_request_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('development_request_id')->constrained('development_requests')->cascadeOnDelete();
            $table->string('old_state');
            $table->string('new_state');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type')->nullable(); // e.g., 'user', 'system', 'webhook'
            $table->string('actor_label')->nullable(); // Human-readable label for system transitions
            $table->text('reason')->nullable();
            $table->string('idempotency_key')->nullable(); // For durable idempotency
            $table->string('correlation_identifier')->nullable(); // Correlation identifier for tracing
            $table->json('metadata')->nullable(); // Additional context-specific data
            $table->timestamp('created_at')->useCurrent();

            // Enforce durable idempotency: unique constraint on (development_request_id, idempotency_key)
            // PostgreSQL permits multiple null values in unique indexes, so this is safe
            $table->unique(['development_request_id', 'idempotency_key'], 'unique_dev_req_idempotency');

            $table->index(['development_request_id', 'created_at']);
            $table->index(['new_state']);
            $table->index(['actor_user_id']);
            $table->index(['correlation_identifier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('development_request_status_histories');
    }
};
