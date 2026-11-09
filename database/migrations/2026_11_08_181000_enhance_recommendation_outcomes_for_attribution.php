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
        Schema::table('recommendation_outcomes', function (Blueprint $table) {
            if (! Schema::hasColumn('recommendation_outcomes', 'finding_id')) {
                $table->foreignId('finding_id')
                    ->nullable()
                    ->after('recommendation_id')
                    ->constrained('findings')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'observed_revenue')) {
                $table->decimal('observed_revenue', 15, 2)->nullable()->after('actual_impact');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'actual_incremental_impact')) {
                $table->decimal('actual_incremental_impact', 15, 2)->nullable()->after('observed_revenue');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'attribution_method')) {
                $table->string('attribution_method', 50)->default('observed_only')->after('actual_incremental_impact');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'attribution_window_days')) {
                $table->integer('attribution_window_days')->default(30)->after('attribution_method');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'matched_orders_json')) {
                $table->json('matched_orders_json')->nullable()->after('attribution_window_days');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'methodology_notes')) {
                $table->text('methodology_notes')->nullable()->after('matched_orders_json');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'confidence_score')) {
                $table->decimal('confidence_score', 5, 2)->nullable()->after('methodology_notes');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('confidence_score');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recommendation_outcomes', function (Blueprint $table) {
            if (Schema::hasColumn('recommendation_outcomes', 'finding_id')) {
                $table->dropForeign(['finding_id']);
                $table->dropColumn('finding_id');
            }

            $columns = [
                'observed_revenue',
                'actual_incremental_impact',
                'attribution_method',
                'attribution_window_days',
                'matched_orders_json',
                'methodology_notes',
                'confidence_score',
                'verified_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('recommendation_outcomes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
