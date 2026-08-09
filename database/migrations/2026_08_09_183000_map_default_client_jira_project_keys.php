<?php

use App\Models\Client;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $mappings = [
            'Cambro'          => 'CMBR2',
            'Transpart'       => 'TRAN',
            'USG'             => 'USG',
            'Create Pharmacy' => 'CREAT',
            'GoldKamp'        => 'G0',
        ];

        foreach ($mappings as $clientName => $key) {
            $client = Client::where('name', 'LIKE', "%{$clientName}%")->first();
            if ($client) {
                $client->update(['jira_project_key' => $key]);
            }
        }

        // Re-assign PmProjects
        $projects = PmProject::all();
        foreach ($projects as $p) {
            $clientBySpace = Client::where('jira_project_key', $p->external_project_key)->first();
            if ($clientBySpace) {
                $p->update(['client_id' => $clientBySpace->id]);
            }
        }

        // Re-assign PmWorkItems
        $items = PmWorkItem::with('project')->get();
        foreach ($items as $item) {
            $key = $item->external_item_key;
            $projKey = explode('-', $key)[0] ?? '';

            $client = Client::where('jira_project_key', $projKey)->first();

            if (! $client && $item->project) {
                $client = Client::where('jira_project_key', $item->project->external_project_key)->first();
            }

            if ($client && $item->client_id !== $client->id) {
                $item->update(['client_id' => $client->id]);
            }
        }
    }

    public function down(): void
    {
        // Migration cleanup if rolled back
    }
};
