<?php

namespace App\Services;

use App\Models\DevelopmentRequest;

class AgentJobPayloadFactory
{
    private const JIRA_FIELDS = [
        'key',
        'id',
        'summary',
        'description',
        'acceptance_criteria',
        'issue_type',
        'priority',
        'labels',
        'url',
    ];

    public function __construct(private readonly PayloadRedactor $redactor) {}

    public function build(DevelopmentRequest $request, string $role): array
    {
        $routing = $request->routing_snapshot ?? [];
        $jira = array_intersect_key($request->jira_snapshot ?? [], array_flip(self::JIRA_FIELDS));

        return $this->redactor->redact([
            'schema_version' => 1,
            'request' => [
                'id' => $request->getKey(),
                'correlation_identifier' => $request->correlation_identifier,
                'type' => $request->request_type,
                'title' => $request->title,
                'description' => $request->description,
                'priority' => $request->priority,
                'environment_key' => $request->environment_key,
                'jira' => $jira,
            ],
            'execution' => [
                'role' => $role,
                'capability_tier' => $request->selected_capability_tier,
                'mapping_id' => $routing['mapping_id'] ?? null,
                'mapping_version' => $routing['mapping_version'] ?? null,
                'repository_id' => $routing['repository_id'] ?? null,
                'workspace_path' => $routing['workspace_path'] ?? null,
                'default_branch' => $routing['default_branch'] ?? null,
                'model_group_alias' => data_get(
                    $routing,
                    'model_group_aliases.'.$request->selected_capability_tier
                ),
            ],
            'approved_context' => data_get(
                $request->latestStatusHistoryEntry()?->metadata,
                'approved_context'
            ),
            'output_contract' => $this->outputContract($role),
        ]);
    }

    private function outputContract(string $role): array
    {
        return [
            'schema_version' => 1,
            'required' => ['summary', 'artifacts', 'warnings'],
            'summary_max_characters' => 10000,
            'artifacts_max_items' => 50,
            'warnings_max_items' => 50,
            'role' => $role,
        ];
    }
}
