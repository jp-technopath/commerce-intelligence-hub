<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // We will add unique index on external_item_id once deduplication completes or via migration
        Schema::table('pm_work_items', function (Blueprint $table) {
            $table->index(['client_id', 'normalized_delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('pm_work_items', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'normalized_delivery_status']);
        });
    }
};
