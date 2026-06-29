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
        // (it may have been dropped in a prior failed run)
        $indexExists = DB::select("
            SHOW INDEX FROM `email_marketing_metrics`
            WHERE Key_name = 'emm_unique_metric'
        ");

        if (! empty($indexExists)) {
            Schema::table('email_marketing_metrics', function (Blueprint $table) {
                $table->dropUnique('emm_unique_metric');
            });
        }

        // Use raw SQL with prefix lengths to stay within MySQL's 3072-byte key limit
        DB::statement('
            ALTER TABLE `email_marketing_metrics`
            ADD UNIQUE `emm_unique_metric` (
                `client_id`,
                `date`,
                `source`(50),
                `type`(50),
                `channel`(30),
                `campaign_name`(100)
            )
        ');
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
