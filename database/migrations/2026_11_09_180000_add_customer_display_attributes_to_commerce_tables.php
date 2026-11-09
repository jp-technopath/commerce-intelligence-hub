<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->after('customer_identity_hash')->index();
            $table->string('customer_name')->nullable()->after('customer_email');
            $table->string('company_name')->nullable()->after('customer_name');
        });

        Schema::table('commerce_customer_purchase_facts', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->after('customer_id')->index();
            $table->string('customer_name')->nullable()->after('customer_email');
            $table->string('company_name')->nullable()->after('customer_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commerce_customer_purchase_facts', function (Blueprint $table) {
            $table->dropIndex(['customer_email']);
            $table->dropColumn(['customer_email', 'customer_name', 'company_name']);
        });

        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->dropIndex(['customer_email']);
            $table->dropColumn(['customer_email', 'customer_name', 'company_name']);
        });
    }
};
