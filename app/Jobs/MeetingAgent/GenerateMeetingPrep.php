<?php

namespace App\Jobs\MeetingAgent;

use App\Enums\MeetingStatus;
use App\Models\ClientMeeting;
use App\Models\MeetingPrep;
use App\Services\MeetingAgent\AiMeetingPrepService;
use App\Services\MeetingAgent\AiProviderService;
use App\Services\MeetingAgent\JiraService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate AI-powered meeting preparation from Jira data.
 * Creates or updates a MeetingPrep record with status emails,
 * internal summaries, and recommended agendas.
 */
class GenerateMeetingPrep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public readonly int $clientMeetingId,
        public readonly string $jiraProjectKey,
        public readonly ?string $customJql = null,
        public readonly ?string $sinceDateString = null,
        public readonly ?string $model = null,
    ) {
        $this->onQueue('meetings');
    }

    public function handle(): void
    {
        $meeting = ClientMeeting::with(['client', 'owner'])->findOrFail($this->clientMeetingId);

        Log::info('GenerateMeetingPrep: starting', [
            'meeting_id'   => $this->clientMeetingId,
            'project_key'  => $this->jiraProjectKey,
            'model'        => $this->model,
        ]);

        try {
            // Fetch Jira snapshot using user OAuth context if available
            $meetingOwner = $meeting->owner;
            $jiraAccount = $meetingOwner?->jiraAccount();

            if ($jiraAccount) {
                try {
                    $accessToken = $jiraAccount->refreshJiraTokenIfNeeded();
                    $cloudId = $jiraAccount->getCredential('cloud_id');

                    $jiraService = new JiraService(
                        accessToken: $accessToken,
                        cloudId: $cloudId
                    );
                } catch (\Exception $tokenEx) {
                    Log::warning('GenerateMeetingPrep: OAuth token refresh failed, falling back to global Jira credentials.', [
                        'user_id' => $meetingOwner?->id,
                        'error'   => $tokenEx->getMessage(),
                    ]);
                    $jiraService = app(JiraService::class);
                }
            } else {
                $jiraService = app(JiraService::class);
            }

            $since = $this->sinceDateString ? Carbon::parse($this->sinceDateString) : null;

            if ($this->customJql) {
                $jiraResult = $jiraService->searchIssues($this->customJql);
                $jiraSnapshot = $jiraResult;
            } else {
                $jiraSnapshot = $jiraService->getProjectStatusSnapshot($this->jiraProjectKey, $since);
            }

            // Generate AI prep
            $aiProvider = app(AiProviderService::class);
            if ($this->model) {
                $aiProvider->setModel($this->model);
            }
            $prepService = new AiMeetingPrepService($aiProvider);
            $prepData = $prepService->generatePrep($meeting, $jiraSnapshot);

            // Create or update MeetingPrep record
            MeetingPrep::updateOrCreate(
                ['client_meeting_id' => $this->clientMeetingId],
                [
                    'jira_project_key'               => $this->jiraProjectKey,
                    'jira_jql'                       => $this->customJql ?? $jiraService->buildDefaultMeetingJql($this->jiraProjectKey, $since),
                    'internal_summary'               => $prepData['internal_summary'],
                    'generated_status_email_subject'  => $prepData['generated_status_email_subject'],
                    'generated_status_email_body'     => $prepData['generated_status_email_body'],
                    'recommended_agenda'             => $prepData['recommended_agenda'],
                    'jira_snapshot'                  => $jiraSnapshot,
                    'ai_provider'                    => $prepData['ai_provider'],
                    'ai_model'                       => $prepData['ai_model'],
                    'ai_error'                       => null,
                    'generated_at'                   => now(),
                ]
            );

            // Update meeting status
            $meeting->update(['status' => MeetingStatus::PrepGenerated]);

            Log::info('GenerateMeetingPrep: completed', [
                'meeting_id' => $this->clientMeetingId,
            ]);

        } catch (\Exception $e) {
            Log::error('GenerateMeetingPrep: failed', [
                'meeting_id' => $this->clientMeetingId,
                'error'      => $e->getMessage(),
            ]);

            // Save the error to the prep record
            MeetingPrep::updateOrCreate(
                ['client_meeting_id' => $this->clientMeetingId],
                [
                    'jira_project_key' => $this->jiraProjectKey,
                    'ai_error'         => $e->getMessage(),
                ]
            );

            // Reset meeting status back to Detected so it's not stuck in PrepPending
            $meeting->update(['status' => MeetingStatus::Detected]);

            throw $e;
        }
    }
}
