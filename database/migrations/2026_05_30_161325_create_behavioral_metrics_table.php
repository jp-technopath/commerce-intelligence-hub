<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavioral_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            // Clarity: Rage Click Count
            $table->unsignedInteger('rage_clicks')->nullable();
            // Clarity: Dead Click Count
            $table->unsignedInteger('dead_clicks')->nullable();
            // Clarity: Quickback Click
            $table->unsignedInteger('quick_backs')->nullable();
            // Clarity: Excessive Scroll
            $table->unsignedInteger('excessive_scrolling')->nullable();
            // Clarity: Script Error Count
            $table->unsignedInteger('script_errors')->nullable();
            // Clarity: Error Click Count
            $table->unsignedInteger('error_clicks')->nullable();
            // Clarity: Scroll Depth (avg %)
            $table->decimal('scroll_depth', 5, 2)->nullable();
            // Clarity: Engagement Time (avg seconds)
            $table->decimal('engagement_time', 10, 2)->nullable();
            // Clarity: Traffic (session count)
            $table->unsignedInteger('traffic')->nullable();

            // Computed: weighted friction score 0-100
            $table->decimal('friction_score', 5, 2)->nullable();

            // Dimension group breakdowns: device_browser, traffic_source, page_device
            $table->json('metadata_json')->nullable();

            $table->timestamps();

            $table->unique(['client_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavioral_metrics');
    }
};
