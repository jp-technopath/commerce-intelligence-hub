<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pm_worklogs', function (Blueprint $table) {
            if (! Schema::hasColumn('pm_worklogs', 'attribution_source')) {
                $table->string('attribution_source', 40)->nullable()->after('author_name');
            }
            if (! Schema::hasColumn('pm_worklogs', 'attribution_metadata_json')) {
                $table->json('attribution_metadata_json')->nullable()->after('attribution_source');
            }
            if (! Schema::hasColumn('pm_worklogs', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            $table->index(['client_id', 'attribution_source'], 'pm_worklogs_client_attribution_idx');
            $table->index(['external_worklog_id', 'pm_connection_id'], 'pm_worklogs_ext_conn_idx');
        });

        // Backfill existing rows:
        // Rows with user_id IS NOT NULL -> 'legacy_attributed'
        // Rows with user_id IS NULL -> 'unattributed'
        DB::table('pm_worklogs')
            ->whereNotNull('user_id')
            ->whereNull('attribution_source')
            ->update(['attribution_source' => 'legacy_attributed']);

        DB::table('pm_worklogs')
            ->whereNull('user_id')
            ->whereNull('attribution_source')
            ->update(['attribution_source' => 'unattributed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pm_worklogs', function (Blueprint $table) {
            if (Schema::hasColumn('pm_worklogs', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
            if (Schema::hasColumn('pm_worklogs', 'attribution_metadata_json')) {
                $table->dropColumn('attribution_metadata_json');
            }
            if (Schema::hasColumn('pm_worklogs', 'attribution_source')) {
                $table->dropIndex('pm_worklogs_client_attribution_idx');
                $table->dropIndex('pm_worklogs_ext_conn_idx');
                $table->dropColumn('attribution_source');
            }
        });
    }
};
