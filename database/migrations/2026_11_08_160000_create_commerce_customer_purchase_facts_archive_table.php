<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_customer_purchase_facts_archive', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_fact_id')->nullable();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('customer_identity_hash', 64);
            $table->string('customer_id', 100)->nullable();
            $table->dateTime('first_valid_order_at');
            $table->dateTime('latest_valid_order_at');
            $table->unsignedInteger('lifetime_valid_order_count')->default(1);
            $table->decimal('lifetime_net_revenue', 15, 2)->default(0.00);
            $table->boolean('is_registered_customer')->default(false);
            $table->dateTime('refreshed_at')->nullable();
            $table->string('archive_reason', 150)->default('reconciliation_orphaned_hash');
            $table->dateTime('archived_at')->useCurrent();
            $table->timestamps();

            $table->index(['client_id', 'customer_identity_hash'], 'idx_archived_facts_hash');
            $table->index(['client_id', 'archived_at'], 'idx_archived_facts_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_customer_purchase_facts_archive');
    }
};
