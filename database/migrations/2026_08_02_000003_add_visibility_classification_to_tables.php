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
        $tables = ['findings', 'recommendations', 'deployments', 'client_meetings'];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (! Schema::hasColumn($tableName, 'visibility_classification')) {
                        $table->string('visibility_classification')->default('internal')->nullable();
                    }
                    if (! Schema::hasColumn($tableName, 'is_customer_visible')) {
                        $table->boolean('is_customer_visible')->default(false);
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['findings', 'recommendations', 'deployments', 'client_meetings'];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (Schema::hasColumn($tableName, 'visibility_classification')) {
                        $table->dropColumn('visibility_classification');
                    }
                    if (Schema::hasColumn($tableName, 'is_customer_visible')) {
                        $table->dropColumn('is_customer_visible');
                    }
                });
            }
        }
    }
};
