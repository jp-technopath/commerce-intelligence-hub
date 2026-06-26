<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('finding_type');
            $table->string('finding_category'); // FindingCategory enum
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('medium'); // FindingSeverity enum
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->decimal('estimated_revenue_impact', 15, 2)->nullable();
            $table->string('status')->default('new'); // FindingStatus enum
            $table->json('metadata_json')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'severity']);
            $table->index(['client_id', 'finding_category']);
            $table->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
