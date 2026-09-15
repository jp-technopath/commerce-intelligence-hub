<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('development_requests') && ! Schema::hasColumn('development_requests', 'project_environment_mapping_id')) {
            Schema::table('development_requests', function (Blueprint $table) {
                $table->foreignId('project_environment_mapping_id')->nullable()->after('environment_key')->constrained('project_environment_mappings', indexName: 'dev_req_pem_id_fk')->nullOnDelete();
                $table->json('routing_snapshot')->nullable()->after('jira_snapshot');
                $table->string('selected_capability_tier')->nullable()->after('routing_snapshot');
            });
        }
    }

    public function down(): void
    {
        Schema::table('development_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_environment_mapping_id');
            $table->dropColumn(['routing_snapshot', 'selected_capability_tier']);
        });
    }
};
