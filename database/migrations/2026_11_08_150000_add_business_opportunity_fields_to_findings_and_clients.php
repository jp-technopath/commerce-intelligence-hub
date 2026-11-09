<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('findings')) {
            Schema::table('findings', function (Blueprint $table) {
                if (! Schema::hasColumn('findings', 'finding_pillar')) {
                    $table->string('finding_pillar', 32)->nullable()->after('finding_category')->index();
                }
                if (! Schema::hasColumn('findings', 'impact_nature')) {
                    $table->string('impact_nature', 32)->nullable()->after('estimated_revenue_impact');
                }
                if (! Schema::hasColumn('findings', 'impact_assumptions_json')) {
                    $table->json('impact_assumptions_json')->nullable()->after('impact_nature');
                }
                if (! Schema::hasColumn('findings', 'priority_score')) {
                    $table->decimal('priority_score', 5, 2)->nullable()->after('confidence_score');
                }
                if (! Schema::hasColumn('findings', 'target_role')) {
                    $table->string('target_role', 64)->nullable()->after('priority_score');
                }
                if (! Schema::hasColumn('findings', 'account_identity_hash')) {
                    $table->string('account_identity_hash', 64)->nullable()->after('fingerprint')->index();
                }
            });
        }

        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table) {
                if (! Schema::hasColumn('clients', 'business_profile_json')) {
                    $table->json('business_profile_json')->nullable()->after('business_context');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('findings')) {
            Schema::table('findings', function (Blueprint $table) {
                $columns = ['finding_pillar', 'impact_nature', 'impact_assumptions_json', 'priority_score', 'target_role', 'account_identity_hash'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('findings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table) {
                if (Schema::hasColumn('clients', 'business_profile_json')) {
                    $table->dropColumn('business_profile_json');
                }
            });
        }
    }
};
