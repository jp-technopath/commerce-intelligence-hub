<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('project_lead_id')
                ->nullable()
                ->after('owner_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Backfill project_lead_id from owner_id if present
        DB::statement('UPDATE projects SET project_lead_id = owner_id WHERE owner_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['project_lead_id']);
            $table->dropColumn('project_lead_id');
        });
    }
};
