<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commerce_orders')) {
            Schema::table('commerce_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('commerce_orders', 'order_state')) {
                    $table->string('order_state', 50)->nullable()->after('order_status');
                }
                if (! Schema::hasColumn('commerce_orders', 'payment_method')) {
                    $table->string('payment_method', 100)->nullable()->after('order_state');
                }
                if (! Schema::hasColumn('commerce_orders', 'invoiced_revenue')) {
                    $table->decimal('invoiced_revenue', 15, 2)->nullable()->after('gross_revenue');
                }
                if (! Schema::hasColumn('commerce_orders', 'paid_revenue')) {
                    $table->decimal('paid_revenue', 15, 2)->nullable()->after('invoiced_revenue');
                }
                if (! Schema::hasColumn('commerce_orders', 'canceled_revenue')) {
                    $table->decimal('canceled_revenue', 15, 2)->nullable()->after('refunded_revenue');
                }
                if (! Schema::hasColumn('commerce_orders', 'outstanding_receivable')) {
                    $table->decimal('outstanding_receivable', 15, 2)->nullable()->after('net_revenue');
                }
                if (! Schema::hasColumn('commerce_orders', 'qualification_classification')) {
                    $table->string('qualification_classification', 64)->nullable()->after('is_valid');
                }
                if (! Schema::hasColumn('commerce_orders', 'qualification_reason')) {
                    $table->string('qualification_reason', 120)->nullable()->after('qualification_classification');
                }
                if (! Schema::hasColumn('commerce_orders', 'qualifying_order_value')) {
                    $table->decimal('qualifying_order_value', 15, 2)->nullable()->after('net_revenue');
                }
                if (! Schema::hasColumn('commerce_orders', 'settlement_unverified_revenue')) {
                    $table->decimal('settlement_unverified_revenue', 15, 2)->nullable()->after('outstanding_receivable');
                }
                if (! Schema::hasColumn('commerce_orders', 'refundable_core_deposit')) {
                    $table->decimal('refundable_core_deposit', 15, 2)->nullable()->after('settlement_unverified_revenue');
                }
                if (! Schema::hasColumn('commerce_orders', 'core_deposit_refunded')) {
                    $table->decimal('core_deposit_refunded', 15, 2)->nullable()->after('refundable_core_deposit');
                }
                if (! Schema::hasColumn('commerce_orders', 'outstanding_core_deposit')) {
                    $table->decimal('outstanding_core_deposit', 15, 2)->nullable()->after('core_deposit_refunded');
                }
            });
        }

        if (! Schema::hasTable('client_configuration_audits')) {
            Schema::create('client_configuration_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id')->nullable()->index();
                $table->unsignedBigInteger('integration_id')->nullable()->index();
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->string('config_scope', 64);
                $table->unsignedInteger('version')->default(1);
                $table->json('changed_keys');
                $table->json('before_json')->nullable();
                $table->json('after_json');
                $table->string('source', 32)->default('system');
                $table->string('ip_address', 64)->nullable();
                $table->timestamps();

                $table->index(['client_id', 'config_scope', 'version'], 'cca_client_scope_ver_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_configuration_audits')) {
            Schema::dropIfExists('client_configuration_audits');
        }

        if (Schema::hasTable('commerce_orders')) {
            Schema::table('commerce_orders', function (Blueprint $table) {
                $cols = array_filter([
                    'order_state',
                    'payment_method',
                    'invoiced_revenue',
                    'paid_revenue',
                    'canceled_revenue',
                    'outstanding_receivable',
                    'qualification_classification',
                    'qualification_reason',
                    'qualifying_order_value',
                    'settlement_unverified_revenue',
                    'refundable_core_deposit',
                    'core_deposit_refunded',
                    'outstanding_core_deposit',
                ], fn (string $c) => Schema::hasColumn('commerce_orders', $c));

                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
