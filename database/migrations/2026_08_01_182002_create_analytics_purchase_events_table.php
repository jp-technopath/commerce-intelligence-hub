<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_purchase_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50)->default('ga4');
            $table->string('transaction_id', 100);
            $table->date('event_date');
            $table->dateTime('event_timestamp');
            $table->decimal('tracked_revenue', 15, 2)->default(0.00);
            $table->string('currency', 3)->default('USD');
            $table->string('user_pseudo_id', 100)->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->string('duplicate_reason', 100)->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->dateTime('collected_at')->useCurrent();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'integration_id', 'source', 'transaction_id'], 'idx_analytics_tx_unique');
            $table->index(['client_id', 'event_date'], 'idx_analytics_client_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_purchase_events');
    }
};
