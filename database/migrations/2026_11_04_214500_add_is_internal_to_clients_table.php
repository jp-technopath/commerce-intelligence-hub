<?php

use App\Models\Client;
use App\Models\Finding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'is_internal')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('is_internal')->default(false)->after('status')->index();
            });
        }

        // Flag internal agency clients
        Client::where('name', 'LIKE', '%Technopath Internal%')
            ->orWhereIn('jira_project_key', ['TEC', 'AGENCY'])
            ->update(['is_internal' => true]);

        // Purge any operational findings generated on internal clients
        $internalClientIds = Client::where('is_internal', true)->pluck('id');
        if ($internalClientIds->isNotEmpty()) {
            Finding::whereIn('client_id', $internalClientIds)->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'is_internal')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('is_internal');
            });
        }
    }
};
