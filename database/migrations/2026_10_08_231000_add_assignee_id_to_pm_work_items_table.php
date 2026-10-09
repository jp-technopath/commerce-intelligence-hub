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
        if (Schema::hasTable('pm_work_items')) {
            Schema::table('pm_work_items', function (Blueprint $table) {
                if (! Schema::hasColumn('pm_work_items', 'user_id')) {
                    $table->foreignId('user_id')
                        ->nullable()
                        ->after('assignee_name')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('pm_work_items', 'external_assignee_id')) {
                    $table->string('external_assignee_id')->nullable()->after('user_id')->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pm_work_items')) {
            Schema::table('pm_work_items', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn(['user_id', 'external_assignee_id']);
            });
        }
    }
};
