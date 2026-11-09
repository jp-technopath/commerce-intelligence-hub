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
            if (! Schema::hasColumn('findings', 'approved_by_user_id')) {
                $table->foreignId('approved_by_user_id')
                    ->nullable()
                    ->after('responsible_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('findings', 'dismissed_by_user_id')) {
                $table->foreignId('dismissed_by_user_id')
                    ->nullable()
                    ->after('approved_by_user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('findings', 'under_review_at')) {
                $table->timestamp('under_review_at')->nullable()->after('snoozed_until');
            }

            if (! Schema::hasColumn('findings', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('under_review_at');
            }

            if (! Schema::hasColumn('findings', 'assigned_at')) {
                $table->timestamp('assigned_at')->nullable()->after('approved_at');
            }

            if (! Schema::hasColumn('findings', 'resolution_notes')) {
                $table->text('resolution_notes')->nullable()->after('resolved_at');
            }

            if (! Schema::hasColumn('findings', 'status_history_json')) {
                $table->json('status_history_json')->nullable()->after('evidence_json');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            if (Schema::hasColumn('findings', 'approved_by_user_id')) {
                $table->dropForeign(['approved_by_user_id']);
                $table->dropColumn('approved_by_user_id');
            }

            if (Schema::hasColumn('findings', 'dismissed_by_user_id')) {
                $table->dropForeign(['dismissed_by_user_id']);
                $table->dropColumn('dismissed_by_user_id');
            }

            $columns = [
                'under_review_at',
                'approved_at',
                'assigned_at',
                'resolution_notes',
                'status_history_json',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('findings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
