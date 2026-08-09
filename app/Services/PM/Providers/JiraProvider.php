<?php

namespace App\Services\PM\Providers;

use App\Enums\ConnectedAccountStatus;
use App\Models\Client;
use App\Models\ConnectedAccount;
use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\PmWorklog;
use App\Models\User;
use App\Services\PM\Exceptions\PmSyncCredentialsException;
use App\Services\PM\Exceptions\UserJiraAccountNotConnectedException;
use App\Services\PM\ProjectManagementProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JiraProvider implements ProjectManagementProvider
{
    /**
     * Resolve credentials based on whether an explicit human actor is passed.
     */
    public function resolveCredentials(?User $actor, PmConnection $connection, bool $throwOnMissing = false): ConnectedAccount
    {
        if ($actor !== null) {
            try {
                $userAccount = $actor->jiraAccount();
                if ($userAccount && $userAccount->status === ConnectedAccountStatus::Active && $userAccount->credentials_json) {
                    return $userAccount;
                }
            } catch (\Throwable $e) {
                Log::info("JiraProvider: Actor credentials error: " . $e->getMessage());
            }

            if ($throwOnMissing) {
                throw new UserJiraAccountNotConnectedException(
                    "User {$actor->name} must connect their Jira account under Profile -> Connected Accounts to perform this action."
                );
            }

            Log::info("JiraProvider: Actor {$actor->name} has no connected Jira OAuth account, falling back to system integration credentials.");
        }

        // Background / system operation fallback
        if ($connection->default_sync_user_id && $connection->defaultSyncUser) {
            try {
                $syncAccount = $connection->defaultSyncUser->jiraAccount();
                if ($syncAccount && $syncAccount->status === ConnectedAccountStatus::Active && $syncAccount->credentials_json) {
                    return $syncAccount;
                }
            } catch (\Throwable $e) {
                Log::info("JiraProvider: Sync user Jira credentials unreadable: " . $e->getMessage());
            }
        }

        return new ConnectedAccount([
            'provider' => 'jira',
            'status'   => ConnectedAccountStatus::Active,
        ]);
    }

    /**
     * Build authorized HTTP request for Jira API calls.
     */
    protected function buildRequest(ConnectedAccount $account)
    {
        try {
            if ($account->exists && $account->credentials_json) {
                $accessToken = $account->refreshJiraTokenIfNeeded();
                if ($accessToken) {
                    return Http::timeout(30)->withToken($accessToken);
                }
            }
        } catch (\Throwable $e) {
            Log::info("JiraProvider: OAuth token decrypt/refresh unavailable, falling back to configured Jira API token: " . $e->getMessage());
        }

        // Fallback to basic auth if configured globally (for legacy/testing/env API token)
        $baseUrl = config('meeting_agent.jira.base_url');
        $email = config('meeting_agent.jira.email');
        $token = config('meeting_agent.jira.api_token');

        if ($baseUrl && $email && $token) {
            return Http::timeout(30)->withBasicAuth($email, $token);
        }

        throw new PmSyncCredentialsException('Jira OAuth token and basic auth configuration are unavailable.');
    }

    /**
     * Get Cloud ID for Jira OAuth endpoint URLs.
     */
    protected function getCloudId(ConnectedAccount $account, PmConnection $connection): string
    {
        return $account->getCredential('cloud_id')
            ?? $connection->external_workspace_id
            ?? config('meeting_agent.jira.cloud_id', '');
    }

    // ─────────────────────────────────────────────────────────────────────
    // ProjectManagementProvider Implementation
    // ─────────────────────────────────────────────────────────────────────

    public function syncProjects(PmConnection $connection): array
    {
        $account = $this->resolveCredentials(null, $connection);
        $cloudId = $this->getCloudId($account, $connection);

        $isOAuth = $account->exists && $account->credentials_json;
        $url = ($isOAuth && $cloudId)
            ? "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/project"
            : rtrim(config('meeting_agent.jira.base_url', ''), '/') . '/rest/api/3/project';

        $response = $this->buildRequest($account)->get($url);

        if (! $response->successful()) {
            Log::error('JiraProvider: syncProjects failed', ['status' => $response->status(), 'body' => $response->body()]);
            return [];
        }

        $projects = $response->json();
        $synced = [];

        foreach ($projects as $proj) {
            $projKey = $proj['key'] ?? '';
            $projName = $proj['name'] ?? $projKey;

            // 1. Resolve target client by matching jira_project_key on Client model
            $matchedClient = Client::where('jira_project_key', $projKey)->first();

            // 2. Fallback: match by client name
            if (! $matchedClient) {
                $matchedClient = Client::where('name', 'LIKE', "%{$projName}%")->first();
            }

            // 3. Auto-provision Client record for client-specific projects if missing
            if (! $matchedClient && ! in_array($projKey, ['SUP', 'TEC', 'TM', 'TEWE', 'TWC', 'TP', 'SAN', 'SST', 'HPT'], true)) {
                $matchedClient = Client::firstOrCreate(
                    ['name' => $projName],
                    ['jira_project_key' => $projKey]
                );
            }

            $pmProject = PmProject::updateOrCreate(
                [
                    'pm_connection_id'     => $connection->id,
                    'external_project_key' => $projKey,
                ],
                [
                    'client_id'           => $matchedClient ? $matchedClient->id : null,
                    'name'                => $projName,
                    'external_project_id' => $proj['id'] ?? null,
                    'is_active'           => true,
                ]
            );
            $synced[] = $pmProject;
        }

        $connection->update(['last_synced_at' => now()]);

        return $synced;
    }

    public function syncWorkItems(PmProject $project): array
    {
        $connection = $project->connection;
        $account = $this->resolveCredentials(null, $connection);
        $cloudId = $this->getCloudId($account, $connection);

        $jql = $project->custom_filter_jql ?: "project = '{$project->external_project_key}' AND status != 'Backlog' AND status != 'backlog' AND updated >= -365d ORDER BY updated DESC";

        $isOAuth = $account->exists && $account->credentials_json;
        $url = ($isOAuth && $cloudId)
            ? "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/search/jql"
            : rtrim(config('meeting_agent.jira.base_url', ''), '/') . '/rest/api/3/search/jql';

        $body = [
            'jql'        => $jql,
            'maxResults' => 100,
            'fields'     => ['summary', 'description', 'status', 'issuetype', 'priority', 'timetracking', 'assignee', 'duedate', 'updated', 'customfield_10002', 'customfield_10028', 'reporter'],
        ];

        $response = $this->buildRequest($account)->post($url, $body);

        if (! $response->successful()) {
            Log::error('JiraProvider: syncWorkItems failed', ['status' => $response->status(), 'jql' => $jql]);
            return [];
        }

        $issues = $response->json()['issues'] ?? [];
        $syncedItems = [];

        foreach ($issues as $issue) {
            $item = $this->normalizeAndSaveWorkItem($issue, $project, $connection);
            if ($item !== null) {
                $syncedItems[] = $item;
            }
        }

        $connection->update(['last_synced_at' => now()]);

        return $syncedItems;
    }

    public function syncWorklogs(PmWorkItem $workItem): array
    {
        $connection = $workItem->connection;
        $account = $this->resolveCredentials(null, $connection);
        $cloudId = $this->getCloudId($account, $connection);

        $isOAuth = $account->exists && $account->credentials_json;
        $url = ($isOAuth && $cloudId)
            ? "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/issue/{$workItem->external_item_id}/worklog"
            : rtrim(config('meeting_agent.jira.base_url', ''), '/') . "/rest/api/3/issue/{$workItem->external_item_id}/worklog";

        $response = $this->buildRequest($account)->get($url);

        if (! $response->successful()) {
            Log::error('JiraProvider: syncWorklogs failed', ['status' => $response->status(), 'item_key' => $workItem->external_item_key]);
            return [];
        }

        $worklogs = $response->json()['worklogs'] ?? [];
        $syncedLogs = [];

        foreach ($worklogs as $wl) {
            $worklogModel = PmWorklog::updateOrCreate(
                [
                    'external_worklog_id' => (string) $wl['id'],
                ],
                [
                    'pm_connection_id'    => $connection->id,
                    'client_id'           => $workItem->client_id,
                    'pm_work_item_id'     => $workItem->id,
                    'author_name'         => $wl['author']['displayName'] ?? 'Unknown',
                    'time_spent_seconds'  => (int) ($wl['timeSpentSeconds'] ?? 0),
                    'worklog_started_at'  => isset($wl['started']) ? Carbon::parse($wl['started']) : now(),
                    'external_created_at' => isset($wl['created']) ? Carbon::parse($wl['created']) : null,
                    'external_updated_at' => isset($wl['updated']) ? Carbon::parse($wl['updated']) : null,
                    'last_synced_at'      => now(),
                ]
            );
            $syncedLogs[] = $worklogModel;
        }

        return $syncedLogs;
    }

    public function getWorkItem(PmConnection $connection, string $externalItemId, ?User $actor = null): array
    {
        $account = $this->resolveCredentials($actor, $connection);
        $cloudId = $this->getCloudId($account, $connection);

        $url = $cloudId
            ? "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/issue/{$externalItemId}"
            : rtrim(config('meeting_agent.jira.base_url', ''), '/') . "/rest/api/3/issue/{$externalItemId}";

        $response = $this->buildRequest($account)->get($url);

        if (! $response->successful()) {
            return [];
        }

        return $response->json();
    }

    public function addComment(PmWorkItem $workItem, string $comment, ?User $actor = null): bool
    {
        $connection = $workItem->connection;
        $account = $this->resolveCredentials($actor, $connection);

        $hasOAuthToken = $account->exists && ! empty($account->credentials_json['access_token']);
        $cloudId = $hasOAuthToken ? $this->getCloudId($account, $connection) : null;

        $url = ($hasOAuthToken && $cloudId)
            ? "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/issue/{$workItem->external_item_key}/comment"
            : rtrim(config('meeting_agent.jira.base_url', ''), '/') . "/rest/api/3/issue/{$workItem->external_item_key}/comment";

        $body = [
            'body' => [
                'type'    => 'doc',
                'version' => 1,
                'content' => [
                    [
                        'type'    => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $comment,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->buildRequest($account)->post($url, $body);

        if (! $response->successful()) {
            Log::error("JiraProvider addComment failed [Status {$response->status()}]: " . $response->body());
        }

        return $response->successful();
    }

    public function updatePriority(PmWorkItem $workItem, string $newPriority, ?User $actor = null): bool
    {
        $connection = $workItem->connection;
        $account = $this->resolveCredentials($actor, $connection);

        $hasOAuthToken = $account->exists && ! empty($account->credentials_json['access_token']);
        $cloudId = $hasOAuthToken ? $this->getCloudId($account, $connection) : null;

        $url = ($hasOAuthToken && $cloudId)
            ? "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/issue/{$workItem->external_item_key}"
            : rtrim(config('meeting_agent.jira.base_url', ''), '/') . "/rest/api/3/issue/{$workItem->external_item_key}";

        $body = [
            'fields' => [
                'priority' => [
                    'name' => $newPriority,
                ],
            ],
        ];

        $response = $this->buildRequest($account)->put($url, $body);

        if (! $response->successful()) {
            Log::error("JiraProvider updatePriority failed [Status {$response->status()}]: " . $response->body());
            return false;
        }

        $workItem->update(['priority' => $newPriority]);

        return true;
    }

    public function transitionWorkItem(PmWorkItem $workItem, string $semanticAction, ?User $actor = null): bool
    {
        $connection = $workItem->connection;
        $account = $this->resolveCredentials($actor, $connection);

        $hasOAuthToken = $account->exists && ! empty($account->credentials_json['access_token']);
        $cloudId = $hasOAuthToken ? $this->getCloudId($account, $connection) : null;

        $urlTransitions = ($hasOAuthToken && $cloudId)
            ? "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/issue/{$workItem->external_item_key}/transitions"
            : rtrim(config('meeting_agent.jira.base_url', ''), '/') . "/rest/api/3/issue/{$workItem->external_item_key}/transitions";

        $response = $this->buildRequest($account)->get($urlTransitions);

        if (! $response->successful()) {
            return false;
        }

        $availableTransitions = $response->json()['transitions'] ?? [];
        $mappings = config("meeting_agent.semantic_transitions.{$semanticAction}", ['Ready for Development', 'In Progress', 'In Review', 'Done']);

        $selectedTransitionId = null;

        foreach ($availableTransitions as $trans) {
            $transName = $trans['name'] ?? '';
            foreach ((array) $mappings as $candidateName) {
                if (strcasecmp($transName, $candidateName) === 0) {
                    $selectedTransitionId = $trans['id'];
                    break 2;
                }
            }
        }

        if (! $selectedTransitionId) {
            Log::info("JiraProvider: No matching transition found for semantic action '{$semanticAction}' on issue {$workItem->external_item_key}");
            return false;
        }

        // 2. Execute selected transition
        $responseExec = $this->buildRequest($account)->post($urlTransitions, [
            'transition' => ['id' => $selectedTransitionId],
        ]);

        return $responseExec->successful();
    }

    public function updateEstimate(PmWorkItem $workItem, int $estimatedSeconds, ?User $actor = null): bool
    {
        $connection = $workItem->connection;
        $account = $this->resolveCredentials($actor, $connection);
        $cloudId = $this->getCloudId($account, $connection);

        $url = $cloudId
            ? "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3/issue/{$workItem->external_item_id}"
            : rtrim(config('meeting_agent.jira.base_url', ''), '/') . "/rest/api/3/issue/{$workItem->external_item_id}";

        $body = [
            'fields' => [
                'timetracking' => [
                    'originalEstimate' => round($estimatedSeconds / 3600, 1) . 'h',
                ],
            ],
        ];

        $response = $this->buildRequest($account)->put($url, $body);

        if ($response->successful()) {
            $workItem->update(['estimated_seconds' => $estimatedSeconds]);
            return true;
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helper Methods
    // ─────────────────────────────────────────────────────────────────────

    public static function parseAdfToMarkdown(mixed $node): ?string
    {
        if (empty($node)) {
            return null;
        }

        if (is_string($node)) {
            return $node;
        }

        if (! is_array($node)) {
            return null;
        }

        $type = $node['type'] ?? '';

        if ($type === 'text') {
            $text = $node['text'] ?? '';
            $marks = $node['marks'] ?? [];
            foreach ($marks as $mark) {
                $mType = $mark['type'] ?? '';
                if ($mType === 'strong' || $mType === 'bold') {
                    $text = "**{$text}**";
                } elseif ($mType === 'em' || $mType === 'italic') {
                    $text = "*{$text}*";
                } elseif ($mType === 'code') {
                    $text = "`{$text}`";
                } elseif ($mType === 'link' && ! empty($mark['attrs']['href'])) {
                    $text = "[{$text}](" . $mark['attrs']['href'] . ")";
                }
            }
            return $text;
        }

        $children = [];
        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                $children[] = self::parseAdfToMarkdown($child);
            }
        }

        $inner = implode('', array_filter($children));

        $result = match ($type) {
            'doc'         => implode("\n\n", array_filter($children)),
            'paragraph'   => $inner . "\n\n",
            'heading'     => "\n\n### " . $inner . "\n\n",
            'bulletList'  => implode("\n", array_map(fn ($item) => "• " . trim($item), array_filter($children))) . "\n\n",
            'orderedList' => implode("\n", array_map(fn ($item, $i) => ($i + 1) . ". " . trim($item), array_filter($children), array_keys(array_filter($children)))) . "\n\n",
            'listItem'    => implode(' ', array_filter(array_map('trim', $children))),
            'hardBreak'   => "\n",
            'codeBlock'   => "```\n" . $inner . "\n```\n\n",
            'blockquote'  => "> " . $inner . "\n\n",
            default       => $inner,
        };

        if ($type === 'doc' && is_string($result)) {
            $result = preg_replace_callback('/^(?:\*\*|\*|#+\s*)?([A-Z][A-Za-z0-9\s\&\/\\()-]{2,40})(?:\*\*|\*)?:?$/m', function ($matches) {
                $title = trim($matches[1]);
                if (in_array(strtolower($title), ['the', 'a', 'an', 'and', 'or', 'in', 'on', 'at', 'to', 'for', 'with', 'platform', 'account'])) {
                    return $matches[0];
                }
                return "\n\n### " . $title . "\n\n";
            }, $result);
        }

        return $result;
    }

    public function normalizeAndSaveWorkItem(array $issue, PmProject $project, PmConnection $connection): ?PmWorkItem
    {
        $fields = $issue['fields'] ?? [];
        $jiraStatus = $fields['status']['name'] ?? 'To Do';

        if (strcasecmp($jiraStatus, 'backlog') === 0 || str_contains(strtolower($jiraStatus), 'backlog')) {
            PmWorkItem::where('external_item_id', (string) $issue['id'])
                ->delete();
            return null;
        }

        $normalizedStatus = $this->mapJiraStatusToForge($jiraStatus, $connection);

        $originalEstimateSeconds = (int) ($fields['timetracking']['originalEstimateSeconds'] ?? 0);
        $timeSpentSeconds = (int) ($fields['timetracking']['timeSpentSeconds'] ?? 0);

        $targetClientId = $this->resolveClientIdFromIssue($issue, $connection, $project);

        return PmWorkItem::updateOrCreate(
            [
                'external_item_id' => (string) $issue['id'],
            ],
            [
                'pm_connection_id'           => $connection->id,
                'client_id'                  => $targetClientId,
                'pm_project_id'              => $project->id,
                'external_item_key'          => $issue['key'] ?? '',
                'summary'                    => $fields['summary'] ?? '',
                'description'                => self::parseAdfToMarkdown($fields['description'] ?? null),
                'item_type'                  => $fields['issuetype']['name'] ?? 'Task',
                'priority'                   => $fields['priority']['name'] ?? 'Medium',
                'external_status'            => $jiraStatus,
                'normalized_delivery_status' => $normalizedStatus,
                'estimated_seconds'          => $originalEstimateSeconds,
                'time_spent_seconds'         => $timeSpentSeconds,
                'assignee_name'              => $fields['assignee']['displayName'] ?? null,
                'target_due_date'            => isset($fields['duedate']) ? Carbon::parse($fields['duedate']) : null,
                'external_updated_at'        => isset($fields['updated']) ? Carbon::parse($fields['updated']) : null,
                'last_synced_at'             => now(),
            ]
        );
    }

    public function resolveClientIdFromIssue(array $issue, PmConnection $connection, PmProject $project): int
    {
        $fields = $issue['fields'] ?? [];

        // 1. For Service Desk SUP project: match Jira Organization / reporter email domain
        if ($project->external_project_key === 'SUP') {
            $orgs = $fields['customfield_10002'] ?? null;
            if (is_array($orgs) && ! empty($orgs)) {
                $orgName = $orgs[0]['name'] ?? null;
                if ($orgName) {
                    $client = Client::where('name', 'LIKE', '%' . trim($orgName) . '%')->first();
                    if ($client) {
                        return $client->id;
                    }
                }
            }

            if (isset($fields['customfield_10028']['value'])) {
                $orgName = $fields['customfield_10028']['value'];
                $client = Client::where('name', 'LIKE', '%' . trim($orgName) . '%')->first();
                if ($client) {
                    return $client->id;
                }
            }

            $reporterEmail = $fields['reporter']['emailAddress'] ?? null;
            if ($reporterEmail && str_contains($reporterEmail, '@')) {
                $domain = strtolower(substr(strrchr($reporterEmail, '@'), 1));
                if ($domain && ! in_array($domain, ['technopath.co', 'technopath.com.au', 'gmail.com'], true)) {
                    $domainKeyword = explode('.', $domain)[0];
                    if (strlen($domainKeyword) >= 3) {
                        $client = Client::where('name', 'LIKE', '%' . $domainKeyword . '%')->first();
                        if ($client) {
                            return $client->id;
                        }
                    }
                }
            }
        }

        // 2. Direct Jira project key match on Client model
        $clientBySpace = Client::where('jira_project_key', $project->external_project_key)->first();
        if ($clientBySpace) {
            return $clientBySpace->id;
        }

        // 3. Explicit project-assigned client_id if present
        if ($project->client_id) {
            return $project->client_id;
        }

        // 4. Heuristic name match on Client name vs Project key/name
        $keyUpper = strtoupper($project->external_project_key);
        $clientByName = Client::all()->first(function ($c) use ($keyUpper, $project) {
            $cNameUpper = strtoupper($c->name);
            return str_contains($cNameUpper, $keyUpper) 
                || str_contains($keyUpper, $cNameUpper) 
                || str_contains(strtoupper($project->name), $cNameUpper);
        });

        if ($clientByName) {
            return $clientByName->id;
        }

        return $connection->client_id;
    }

    public function mapJiraStatusToForge(string $jiraStatus, PmConnection $connection): string
    {
        $customMappings = $connection->status_mappings_json ?? [];

        if (isset($customMappings[strtolower($jiraStatus)])) {
            return $customMappings[strtolower($jiraStatus)];
        }

        $jiraStatusLower = strtolower($jiraStatus);

        return match (true) {
            str_contains($jiraStatusLower, 'done') || str_contains($jiraStatusLower, 'closed') || str_contains($jiraStatusLower, 'resolved') => 'completed',
            str_contains($jiraStatusLower, 'uat') || str_contains($jiraStatusLower, 'customer') => 'customer_review',
            str_contains($jiraStatusLower, 'review') || str_contains($jiraStatusLower, 'qa') || str_contains($jiraStatusLower, 'testing') => 'review_qa',
            str_contains($jiraStatusLower, 'progress') || str_contains($jiraStatusLower, 'dev') => 'in_progress',
            str_contains($jiraStatusLower, 'ready') || str_contains($jiraStatusLower, 'to do') => 'ready',
            default => 'planned',
        };
    }

    public function fetchCustomerNoteForWorkItem(PmWorkItem $workItem): ?string
    {
        try {
            $email = config('meeting_agent.jira.email');
            $token = config('meeting_agent.jira.api_token');
            $baseUrl = config('meeting_agent.jira.base_url');

            $url = rtrim($baseUrl, '/') . "/rest/api/3/issue/{$workItem->external_item_key}?expand=renderedFields,comments";
            $response = Http::withBasicAuth($email, $token)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $issue = $response->json();
            $renderedComments = $issue['renderedFields']['comment']['comments'] ?? [];

            foreach ($renderedComments as $c) {
                $html = $c['body'] ?? '';
                $decodedHtml = html_entity_decode($html);

                if (str_contains(strtolower($decodedHtml), 'customer note')) {
                    // 1. Remove author profile link paragraph at start
                    $cleaned = preg_replace('/<p>\s*<a[^>]*>[^<]*<\/a>\s*<span[^>]*>&#91;Customer Note&#93;<\/span>\s*<\/p>/i', '', $html);
                    $cleaned = preg_replace('/<p>\s*<a[^>]*>[^<]*<\/a>\s*\[Customer Note\]\s*<\/p>/i', '', $cleaned);
                    $cleaned = preg_replace('/\[customer note\]/i', '', $cleaned);

                    // 2. Remove empty anchors in headings
                    $cleaned = preg_replace('/<a name="[^"]*"><\/a>/i', '', $cleaned);

                    // 3. Format Confluence tables into modern styled tables
                    $cleaned = str_replace(
                        "<table class='confluenceTable'>",
                        "<table class='w-full text-sm text-left border-collapse border border-gray-200 dark:border-gray-800 rounded-lg overflow-hidden my-4 shadow-sm'>",
                        $cleaned
                    );
                    $cleaned = str_replace(
                        "<th class='confluenceTh'>",
                        "<th class='bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100 font-semibold px-4 py-2.5 border-b border-gray-200 dark:border-gray-700 text-xs uppercase tracking-wider'>",
                        $cleaned
                    );
                    $cleaned = str_replace(
                        "<td class='confluenceTd'>",
                        "<td class='px-4 py-2.5 border-b border-gray-100 dark:border-gray-800 text-gray-700 dark:text-gray-300'>",
                        $cleaned
                    );

                    // 4. Style lists, headings, and paragraphs cleanly
                    $cleaned = str_replace('<ul>', "<ul class='list-disc list-inside space-y-1 my-3 text-gray-700 dark:text-gray-300'>", $cleaned);
                    $cleaned = str_replace('<h2>', "<h2 class='text-base font-bold text-primary-600 dark:text-primary-400 mt-6 mb-2 pb-1 border-b border-gray-200 dark:border-gray-800'>", $cleaned);
                    $cleaned = str_replace('<p>', "<p class='mb-3 text-gray-700 dark:text-gray-300 leading-relaxed'>", $cleaned);

                    return '<div class="estimate-breakdown-wrapper space-y-3 font-sans text-sm">' . trim($cleaned) . '</div>';
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to fetch customer note for {$workItem->external_item_key}: " . $e->getMessage());
        }

        return null;
    }
}
