<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('authorized_email')->nullable();
            $table->text('credentials_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('token_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};
