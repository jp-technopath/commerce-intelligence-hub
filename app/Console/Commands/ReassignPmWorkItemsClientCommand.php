<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use Illuminate\Console\Command;

class ReassignPmWorkItemsClientCommand extends Command
{
    protected $signature = 'pm:reassign-clients';
    protected $description = 'Re-assign PM projects and work items to their correct client based on Jira project keys and organizations';

    public function handle(): int
    {
        $this->info('Starting PM Work Item Client Re-assignment...');

        // 1. Ensure default Client jira_project_key mapping
        $mappings = [
            'Cambro'          => 'CMBR2',
            'Transpart'       => 'TRAN',
            'USG'             => 'USG',
            'Create Pharmacy' => 'CREAT',
            'GoldKamp'        => 'G0',
        ];

        foreach ($mappings as $clientName => $key) {
            $client = Client::where('name', 'LIKE', "%{$clientName}%")->first();
            if ($client && empty($client->jira_project_key)) {
                $client->update(['jira_project_key' => $key]);
                $this->info("Updated {$client->name} jira_project_key -> {$key}");
            }
        }

        // 2. Re-assign PmProjects
        $projects = PmProject::all();
        $projectCount = 0;
        foreach ($projects as $p) {
            $clientBySpace = Client::where('jira_project_key', $p->external_project_key)->first();
            if ($clientBySpace) {
                $p->update(['client_id' => $clientBySpace->id]);
                $projectCount++;
            }
        }
        $this->info("Re-assigned {$projectCount} PM projects to specific clients.");

        // 3. Re-assign PmWorkItems
        $items = PmWorkItem::with('project')->get();
        $itemCount = 0;

        foreach ($items as $item) {
            $key = $item->external_item_key;
            $projKey = explode('-', $key)[0] ?? '';

            $client = Client::where('jira_project_key', $projKey)->first();

            if (! $client && $item->project) {
                $client = Client::where('jira_project_key', $item->project->external_project_key)->first();
            }

            if ($client && $item->client_id !== $client->id) {
                $item->update(['client_id' => $client->id]);
                $itemCount++;
            }
        }

        $this->info("Re-assigned {$itemCount} PM work items to their correct clients.");

        // 4. Re-assign CustomerAttentionItems
        $attentionItems = \App\Models\CustomerAttentionItem::all();
        $attCount = 0;
        foreach ($attentionItems as $attItem) {
            if (in_array($attItem->source_type, ['forge_estimate_version', 'jira'], true)) {
                $version = \App\Models\ForgeEstimateVersion::find($attItem->source_id);
                if ($version && $version->workItem && $attItem->client_id !== $version->workItem->client_id) {
                    $attItem->update(['client_id' => $version->workItem->client_id]);
                    $attCount++;
                }
            }
        }
        $this->info("Re-assigned {$attCount} Customer Attention Items to their correct clients.");

        return Command::SUCCESS;
    }
}
