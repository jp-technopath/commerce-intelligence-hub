<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_environment_mappings', function (Blueprint $table) {
            $table->string('worker_service_account_email')
                ->nullable()
                ->after('vm_name');

            $table->index(
                ['worker_service_account_email', 'is_active'],
                'project_environment_mapping_worker_identity_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('project_environment_mappings', function (Blueprint $table) {
            $table->dropIndex('project_environment_mapping_worker_identity_idx');
            $table->dropColumn('worker_service_account_email');
        });
    }
};
