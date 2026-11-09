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
            $table->unsignedBigInteger('recommendation_id')->nullable()->change();

            if (! Schema::hasColumn('recommendation_outcomes', 'baseline_expected_revenue')) {
                $table->decimal('baseline_expected_revenue', 15, 2)->default(0.00)->after('observed_revenue');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'orders_count')) {
                $table->integer('orders_count')->default(0)->after('actual_incremental_impact');
            }

            if (! Schema::hasColumn('recommendation_outcomes', 'is_zero_outcome')) {
                $table->boolean('is_zero_outcome')->default(false)->after('orders_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recommendation_outcomes', function (Blueprint $table) {
            if (Schema::hasColumn('recommendation_outcomes', 'baseline_expected_revenue')) {
                $table->dropColumn('baseline_expected_revenue');
            }
            if (Schema::hasColumn('recommendation_outcomes', 'orders_count')) {
                $table->dropColumn('orders_count');
            }
            if (Schema::hasColumn('recommendation_outcomes', 'is_zero_outcome')) {
                $table->dropColumn('is_zero_outcome');
            }
        });
    }
};
