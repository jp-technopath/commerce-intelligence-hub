<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('source', 30)->default('adobe_commerce'); // adobe_commerce, shopify

            // Stock levels
            $table->integer('total_products')->nullable();
            $table->integer('in_stock_count')->nullable();
            $table->integer('out_of_stock_count')->nullable();
            $table->integer('low_stock_count')->nullable();       // Below threshold

            // Rates
            $table->float('out_of_stock_rate')->nullable();       // % of catalog out of stock
            $table->float('low_stock_rate')->nullable();          // % of catalog low stock

            // Turnover
            $table->float('inventory_turnover')->nullable();      // Sold / avg stock
            $table->integer('backorder_count')->nullable();

            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'date', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_metrics');
    }
};
