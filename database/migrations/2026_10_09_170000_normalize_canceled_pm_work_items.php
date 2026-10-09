<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('pm_work_items')
            ->where(function ($q) {
                $q->whereRaw('LOWER(external_status) LIKE ?', ['%cancel%'])
                  ->orWhereRaw('LOWER(external_status) LIKE ?', ['%reject%'])
                  ->orWhereRaw('LOWER(external_status) LIKE ?', ['%abort%'])
                  ->orWhereRaw('LOWER(external_status) LIKE ?', ['%wont%'])
                  ->orWhereRaw('LOWER(external_status) LIKE ?', ["%won't%"]);
            })
            ->update(['normalized_delivery_status' => 'cancelled']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op rollback: status cannot be safely reverted to non-cancelled without Jira sync
    }
};
