<?php

namespace App\Console\Commands;

use App\Models\ConnectedAccount;
use App\Models\PmWorkItem;
use App\Models\User;
use App\Services\PM\IdentityResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncJiraUserIdentitiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jira:sync-identities {--dry-run : Only show what would be updated without saving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discovers Jira workspace users from Jira API, links ConnectedAccount identities, and updates PmWorkItems';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $this->info($isDryRun ? '--- DRY RUN: Syncing Jira User Identities ---' : '--- Syncing Jira User Identities ---');

        $baseUrl = config('meeting_agent.jira.base_url');
        $email = config('meeting_agent.jira.email');
        $token = config('meeting_agent.jira.api_token');

        $jiraUsers = [];

        if ($baseUrl && $email && $token) {
            $this->line("Fetching users from Jira Cloud ({$baseUrl})...");
            try {
                $response = Http::withBasicAuth($email, $token)
                    ->timeout(30)
                    ->get(rtrim($baseUrl, '/') . '/rest/api/3/users/search?maxResults=100');

                if ($response->successful()) {
                    $jiraUsers = $response->json();
                    $this->info("Successfully fetched " . count($jiraUsers) . " users from Jira.");
                } else {
                    $this->warn("Jira API user search returned status: " . $response->status());
                }
            } catch (\Throwable $e) {
                $this->warn("Failed to query Jira API: " . $e->getMessage());
            }
        }

        // Map discovered Jira users to Forge Users
        $matchedMap = []; // userId => ['name' => ..., 'accountId' => ..., 'email' => ...]
        foreach ($jiraUsers as $jUser) {
            $accountId = $jUser['accountId'] ?? null;
            $displayName = $jUser['displayName'] ?? null;
            $emailAddress = $jUser['emailAddress'] ?? null;
            $accountType = $jUser['accountType'] ?? 'atlassian';

            // Skip Jira bot/app accounts
            if ($accountType === 'app' || in_array($displayName, ['Slack', 'Trello', 'Automation for Jira', 'System', 'Jira Outlook'])) {
                continue;
            }

            $userId = IdentityResolverService::resolveUserId('jira', $accountId, $emailAddress, $displayName);
            if ($userId) {
                if (! isset($matchedMap[$userId])) {
                    $matchedMap[$userId] = [
                        'user'        => User::find($userId),
                        'account_id'  => $accountId,
                        'name'        => $displayName,
                        'email'       => $emailAddress,
                    ];
                }
            }
        }

        // Ensure default known mappings are also present in matchedMap
        $knownMappings = IdentityResolverService::getJiraAccountMappings();
        foreach ($knownMappings as $accId => $uId) {
            if (! isset($matchedMap[$uId])) {
                $matchedMap[$uId] = [
                    'user'       => User::find($uId),
                    'account_id' => $accId,
                    'name'       => User::find($uId)?->name,
                    'email'      => User::find($uId)?->email,
                ];
            }
        }

        $this->table(
            ['User ID', 'Forge User', 'Jira Display Name', 'Jira Account ID', 'Jira Email'],
            collect($matchedMap)->map(function ($item, $userId) {
                return [
                    $userId,
                    $item['user']?->name ?? 'Unknown',
                    $item['name'] ?? 'N/A',
                    $item['account_id'] ?? 'N/A',
                    $item['email'] ?? 'N/A',
                ];
            })
        );

        if (! $isDryRun) {
            // 1. Update connected_accounts table with external_account_id
            foreach ($matchedMap as $userId => $item) {
                $existing = DB::table('connected_accounts')
                    ->where('user_id', $userId)
                    ->where('provider', 'jira')
                    ->first();

                if ($existing) {
                    DB::table('connected_accounts')
                        ->where('id', $existing->id)
                        ->update([
                            'external_account_id' => $item['account_id'],
                            'status'              => 'active',
                            'updated_at'          => now(),
                        ]);
                    $this->line("Updated ConnectedAccount for User {$userId} ({$item['user']?->name}) with AccountID: {$item['account_id']}");
                } else {
                    DB::table('connected_accounts')->insert([
                        'user_id'             => $userId,
                        'provider'            => 'jira',
                        'external_account_id' => $item['account_id'],
                        'authorized_email'    => $item['email'] ?? ($item['user']?->email ?? $baseUrl),
                        'status'              => 'active',
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);
                    $this->line("Created ConnectedAccount for User {$userId} ({$item['user']?->name}) with AccountID: {$item['account_id']}");
                }
            }

            // 2. Update pm_work_items by external_assignee_id and assignee_name
            $this->info("Attributing pm_work_items to Forge users...");
            $results = [];

            foreach ($matchedMap as $userId => $item) {
                $accId = $item['account_id'];
                $name = $item['name'];
                $forgeUserName = $item['user']?->name;

                $count = PmWorkItem::where(function ($q) use ($accId, $name, $forgeUserName) {
                    if ($accId) {
                        $q->where('external_assignee_id', $accId);
                    }
                    if ($name) {
                        $q->orWhere('assignee_name', $name)
                          ->orWhere('assignee_name', 'like', "%{$name}%");
                    }
                    if ($forgeUserName) {
                        $q->orWhere('assignee_name', $forgeUserName)
                          ->orWhere('assignee_name', 'like', "%{$forgeUserName}%");
                    }
                })->update(['user_id' => $userId]);

                $activeCount = PmWorkItem::where('user_id', $userId)
                    ->where('normalized_delivery_status', '!=', 'completed')
                    ->count();

                $results[] = [
                    'User'         => "{$forgeUserName} (ID {$userId})",
                    'Total Linked' => $count,
                    'Active Tasks' => $activeCount,
                ];
            }

            $this->table(['User', 'Total Linked Tickets', 'Active (Non-Done) Tasks'], $results);

            // Also check unassigned items remaining
            $unassignedActive = PmWorkItem::whereNull('user_id')
                ->where('normalized_delivery_status', '!=', 'completed')
                ->count();
            $this->line("Remaining unassigned active items: {$unassignedActive}");
        }

        $this->info("Sync completed successfully!");
        return Command::SUCCESS;
    }
}
