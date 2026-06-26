<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('root_cause')->nullable();
            $table->text('fix_implemented')->nullable();
            $table->text('outcome')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->timestamps();

            $table->index('finding_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_notes');
    }
};
