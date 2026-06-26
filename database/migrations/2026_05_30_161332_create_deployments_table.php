<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('deployment_type'); // DeploymentType enum
            $table->text('description')->nullable();
            $table->string('deployed_by')->nullable();
            $table->timestamp('deployed_at');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'deployed_at']);
            $table->index('deployment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
