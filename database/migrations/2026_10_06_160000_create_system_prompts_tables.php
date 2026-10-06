<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category')->default('general')->index();
            $table->text('description')->nullable();
            $table->longText('draft_system_prompt')->nullable();
            $table->longText('draft_user_prompt_template')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('draft_updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('draft_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_prompt_id')->constrained('system_prompts')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->longText('system_prompt');
            $table->longText('user_prompt_template');
            $table->text('publish_notes')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['system_prompt_id', 'version_number']);
            $table->index(['system_prompt_id', 'created_at']);
        });

        Schema::table('system_prompts', function (Blueprint $table) {
            $table->foreign('published_version_id')
                ->references('id')
                ->on('system_prompt_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('system_prompts', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });

        Schema::dropIfExists('system_prompt_versions');
        Schema::dropIfExists('system_prompts');
    }
};
