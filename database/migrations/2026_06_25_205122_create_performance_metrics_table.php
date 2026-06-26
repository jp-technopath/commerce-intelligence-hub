<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('source', 30)->default('ga4'); // ga4, clarity

            // Core Web Vitals
            $table->float('lcp')->nullable();            // Largest Contentful Paint (seconds)
            $table->float('fid')->nullable();             // First Input Delay (ms) — legacy
            $table->float('inp')->nullable();             // Interaction to Next Paint (ms)
            $table->float('cls')->nullable();             // Cumulative Layout Shift (score)
            $table->float('ttfb')->nullable();            // Time to First Byte (ms)

            // Page speed
            $table->float('page_load_time')->nullable();  // Average page load (seconds)
            $table->float('server_response_time')->nullable(); // Server response (ms)

            // Engagement performance
            $table->float('bounce_rate')->nullable();     // % single-page sessions
            $table->integer('slow_pages_count')->nullable(); // Pages exceeding threshold

            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'date', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_metrics');
    }
};
