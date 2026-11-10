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
        if (! Schema::hasColumn('pm_worklogs', 'canonical_worklog_key')) {
            Schema::table('pm_worklogs', function (Blueprint $table) {
                $table->string('canonical_worklog_key', 191)->nullable()->after('external_worklog_id');
            });
        }

        // Backfill existing worklogs with stable canonical keys: site_identity:external_worklog_id
        $connections = DB::table('pm_connections')->get(['id', 'external_workspace_id', 'configuration_json']);
        $defaultSite = parse_url(config('meeting_agent.jira.base_url', 'https://technopath.atlassian.net'), PHP_URL_HOST) ?: 'technopath.atlassian.net';
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        foreach ($connections as $conn) {
            $site = $conn->external_workspace_id ?: $defaultSite;
            $concatSql = $isSqlite
                ? "'{$site}:' || external_worklog_id"
                : "CONCAT('{$site}', ':', external_worklog_id)";

            DB::table('pm_worklogs')
                ->where('pm_connection_id', $conn->id)
                ->whereNull('canonical_worklog_key')
                ->update(['canonical_worklog_key' => DB::raw($concatSql)]);
        }

        // Fallback for any orphaned rows without valid connection
        $fallbackConcat = $isSqlite
            ? "'{$defaultSite}:' || external_worklog_id"
            : "CONCAT('{$defaultSite}', ':', external_worklog_id)";

        DB::table('pm_worklogs')
            ->whereNull('canonical_worklog_key')
            ->update(['canonical_worklog_key' => DB::raw($fallbackConcat)]);

        // Ensure canonical_worklog_key has a unique index
        Schema::table('pm_worklogs', function (Blueprint $table) {
            $table->unique('canonical_worklog_key', 'pm_worklogs_canonical_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pm_worklogs', function (Blueprint $table) {
            $table->dropUnique('pm_worklogs_canonical_key_unique');
            $table->dropColumn('canonical_worklog_key');
        });
    }
};
