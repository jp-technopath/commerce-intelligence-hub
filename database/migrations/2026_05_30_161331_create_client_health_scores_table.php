<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_health_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('health_score')->default(0); // 0-100
            $table->string('risk_level')->default('attention_needed'); // RiskLevel enum
            $table->json('score_breakdown_json')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'date']);
            $table->index('date');
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_health_scores');
    }
};
