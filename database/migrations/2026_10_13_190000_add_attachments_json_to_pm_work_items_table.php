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
            if (! Schema::hasColumn('pm_work_items', 'attachments_json')) {
                $table->json('attachments_json')->nullable()->after('labels_json');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pm_work_items', function (Blueprint $table) {
            if (Schema::hasColumn('pm_work_items', 'attachments_json')) {
                $table->dropColumn('attachments_json');
            }
        });
    }
};
