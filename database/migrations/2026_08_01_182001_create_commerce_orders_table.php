<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50)->default('adobe_commerce');
            $table->string('source_order_id', 100);
            $table->string('source_increment_id', 100)->nullable();
            $table->string('order_status', 50)->default('processing');
            $table->string('customer_identity_hash', 64)->nullable();
            $table->string('registered_customer_id', 100)->nullable();
            $table->dateTime('order_date');
            $table->dateTime('refund_date')->nullable();
            $table->decimal('gross_revenue', 15, 2)->default(0.00);
            $table->decimal('refunded_revenue', 15, 2)->default(0.00);
            $table->decimal('net_revenue', 15, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('shipping_amount', 12, 2)->default(0.00);
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->string('currency', 3)->default('USD');
            $table->string('base_currency', 3)->default('USD');
            $table->string('reporting_currency', 3)->default('USD');
            $table->decimal('exchange_rate', 12, 6)->default(1.000000);
            $table->boolean('is_valid')->default(true);
            $table->string('exclusion_reason', 100)->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->dateTime('financial_last_changed_at')->useCurrent();
            $table->dateTime('collected_at')->useCurrent();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'integration_id', 'source', 'source_order_id'], 'idx_comm_orders_unique');
            $table->index(['client_id', 'order_date', 'is_valid'], 'idx_comm_orders_client_date');
            $table->index(['client_id', 'customer_identity_hash'], 'idx_comm_orders_cust_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_orders');
    }
};
