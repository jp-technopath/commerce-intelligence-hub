<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safely drop the old constraint if it still exists
        $indexExists = DB::select("
            SELECT 1 FROM pg_indexes
            WHERE tablename = 'email_marketing_metrics'
              AND indexname = 'emm_unique_metric'
        ");

        if (! empty($indexExists)) {
            Schema::table('email_marketing_metrics', function (Blueprint $table) {
                $table->dropUnique('emm_unique_metric');
            });
        }

        // Create the unique constraint with channel included
        Schema::table('email_marketing_metrics', function (Blueprint $table) {
            $table->unique(
                ['client_id', 'date', 'source', 'type', 'channel', 'campaign_name'],
                'emm_unique_metric'
            );
        });
    }

    public function down(): void
    {
        Schema::table('email_marketing_metrics', function (Blueprint $table) {
            $table->dropUnique('emm_unique_metric');
            $table->unique(
                ['client_id', 'date', 'source', 'type', 'campaign_name'],
                'emm_unique_metric'
            );
        });
    }
};
