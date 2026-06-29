<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_marketing_metrics', function (Blueprint $table) {
            // Drop old constraint that doesn't include channel
            $table->dropUnique('emm_unique_metric');

            // Re-create with channel included so email + sms rows coexist
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
