<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guardrail #2: Replace the regular unique constraint on client_meetings with
 * a partial unique index. PostgreSQL allows multiple NULLs in unique constraints,
 * but a partial index is more semantically correct: only calendar-sourced
 * meetings (where all three identity fields are non-null) should be deduplicated.
 * Manual meetings (where these fields are null) are unaffected.
 * 
 * Note: MySQL does not support partial unique indexes with a WHERE clause.
 * However, in MySQL, a standard UNIQUE constraint naturally allows multiple NULL 
 * values across nullable columns. Therefore, on MySQL (used in Laravel Cloud), 
 * we safely skip dropping and recreating this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            return;
        }

        // Drop the existing regular unique constraint
        Schema::table('client_meetings', function ($table) {
            $table->dropUnique('client_meetings_scanner_calendar_event_unique');
        });

        // Create a partial unique index — only enforced when all three fields are present
        DB::statement('
            CREATE UNIQUE INDEX client_meetings_scanner_calendar_event_unique
            ON client_meetings (scanned_by_user_id, google_calendar_id, google_event_id)
            WHERE scanned_by_user_id IS NOT NULL
              AND google_calendar_id IS NOT NULL
              AND google_event_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            return;
        }

        // Drop the partial index
        DB::statement('DROP INDEX IF EXISTS client_meetings_scanner_calendar_event_unique');

        // Restore the regular unique constraint
        Schema::table('client_meetings', function ($table) {
            $table->unique(
                ['scanned_by_user_id', 'google_calendar_id', 'google_event_id'],
                'client_meetings_scanner_calendar_event_unique'
            );
        });
    }
};
