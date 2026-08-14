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
        Schema::table('pm_work_items', function (Blueprint $table) {
            if (! Schema::hasColumn('pm_work_items', 'labels_json')) {
                $table->json('labels_json')->nullable()->after('blocked_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pm_work_items', function (Blueprint $table) {
            if (Schema::hasColumn('pm_work_items', 'labels_json')) {
                $table->dropColumn('labels_json');
            }
        });
    }
};
