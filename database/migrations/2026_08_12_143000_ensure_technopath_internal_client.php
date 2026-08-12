<?php

use App\Models\Client;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure Technopath Internal client exists
        $internalClient = Client::firstOrCreate(
            ['name' => 'Technopath Internal'],
            ['jira_project_key' => 'TEC']
        );

        if (empty($internalClient->jira_project_key)) {
            $internalClient->update(['jira_project_key' => 'TEC']);
        }

        $internalKeys = ['TEC', 'TM', 'TEWE', 'TWC', 'TP', 'SAN', 'SST', 'HPT', 'WPOC', 'SUP'];

        // 2. Re-assign internal PmProjects
        PmProject::whereIn('external_project_key', $internalKeys)->update(['client_id' => $internalClient->id]);

        // 3. Re-assign all PmWorkItems for internal project keys
        foreach ($internalKeys as $key) {
            PmWorkItem::where('external_item_key', 'LIKE', $key . '-%')->update(['client_id' => $internalClient->id]);
        }

        // 4. Re-assign PmWorklogs for internal work items
        $internalWorkItemIds = PmWorkItem::where('client_id', $internalClient->id)->pluck('id');
        PmWorklog::whereIn('pm_work_item_id', $internalWorkItemIds)->update(['client_id' => $internalClient->id]);

        // 5. Re-assign CustomerAttentionItems for internal work items
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
        // Reverse if rolled back
    }
};
