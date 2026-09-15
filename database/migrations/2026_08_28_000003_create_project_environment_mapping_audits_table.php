<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_environment_mapping_audits')) {
            return;
        }

        Schema::create('project_environment_mapping_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_environment_mapping_id')->constrained('project_environment_mappings', indexName: 'pem_audits_mapping_id_fk')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['project_environment_mapping_id', 'created_at'], 'mapping_audit_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environment_mapping_audits');
    }
};
