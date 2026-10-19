<?php

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Reattribute existing meetings for clients that have a project_lead_id
        $clients = Client::whereNotNull('project_lead_id')->get();
        foreach ($clients as $client) {
            $client->reattributeMeetingsToLead();
        }

        // Reattribute existing meetings for projects that have a project_lead_id or owner_id
        $projects = Project::whereNotNull('project_lead_id')->orWhereNotNull('owner_id')->get();
        foreach ($projects as $project) {
            $project->reattributeMeetingsToLead();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data migration only, no schema change
    }
};
