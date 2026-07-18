<?php

namespace Tests\Unit\MeetingAgent;

use App\Services\MeetingAgent\JiraService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class JiraServiceTest extends TestCase
{
    private function fakeJiraConfig(): void
    {
        config([
            'meeting_agent.jira.base_url'  => 'https://test-jira.atlassian.net',
            'meeting_agent.jira.email'     => 'test@technopath.com',
            'meeting_agent.jira.api_token' => 'test-jira-token',
            'meeting_agent.jira.status_mappings' => [
                'completed'            => ['Done', 'Closed', 'Resolved'],
                'in_progress'          => ['In Progress', 'Development'],
                'blocked'              => ['Blocked', 'On Hold'],
                'ready_for_review'     => ['Ready for QA', 'Ready for Review'],
                'needs_customer_input' => ['Waiting on Customer'],
            ],
        ]);
    }

    // ── searchIssues() ─────────────────────────────────────────────────

    public function test_search_issues_sends_correct_jql_to_jira_api(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response([
                'issues' => [],
                'total'  => 0,
            ], 200),
        ]);

        $service = new JiraService();
        $service->searchIssues('project = TEST ORDER BY updated DESC');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rest/api/3/search')
                && $request['jql'] === 'project = TEST ORDER BY updated DESC'
                && str_contains($request->url(), 'test-jira.atlassian.net');
        });
    }

    public function test_search_issues_passes_pagination_params(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response([
                'issues' => [],
                'total'  => 0,
            ], 200),
        ]);

        $service = new JiraService();
        $service->searchIssues('project = TEST', maxResults: 25, nextPageToken: 'token-abc');

        Http::assertSent(function ($request) {
            return $request['maxResults'] === 25
                && $request['nextPageToken'] === 'token-abc';
        });
    }

    // ── getProjectStatusSnapshot() ─────────────────────────────────────

    public function test_get_project_status_snapshot_correctly_groups_issues(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response([
                'issues' => [
                    [
                        'key'    => 'TEST-1',
                        'fields' => [
                            'summary'  => 'Done task',
                            'status'   => ['name' => 'Done'],
                            'priority' => ['name' => 'Medium'],
                            'assignee' => ['displayName' => 'Alice'],
                            'updated'  => now()->toISOString(),
                        ],
                    ],
                    [
                        'key'    => 'TEST-2',
                        'fields' => [
                            'summary'  => 'In-progress task',
                            'status'   => ['name' => 'In Progress'],
                            'priority' => ['name' => 'Medium'],
                            'assignee' => ['displayName' => 'Bob'],
                            'updated'  => now()->toISOString(),
                        ],
                    ],
                    [
                        'key'    => 'TEST-3',
                        'fields' => [
                            'summary'  => 'Blocked task',
                            'status'   => ['name' => 'Blocked'],
                            'priority' => ['name' => 'High'],
                            'assignee' => null,
                            'updated'  => now()->toISOString(),
                        ],
                    ],
                ],
                'total' => 3,
            ], 200),
        ]);

        $service = new JiraService();
        $snapshot = $service->getProjectStatusSnapshot('TEST');

        $this->assertCount(1, $snapshot['completed_since_last_meeting']);
        $this->assertCount(1, $snapshot['in_progress']);
        $this->assertCount(1, $snapshot['blocked_or_on_hold']);
        $this->assertSame(3, $snapshot['total_count']);
    }

    public function test_unmapped_statuses_go_to_other_bucket(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response([
                'issues' => [
                    [
                        'key'    => 'TEST-1',
                        'fields' => [
                            'summary'  => 'Custom status task',
                            'status'   => ['name' => 'Needs Triage'],
                            'priority' => ['name' => 'Medium'],
                            'assignee' => ['displayName' => 'Alice'],
                            'updated'  => now()->subDays(10)->toISOString(),
                        ],
                    ],
                ],
                'total' => 1,
            ], 200),
        ]);

        $service = new JiraService();
        $snapshot = $service->getProjectStatusSnapshot('TEST');

        $this->assertCount(1, $snapshot['other']);
        $this->assertCount(0, $snapshot['completed_since_last_meeting']);
        $this->assertCount(0, $snapshot['in_progress']);
    }

    public function test_case_insensitive_status_matching(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response([
                'issues' => [
                    [
                        'key'    => 'TEST-1',
                        'fields' => [
                            'summary'  => 'Done with different casing',
                            'status'   => ['name' => 'DONE'],
                            'priority' => ['name' => 'Medium'],
                            'assignee' => ['displayName' => 'Alice'],
                            'updated'  => now()->toISOString(),
                        ],
                    ],
                    [
                        'key'    => 'TEST-2',
                        'fields' => [
                            'summary'  => 'In progress with different casing',
                            'status'   => ['name' => 'in progress'],
                            'priority' => ['name' => 'Low'],
                            'assignee' => ['displayName' => 'Bob'],
                            'updated'  => now()->toISOString(),
                        ],
                    ],
                ],
                'total' => 2,
            ], 200),
        ]);

        $service = new JiraService();
        $snapshot = $service->getProjectStatusSnapshot('TEST');

        $this->assertCount(1, $snapshot['completed_since_last_meeting']);
        $this->assertCount(1, $snapshot['in_progress']);
    }

    public function test_high_priority_issues_detected(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response([
                'issues' => [
                    [
                        'key'    => 'TEST-1',
                        'fields' => [
                            'summary'  => 'Critical bug',
                            'status'   => ['name' => 'In Progress'],
                            'priority' => ['name' => 'Highest'],
                            'assignee' => ['displayName' => 'Alice'],
                            'updated'  => now()->toISOString(),
                        ],
                    ],
                ],
                'total' => 1,
            ], 200),
        ]);

        $service = new JiraService();
        $snapshot = $service->getProjectStatusSnapshot('TEST');

        $this->assertCount(1, $snapshot['high_priority']);
    }

    // ── Configuration validation ───────────────────────────────────────

    public function test_throws_runtime_exception_when_jira_base_url_not_configured(): void
    {
        config(['meeting_agent.jira.base_url' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Jira is not configured');

        new JiraService();
    }

    public function test_throws_runtime_exception_when_jira_base_url_is_empty(): void
    {
        config(['meeting_agent.jira.base_url' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Jira is not configured');

        new JiraService();
    }

    // ── API error handling ─────────────────────────────────────────────

    public function test_search_issues_throws_on_api_error(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response('Unauthorized', 401),
        ]);

        $service = new JiraService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Jira API request failed with status 401');

        $service->searchIssues('project = TEST');
    }

    // ── OAuth 2.0 Integration Tests ────────────────────────────────────

    public function test_jira_service_instantiates_with_oauth_credentials(): void
    {
        // No global config loaded, base_url is null
        config(['meeting_agent.jira.base_url' => null]);

        // This should not throw since OAuth parameters are supplied
        $service = new JiraService(
            accessToken: 'oauth-token-123',
            cloudId: 'cloud-id-456'
        );

        $this->assertInstanceOf(JiraService::class, $service);
    }

    public function test_search_issues_sends_correct_bearer_token_and_oauth_url(): void
    {
        Http::fake([
            'api.atlassian.com/*' => Http::response([
                'issues' => [],
                'total'  => 0,
            ], 200),
        ]);

        $service = new JiraService(
            accessToken: 'oauth-token-123',
            cloudId: 'cloud-id-456'
        );

        $service->searchIssues('project = TEST');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://api.atlassian.com/ex/jira/cloud-id-456/rest/api/3/search/jql')
                && $request->hasHeader('Authorization', 'Bearer oauth-token-123')
                && $request['jql'] === 'project = TEST';
        });
    }

    public function test_find_user_returns_account_id(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response([
                [
                    'accountId'   => 'acc-id-111',
                    'displayName' => 'Nick Barretta',
                ]
            ], 200),
        ]);

        $service = new JiraService();
        $accountId = $service->findUser('Nick Barretta');

        $this->assertSame('acc-id-111', $accountId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/rest/api/3/user/search')
                && str_contains($request->url(), 'Nick')
                && str_contains($request->url(), 'Barretta');
        });
    }

    public function test_create_issue_submits_correct_payload(): void
    {
        $this->fakeJiraConfig();

        Http::fake([
            'test-jira.atlassian.net/*' => Http::response([
                'id'   => '10050',
                'key'  => 'TEST-123',
                'self' => 'https://test-jira.atlassian.net/rest/api/3/issue/10050'
            ], 201),
        ]);

        $service = new JiraService();
        $response = $service->createIssue(
            projectKey: 'TEST',
            summary: 'My test task',
            description: 'This is a description',
            assigneeAccountId: 'acc-id-111'
        );

        $this->assertSame('TEST-123', $response['key']);

        Http::assertSent(function ($request) {
            $data = json_decode($request->body(), true);
            return str_contains($request->url(), '/rest/api/3/issue')
                && ($data['fields']['project']['key'] ?? null) === 'TEST'
                && ($data['fields']['summary'] ?? null) === 'My test task'
                && ($data['fields']['assignee']['accountId'] ?? null) === 'acc-id-111'
                && ($data['fields']['description']['content'][0]['content'][0]['text'] ?? null) === 'This is a description';
        });
    }
}
