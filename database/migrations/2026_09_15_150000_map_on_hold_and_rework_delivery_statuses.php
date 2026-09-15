<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pm_work_items')) {
            return;
        }

        // 1. Backfill "On Hold" / "Hold" / "Paused" raw statuses to "on_hold"
        DB::table('pm_work_items')
            ->where(function ($query) {
                $query->whereRaw('LOWER(external_status) LIKE ?', ['%on hold%'])
                    ->orWhereRaw('LOWER(external_status) LIKE ?', ['%hold%'])
                    ->orWhereRaw('LOWER(external_status) LIKE ?', ['%paused%']);
            })
            ->update([
                'normalized_delivery_status' => 'on_hold',
                'updated_at'                 => now(),
            ]);

        // 2. Backfill "Rework" / "Revision" / "Re-work" raw statuses to "rework"
        DB::table('pm_work_items')
            ->where(function ($query) {
                $query->whereRaw('LOWER(external_status) LIKE ?', ['%rework%'])
                    ->orWhereRaw('LOWER(external_status) LIKE ?', ['%revision%'])
                    ->orWhereRaw('LOWER(external_status) LIKE ?', ['%re-work%']);
            })
            ->update([
                'normalized_delivery_status' => 'rework',
                'updated_at'                 => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pm_work_items')) {
            return;
        }

        // Revert on_hold and rework back to planned
        DB::table('pm_work_items')
            ->whereIn('normalized_delivery_status', ['on_hold', 'rework'])
            ->update([
                'normalized_delivery_status' => 'planned',
                'updated_at'                 => now(),
            ]);
    }
};
