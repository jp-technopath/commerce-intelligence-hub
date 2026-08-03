<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_reconciliation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->dateTime('reporting_start');
            $table->dateTime('reporting_end');
            $table->unsignedInteger('adobe_transaction_count')->default(0);
            $table->unsignedInteger('ga4_transaction_count')->default(0);
            $table->unsignedInteger('matched_transaction_count')->default(0);
            $table->unsignedInteger('missing_in_ga4_count')->default(0);
            $table->unsignedInteger('missing_in_adobe_count')->default(0);
            $table->unsignedInteger('duplicate_ga4_count')->default(0);
            $table->decimal('adobe_net_revenue', 15, 2)->default(0.00);
            $table->decimal('ga4_tracked_revenue', 15, 2)->default(0.00);
            $table->decimal('absolute_difference', 15, 2)->default(0.00);
            $table->decimal('percentage_difference', 8, 4)->default(0.00);
            $table->string('validation_status', 50)->default('valid'); // valid, review_recommended, material_discrepancy
            $table->string('calculation_version', 20)->default('v1.0.0');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'reporting_start', 'reporting_end'], 'idx_recon_client_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_reconciliation_results');
    }
};
