<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('revenue', 15, 2)->nullable();
            $table->unsignedInteger('orders')->nullable();
            $table->decimal('conversion_rate', 8, 4)->nullable();
            $table->decimal('average_order_value', 10, 2)->nullable();
            $table->unsignedInteger('sessions')->nullable();
            $table->unsignedInteger('new_customers')->nullable();
            $table->unsignedInteger('returning_customers')->nullable();
            $table->json('source_breakdown_json')->nullable();
            $table->json('device_breakdown_json')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_metrics');
    }
};
