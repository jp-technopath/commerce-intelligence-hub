<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_metrics', function (Blueprint $table) {
            $table->unsignedInteger('active_users')->default(0)->after('sessions');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_metrics', function (Blueprint $table) {
            $table->dropColumn('active_users');
        });
    }
};
