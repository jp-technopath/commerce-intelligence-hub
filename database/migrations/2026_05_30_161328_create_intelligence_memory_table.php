<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_memory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('finding_type')->nullable();
            $table->string('finding_category')->nullable();
            $table->text('pattern_description')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('resolution')->nullable();
            $table->text('outcome')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('finding_type');
            $table->index('finding_category');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_memory');
    }
};
