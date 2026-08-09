<?php

use App\Models\Client;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $knownClients = [
            'CMBR2'   => 'Cambro',
            'HCCAM'   => 'Cambro',
            'B2B'     => 'Cambro',
            'TRAN'    => 'Transpart',
            'USG'     => 'USG',
            'CREAT'   => 'Create Pharmacy',
            'G0'      => 'GoldKamp',
            'GKSSC'   => 'GoldKamp',
            'T0'      => 'Traffix',
            'SR'      => 'Sketchy Realtor',
            'SHEL'    => 'ShellProof',
            'W2AF'    => '2AF',
            'MAR'     => '2AF',
            'RPD'     => 'RPD Custom Pools',
            'GZL'     => 'GZLures',
            'GWD'     => 'Gun Website Demo',
            'LAB'     => 'LaborLaw',
            'LEIB'    => 'Leibelle',
            'C0'      => 'Create Vet',
            'MR'      => 'Martor',
            'MRT'     => 'Martor',
            'PE'      => 'PM Enhancement',
            'FP'      => 'Flat Projects',
            'MDP'     => 'My Discovery Project',
            'MG'      => 'MTN Gear',
        ];

        foreach ($knownClients as $key => $clientName) {
            $client = Client::firstOrCreate(
                ['name' => $clientName],
                ['jira_project_key' => $key]
            );
            if (empty($client->jira_project_key)) {
                $client->update(['jira_project_key' => $key]);
            }
            PmProject::where('external_project_key', $key)->update(['client_id' => $client->id]);
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
