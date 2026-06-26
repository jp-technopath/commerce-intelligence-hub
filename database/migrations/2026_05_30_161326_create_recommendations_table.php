<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->text('recommendation_text');
            $table->text('ai_summary')->nullable();
            $table->text('confidence_reasoning')->nullable();
            $table->string('model_used')->nullable(); // e.g. gpt-5.5-preview
            $table->timestamps();

            $table->index('finding_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
