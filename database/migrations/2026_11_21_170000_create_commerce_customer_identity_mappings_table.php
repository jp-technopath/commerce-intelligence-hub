<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commerce_customer_identity_mappings')) {
            Schema::create('commerce_customer_identity_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->string('canonical_account_id', 128);
                $table->string('identity_type', 32); // registered_customer_id, normalized_email, source_identity_hash
                $table->string('identity_value', 255);
                $table->string('source_system', 64)->default('adobe_commerce');
                $table->decimal('confidence_score', 4, 2)->default(1.00);
                $table->json('provenance_metadata')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['client_id', 'identity_type', 'identity_value'], 'idx_cust_id_map_unique');
                $table->index(['client_id', 'canonical_account_id'], 'idx_cust_id_map_account');
            });
        }

        if (Schema::hasTable('commerce_orders') && ! Schema::hasColumn('commerce_orders', 'canonical_account_id')) {
            Schema::table('commerce_orders', function (Blueprint $table) {
                $table->string('canonical_account_id', 128)->nullable()->after('customer_identity_hash');
                $table->index(['client_id', 'canonical_account_id'], 'idx_comm_orders_canonical_account');
            });
        }

        if (Schema::hasTable('commerce_customer_purchase_facts') && ! Schema::hasColumn('commerce_customer_purchase_facts', 'canonical_account_id')) {
            Schema::table('commerce_customer_purchase_facts', function (Blueprint $table) {
                $table->string('canonical_account_id', 128)->nullable()->after('customer_identity_hash');
                $table->index(['client_id', 'canonical_account_id'], 'idx_cust_facts_canonical_account');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('commerce_orders') && Schema::hasColumn('commerce_orders', 'canonical_account_id')) {
            Schema::table('commerce_orders', function (Blueprint $table) {
                $table->dropIndex('idx_comm_orders_canonical_account');
                $table->dropColumn('canonical_account_id');
            });
        }

        if (Schema::hasTable('commerce_customer_purchase_facts') && Schema::hasColumn('commerce_customer_purchase_facts', 'canonical_account_id')) {
            Schema::table('commerce_customer_purchase_facts', function (Blueprint $table) {
                $table->dropIndex('idx_cust_facts_canonical_account');
                $table->dropColumn('canonical_account_id');
            });
        }

        Schema::dropIfExists('commerce_customer_identity_mappings');
    }
};
