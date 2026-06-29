<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('findings_comparison_period')
                  ->default(7)
                  ->after('monitoring_config')
                  ->comment('Days for findings engine comparison: 7=WoW, 14=2wk, 30=MoM');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('findings_comparison_period');
        });
    }
};
