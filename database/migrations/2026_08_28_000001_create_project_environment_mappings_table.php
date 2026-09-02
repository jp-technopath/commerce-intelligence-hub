<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_environment_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pm_project_id')->nullable()->constrained('pm_projects')->nullOnDelete();
            $table->foreignId('repository_id')->constrained('repositories')->restrictOnDelete();
            $table->string('environment_key');
            $table->string('gcp_project_id');
            $table->string('gcp_zone');
            $table->string('vm_name');
            $table->string('workspace_path');
            $table->string('default_branch')->default('main');
            $table->json('allowed_agent_roles');
            $table->json('allowed_capability_tiers');
            $table->string('default_capability_tier');
            $table->json('tier_recommendation_policy');
            $table->json('model_group_aliases');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'environment_key', 'version'], 'project_environment_mapping_version_unique');
            $table->index(['project_id', 'environment_key', 'is_active'], 'project_environment_mapping_resolution_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environment_mappings');
    }
};
