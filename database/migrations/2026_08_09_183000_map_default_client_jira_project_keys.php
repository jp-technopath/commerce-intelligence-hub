<?php

use App\Models\Client;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $internalClient = Client::firstOrCreate(
            ['name' => 'Technopath Internal'],
            ['jira_project_key' => 'TEC']
        );

        // Map every PmProject to a specific Client
        $projects = PmProject::all();
        foreach ($projects as $p) {
            $key = $p->external_project_key;
            $name = $p->name ?? $key;

            $client = Client::where('jira_project_key', $key)->first();

            if (! $client) {
                if (in_array($key, ['SUP', 'TEC', 'TM', 'TEWE', 'TWC', 'TP', 'SAN', 'SST', 'HPT', 'WPOC'], true)) {
                    $client = $internalClient;
                } else {
                    $client = Client::firstOrCreate(
                        ['name' => $name],
                        ['jira_project_key' => $key]
                    );
                }
            }

            $p->update(['client_id' => $client->id]);
        }

        // Re-assign PmWorkItems based on project client_id or key
        $items = PmWorkItem::with('project')->get();
        foreach ($items as $item) {
            $key = $item->external_item_key;
            $projKey = explode('-', $key)[0] ?? '';

            $client = Client::where('jira_project_key', $projKey)->first();

            if (! $client && $item->project) {
                $client = Client::where('jira_project_key', $item->project->external_project_key)->first()
                    ?? ($item->project->client_id ? Client::find($item->project->client_id) : null);
            }

            if ($client && $item->client_id !== $client->id) {
                $item->update(['client_id' => $client->id]);
            }
        }

        // Re-assign CustomerAttentionItems
        $attentionItems = \App\Models\CustomerAttentionItem::all();
        foreach ($attentionItems as $attItem) {
            if (in_array($attItem->source_type, ['forge_estimate_version', 'jira'], true)) {
                $version = \App\Models\ForgeEstimateVersion::find($attItem->source_id);
                if ($version && $version->workItem && $attItem->client_id !== $version->workItem->client_id) {
                    $attItem->update(['client_id' => $version->workItem->client_id]);
                }
            }
        }
    }

    public function down(): void
    {
        // Migration cleanup if rolled back
    }
};
