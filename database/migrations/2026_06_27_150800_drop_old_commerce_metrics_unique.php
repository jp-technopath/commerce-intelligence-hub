<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_metrics', function (Blueprint $table) {
            // Drop the old constraint that only uses client_id+date
            // This blocks multiple sources (ga4 + adobe_commerce) for the same client+date
            $table->dropUnique('commerce_metrics_client_id_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_metrics', function (Blueprint $table) {
            $table->unique(['client_id', 'date'], 'commerce_metrics_client_id_date_unique');
        });
    }
};
