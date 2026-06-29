<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only drop if the old constraint still exists (may have been removed already)
        $exists = \Illuminate\Support\Facades\DB::select("
            SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_name = 'commerce_metrics_client_id_date_unique'
              AND table_name = 'commerce_metrics'
        ");

        if (! empty($exists)) {
            Schema::table('commerce_metrics', function (Blueprint $table) {
                $table->dropUnique('commerce_metrics_client_id_date_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('commerce_metrics', function (Blueprint $table) {
            $table->unique(['client_id', 'date'], 'commerce_metrics_client_id_date_unique');
        });
    }
};
