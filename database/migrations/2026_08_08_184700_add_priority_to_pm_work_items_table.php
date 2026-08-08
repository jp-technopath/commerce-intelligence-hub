<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pm_work_items', 'priority')) {
            Schema::table('pm_work_items', function (Blueprint $table) {
                $table->string('priority')->nullable()->default('Medium')->after('item_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pm_work_items', 'priority')) {
            Schema::table('pm_work_items', function (Blueprint $table) {
                $table->dropColumn('priority');
            });
        }
    }
};
