<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_marketing_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('source')->default('klaviyo');
            $table->string('type');           // 'campaign' or 'flow'
            $table->string('channel');        // 'email' or 'sms'
            $table->string('campaign_name')->nullable();
            $table->string('flow_id')->nullable();
            $table->integer('recipients')->default(0);
            $table->integer('opens')->default(0);
            $table->integer('clicks')->default(0);
            $table->integer('conversions')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->integer('unsubscribes')->default(0);
            $table->integer('bounces')->default(0);
            $table->decimal('open_rate', 8, 4)->default(0);
            $table->decimal('click_rate', 8, 4)->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'date', 'source', 'type', 'campaign_name'], 'emm_unique_metric');
            $table->index(['client_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_marketing_metrics');
    }
};
