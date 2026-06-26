<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_metrics', function (Blueprint $table) {
            $table->string('source', 50)->default('adobe_commerce')->after('date');
            $table->integer('items_sold')->default(0)->after('orders');
            $table->decimal('aov', 12, 2)->default(0)->after('average_order_value');
            $table->jsonb('metadata_json')->nullable()->after('device_breakdown_json');

            // Update unique constraint to include source
            $table->unique(['client_id', 'date', 'source'], 'commerce_metrics_client_date_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_metrics', function (Blueprint $table) {
            $table->dropUnique('commerce_metrics_client_date_source_unique');
            $table->dropColumn(['source', 'items_sold', 'aov', 'metadata_json']);
        });
    }
};
