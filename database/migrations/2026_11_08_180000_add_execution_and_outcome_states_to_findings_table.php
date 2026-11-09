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
        Schema::table('findings', function (Blueprint $table) {
            if (! Schema::hasColumn('findings', 'execution_status')) {
                $table->string('execution_status', 50)->default('new')->after('status')->index();
            }

            if (! Schema::hasColumn('findings', 'outcome_status')) {
                $table->string('outcome_status', 50)->default('pending_execution')->after('execution_status')->index();
            }

            if (! Schema::hasColumn('findings', 'target_role')) {
                $table->string('target_role', 50)->default('sales')->after('outcome_status')->index();
            }

            if (! Schema::hasColumn('findings', 'pm_work_item_id')) {
                $table->foreignId('pm_work_item_id')
                    ->nullable()
                    ->after('target_role')
                    ->constrained('pm_work_items')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('findings', 'pm_idempotency_token')) {
                $table->string('pm_idempotency_token', 100)->nullable()->unique()->after('pm_work_item_id');
            }

            if (! Schema::hasColumn('findings', 'action_completed_at')) {
                $table->timestamp('action_completed_at')->nullable()->after('assigned_at');
            }

            if (! Schema::hasColumn('findings', 'outcome_measured_at')) {
                $table->timestamp('outcome_measured_at')->nullable()->after('action_completed_at');
            }
        });

        if (! Schema::hasTable('finding_status_transitions')) {
            Schema::create('finding_status_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('finding_id')->constrained('findings')->cascadeOnDelete();
                $table->string('from_status', 50)->nullable();
                $table->string('to_status', 50);
                $table->string('from_execution_status', 50)->nullable();
                $table->string('to_execution_status', 50);
                $table->string('from_outcome_status', 50)->nullable();
                $table->string('to_outcome_status', 50);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('user_name')->nullable();
                $table->string('transition_source', 50)->default('forge_ui');
                $table->text('notes')->nullable();
                $table->string('business_outcome', 100)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['finding_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finding_status_transitions');

        Schema::table('findings', function (Blueprint $table) {
            if (Schema::hasColumn('findings', 'pm_work_item_id')) {
                $table->dropForeign(['pm_work_item_id']);
                $table->dropColumn('pm_work_item_id');
            }

            $columns = [
                'execution_status',
                'outcome_status',
                'target_role',
                'pm_idempotency_token',
                'action_completed_at',
                'outcome_measured_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('findings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
