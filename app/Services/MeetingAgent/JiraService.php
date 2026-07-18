<?php

namespace App\Services\MeetingAgent;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Jira Cloud REST API service for the Meeting Agent module.
 *
 * Uses HTTP basic auth (email + API token) to query project status
 * and build meeting-ready snapshots of issue progress.
 */
class JiraService
{
    private ?string $baseUrl = null;
    private ?string $email = null;
    private ?string $token = null;

    private ?string $accessToken = null;
    private ?string $cloudId = null;
    private bool $isOAuth = false;

    public function __construct(
        ?string $baseUrl = null,
        ?string $email = null,
        ?string $token = null,
        ?string $accessToken = null,
        ?string $cloudId = null
    ) {
        if ($accessToken && $cloudId) {
            $this->accessToken = $accessToken;
            $this->cloudId = $cloudId;
            $this->isOAuth = true;
        } else {
            $this->baseUrl = rtrim($baseUrl ?? config('meeting_agent.jira.base_url', ''), '/');
            $this->email = $email ?? config('meeting_agent.jira.email', '');
            $this->token = $token ?? config('meeting_agent.jira.api_token', '');

            if (empty($this->baseUrl)) {
                throw new RuntimeException('Jira is not configured. Set JIRA_BASE_URL in your environment.');
            }
        }
    }

    /**
     * Execute a JQL search and return the raw issues array.
     */
    public function searchIssues(string $jql, int $maxResults = 50, ?string $nextPageToken = null): array
    {
        $body = [
            'jql'        => $jql,
            'maxResults' => $maxResults,
            'fields'     => ['summary', 'status', 'priority', 'assignee', 'updated'],
        ];

        if ($nextPageToken) {
            $body['nextPageToken'] = $nextPageToken;
        }

        $url = $this->isOAuth
            ? "https://api.atlassian.com/ex/jira/{$this->cloudId}/rest/api/3/search/jql"
            : $this->baseUrl . '/rest/api/3/search/jql';

        $request = Http::timeout(30);

        if ($this->isOAuth) {
            $request = $request->withToken($this->accessToken);
        } else {
            $request = $request->withBasicAuth($this->email, $this->token);
        }

        $response = $request->post($url, $body);

        if (! $response->successful()) {
            Log::error('JiraService: search failed', [
                'status' => $response->status(),
                'jql'    => $jql,
                'body'   => substr($response->body(), 0, 500),
            ]);
            throw new RuntimeException('Jira API request failed with status ' . $response->status());
        }

        return $response->json();
    }

    /**
     * Build a comprehensive project status snapshot grouped by status categories.
     */
    public function getProjectStatusSnapshot(string $projectKey, ?Carbon $since = null): array
    {
        $since = $since ?? now()->subDays(14);
        $jql = $this->buildDefaultMeetingJql($projectKey, $since);

        // Paginate through all results using nextPageToken
        $allIssues = [];
        $nextPageToken = null;

        do {
            $result = $this->searchIssues($jql, 50, $nextPageToken);
            $issues = $result['issues'] ?? [];
            $isLast = $result['isLast'] ?? true;
            $nextPageToken = $result['nextPageToken'] ?? null;

            foreach ($issues as $issue) {
                $allIssues[] = $this->normalizeIssue($issue);
            }
        } while (! $isLast && $nextPageToken);

        return $this->groupIssues($allIssues, $since);
    }

    /**
     * Build the default JQL for meeting preparation.
     */
    public function buildDefaultMeetingJql(string $projectKey, ?Carbon $since = null): string
    {
        $since = $since ?? now()->subDays(14);
        $sinceDate = $since->format('Y-m-d');

        return "project = {$projectKey} AND updated >= '{$sinceDate}' ORDER BY updated DESC";
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Normalize a Jira issue into a consistent array format.
     */
    private function normalizeIssue(array $issue): array
    {
        $fields = $issue['fields'] ?? [];

        return [
            'key'      => $issue['key'] ?? '',
            'summary'  => $fields['summary'] ?? '',
            'status'   => $fields['status']['name'] ?? 'Unknown',
            'priority' => $fields['priority']['name'] ?? 'Medium',
            'assignee' => $fields['assignee']['displayName'] ?? 'Unassigned',
            'updated'  => $fields['updated'] ?? null,
        ];
    }

    /**
     * Group normalized issues by status category using config mappings.
     */
    private function groupIssues(array $issues, Carbon $since): array
    {
        $mappings = config('meeting_agent.jira.status_mappings', []);
        $sevenDaysAgo = now()->subDays(7);

        $groups = [
            'completed_since_last_meeting' => [],
            'in_progress'                  => [],
            'blocked_or_on_hold'           => [],
            'ready_for_review'             => [],
            'needs_customer_input'         => [],
            'high_priority'                => [],
            'recently_updated'             => [],
            'other'                        => [],
        ];

        // Build reverse lookup: lowercase status → group name
        $statusToGroup = [];
        $groupMap = [
            'completed'            => 'completed_since_last_meeting',
            'in_progress'          => 'in_progress',
            'blocked'              => 'blocked_or_on_hold',
            'ready_for_review'     => 'ready_for_review',
            'needs_customer_input' => 'needs_customer_input',
        ];

        foreach ($groupMap as $configKey => $groupName) {
            $statuses = $mappings[$configKey] ?? [];
            foreach ($statuses as $status) {
                $statusToGroup[strtolower($status)] = $groupName;
            }
        }

        foreach ($issues as $issue) {
            $statusLower = strtolower($issue['status']);
            $group = $statusToGroup[$statusLower] ?? 'other';

            $groups[$group][] = $issue;

            // High priority detection
            if (in_array($issue['priority'], ['Highest', 'High'], true)) {
                $groups['high_priority'][] = $issue;
            }

            // Recently updated detection (within last 7 days)
            if ($issue['updated'] && Carbon::parse($issue['updated'])->gte($sevenDaysAgo)) {
                $groups['recently_updated'][] = $issue;
            }
        }

        $groups['total_count'] = count($issues);
        $groups['snapshot_at'] = now()->toISOString();

        return $groups;
    }

    /**
     * Search for a Jira user by query (name or email) and return their accountId if found.
     */
    public function findUser(string $query): ?string
    {
        $url = $this->isOAuth
            ? "https://api.atlassian.com/ex/jira/{$this->cloudId}/rest/api/3/user/search"
            : $this->baseUrl . '/rest/api/3/user/search';

        $request = Http::timeout(30);

        if ($this->isOAuth) {
            $request = $request->withToken($this->accessToken);
        } else {
            $request = $request->withBasicAuth($this->email, $this->token);
        }

        $response = $request->get($url, ['query' => $query]);

        if ($response->successful()) {
            $users = $response->json();
            if (!empty($users) && isset($users[0]['accountId'])) {
                return $users[0]['accountId'];
            }
        }

        return null;
    }

    /**
     * Create a new issue in Jira.
     */
    public function createIssue(
        string $projectKey,
        string $summary,
        ?string $description = null,
        ?string $assigneeAccountId = null,
        string $issueType = 'Task'
    ): array {
        $url = $this->isOAuth
            ? "https://api.atlassian.com/ex/jira/{$this->cloudId}/rest/api/3/issue"
            : $this->baseUrl . '/rest/api/3/issue';

        $fields = [
            'project'   => ['key' => $projectKey],
            'summary'   => $summary,
            'issuetype' => ['name' => $issueType],
        ];

        if (!empty($description)) {
            $fields['description'] = [
                'type'    => 'doc',
                'version' => 1,
                'content' => [
                    [
                        'type'    => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $description,
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($assigneeAccountId) {
            $fields['assignee'] = ['accountId' => $assigneeAccountId];
        }

        $body = ['fields' => $fields];

        $request = Http::timeout(30);

        if ($this->isOAuth) {
            $request = $request->withToken($this->accessToken);
        } else {
            $request = $request->withBasicAuth($this->email, $this->token);
        }

        $response = $request->post($url, $body);

        if (! $response->successful()) {
            Log::error('JiraService: create issue failed', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);
            throw new RuntimeException('Jira API create issue failed with status ' . $response->status());
        }

        return $response->json();
    }
}
