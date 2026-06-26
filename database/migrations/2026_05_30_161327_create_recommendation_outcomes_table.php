<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained()->cascadeOnDelete();
            $table->boolean('implemented')->default(false);
            $table->timestamp('implemented_at')->nullable();
            $table->decimal('estimated_impact', 15, 2)->nullable();
            $table->decimal('actual_impact', 15, 2)->nullable();
            $table->text('outcome_notes')->nullable();
            $table->timestamps();

            $table->unique('recommendation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_outcomes');
    }
};
