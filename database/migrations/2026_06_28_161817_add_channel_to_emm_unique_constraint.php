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
        $driver = DB::getDriverName();
        $indexExists = false;

        if ($driver === 'pgsql') {
            $indexExists = ! empty(DB::select("
                SELECT 1 FROM pg_indexes
                WHERE tablename = 'email_marketing_metrics'
                  AND indexname = 'emm_unique_metric'
            "));
        } else {
            try {
                $indexExists = ! empty(DB::select("
                    SHOW INDEX FROM email_marketing_metrics
                    WHERE Key_name = 'emm_unique_metric'
                "));
            } catch (\Throwable $e) {
                $indexExists = false;
            }
        }

        if ($indexExists) {
            try {
                Schema::table('email_marketing_metrics', function (Blueprint $table) {
                    $table->dropUnique('emm_unique_metric');
                });
            } catch (\Throwable $e) {
                // Ignore if already dropped
            }
        }

        // Create the unique constraint with channel included
        if ($driver === 'pgsql') {
            Schema::table('email_marketing_metrics', function (Blueprint $table) {
                $table->unique(
                    ['client_id', 'date', 'source', 'type', 'channel', 'campaign_name'],
                    'emm_unique_metric'
                );
            });
        } else {
            DB::statement('ALTER TABLE email_marketing_metrics ADD UNIQUE INDEX emm_unique_metric (client_id, date, source(50), type(50), channel(50), campaign_name(150))');
        }
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
