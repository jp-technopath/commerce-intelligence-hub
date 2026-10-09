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
        // 1. connected_accounts: Provider-independent external user ID
        if (Schema::hasTable('connected_accounts')) {
            Schema::table('connected_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('connected_accounts', 'external_account_id')) {
                    $table->string('external_account_id')->nullable()->after('provider');
                    $table->index(['provider', 'external_account_id'], 'conn_accts_prov_ext_id_idx');
                }
            });
        }

        // 2. pm_worklogs: Link to User and external author account identifier
        if (Schema::hasTable('pm_worklogs')) {
            Schema::table('pm_worklogs', function (Blueprint $table) {
                if (! Schema::hasColumn('pm_worklogs', 'user_id')) {
                    $table->foreignId('user_id')
                        ->nullable()
                        ->after('client_id')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('pm_worklogs', 'external_author_id')) {
                    $table->string('external_author_id')->nullable()->after('user_id')->index();
                }
            });
        }

        // 3. clients: Contractual monthly allocated hours
        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table) {
                if (! Schema::hasColumn('clients', 'monthly_allocated_hours')) {
                    $table->unsignedInteger('monthly_allocated_hours')->nullable()->after('currency');
                }
            });
        }

        // 4. projects: Project-level monthly allocated hours
        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                if (! Schema::hasColumn('projects', 'monthly_allocated_hours')) {
                    $table->unsignedInteger('monthly_allocated_hours')->nullable()->after('budget_amount');
                }
            });
        }

        // 5. findings: Action center fields, attribution, and deduplication lifecycle
        if (Schema::hasTable('findings')) {
            Schema::table('findings', function (Blueprint $table) {
                if (! Schema::hasColumn('findings', 'responsible_user_id')) {
                    $table->foreignId('responsible_user_id')
                        ->nullable()
                        ->after('client_id')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('findings', 'project_id')) {
                    $table->foreignId('project_id')
                        ->nullable()
                        ->after('responsible_user_id')
                        ->constrained('projects')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('findings', 'source_type')) {
                    $table->string('source_type')->nullable()->after('finding_type')->index();
                }
                if (! Schema::hasColumn('findings', 'source_id')) {
                    $table->string('source_id')->nullable()->after('source_type')->index();
                }
                if (! Schema::hasColumn('findings', 'fingerprint')) {
                    $table->string('fingerprint', 64)->nullable()->after('source_id')->index();
                }
                if (! Schema::hasColumn('findings', 'evidence_json')) {
                    $table->json('evidence_json')->nullable()->after('metadata_json');
                }
                if (! Schema::hasColumn('findings', 'snoozed_until')) {
                    $table->timestamp('snoozed_until')->nullable()->after('status');
                }
                if (! Schema::hasColumn('findings', 'resolved_at')) {
                    $table->timestamp('resolved_at')->nullable()->after('snoozed_until');
                }
                if (! Schema::hasColumn('findings', 'dismissed_at')) {
                    $table->timestamp('dismissed_at')->nullable()->after('resolved_at');
                }
                if (! Schema::hasColumn('findings', 'dismissed_reason')) {
                    $table->text('dismissed_reason')->nullable()->after('dismissed_at');
                }
                if (! Schema::hasColumn('findings', 'escalation_level')) {
                    $table->string('escalation_level', 32)->default('standard')->after('severity');
                }

                $table->index(['responsible_user_id', 'status'], 'findings_resp_user_status_idx');
                $table->index(['project_id', 'status'], 'findings_project_status_idx');
                $table->index(['source_type', 'source_id'], 'findings_source_type_id_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('findings')) {
            Schema::table('findings', function (Blueprint $table) {
                $table->dropIndex('findings_resp_user_status_idx');
                $table->dropIndex('findings_project_status_idx');
                $table->dropIndex('findings_source_type_id_idx');

                $table->dropForeign(['responsible_user_id']);
                $table->dropForeign(['project_id']);
                $table->dropColumn([
                    'responsible_user_id',
                    'project_id',
                    'source_type',
                    'source_id',
                    'fingerprint',
                    'evidence_json',
                    'snoozed_until',
                    'resolved_at',
                    'dismissed_at',
                    'dismissed_reason',
                    'escalation_level',
                ]);
            });
        }

        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('monthly_allocated_hours');
            });
        }

        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('monthly_allocated_hours');
            });
        }

        if (Schema::hasTable('pm_worklogs')) {
            Schema::table('pm_worklogs', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn(['user_id', 'external_author_id']);
            });
        }

        if (Schema::hasTable('connected_accounts')) {
            Schema::table('connected_accounts', function (Blueprint $table) {
                $table->dropIndex('conn_accts_prov_ext_id_idx');
                $table->dropColumn('external_account_id');
            });
        }
    }
};
