<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_customer_purchase_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('customer_identity_hash', 64);
            $table->string('customer_id', 100)->nullable();
            $table->dateTime('first_valid_order_at');
            $table->dateTime('latest_valid_order_at');
            $table->unsignedInteger('lifetime_valid_order_count')->default(1);
            $table->decimal('lifetime_net_revenue', 15, 2)->default(0.00);
            $table->boolean('is_registered_customer')->default(false);
            $table->dateTime('refreshed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['client_id', 'customer_identity_hash'], 'idx_cust_facts_unique');
            $table->index(['client_id', 'first_valid_order_at'], 'idx_cust_facts_first_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_customer_purchase_facts');
    }
};
