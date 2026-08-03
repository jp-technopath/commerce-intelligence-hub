<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_quality_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('finding_type', 100);             // revenue_mismatch, missing_reporting_days, etc.
            $table->string('affected_metric', 100);
            $table->string('severity', 30)->default('review_recommended'); // info, review_recommended, material_discrepancy
            $table->dateTime('reporting_start');
            $table->dateTime('reporting_end');
            $table->string('detection_rule', 150);
            $table->json('supporting_values_json')->nullable();
            $table->text('recommended_investigation')->nullable();
            $table->dateTime('first_detected_at');
            $table->dateTime('last_detected_at');
            $table->dateTime('resolved_at')->nullable();
            $table->string('status', 30)->default('open');   // open, acknowledged, resolved, reopened, ignored
            $table->string('calculation_version', 20)->default('v1.0.0');
            $table->timestamps();

            $table->index(['client_id', 'status', 'finding_type'], 'idx_dqf_client_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_quality_findings');
    }
};
