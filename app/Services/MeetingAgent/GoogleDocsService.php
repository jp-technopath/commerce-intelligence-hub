<?php

namespace App\Services\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ClientMeeting;
use App\Models\ConnectedAccount;
use App\Models\MeetingPrep;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Docs as Google_Service_Docs;
use Google\Service\Docs\BatchUpdateDocumentRequest;
use Google\Service\Docs\Document;
use Google\Service\Docs\InsertTextRequest;
use Google\Service\Docs\Request as DocsRequest;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Docs integration for creating pre-populated meeting notes documents.
 */
class GoogleDocsService
{
    private ConnectedAccount $account;
    private Google_Service_Docs $docsService;
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;

        $this->account = ConnectedAccount::where('user_id', $user->id)
            ->where('provider', 'google_workspace')
            ->where('status', ConnectedAccountStatus::Active)
            ->firstOrFail();

        $credentials = $this->account->credentials_json ?? [];
        $refreshToken = $credentials['refresh_token'] ?? null;

        if (! $refreshToken) {
            throw new RuntimeException("No refresh token found for user {$user->id} Google Workspace account.");
        }

        $client = $this->buildGoogleClient($refreshToken);
        $this->docsService = new Google_Service_Docs($client);
    }

    /**
     * Create a Google Doc pre-populated with meeting notes template.
     *
     * @return array{doc_id: string, doc_url: string}
     */
    public function createMeetingNotesDoc(ClientMeeting $meeting, MeetingPrep $prep): array
    {
        // Check for drive.file scope using full URL from config
        $requiredScope = config('meeting_agent.google.scopes.drive_file');
        if (! $this->account->hasScope($requiredScope)) {
            throw new RuntimeException('User does not have the drive.file scope granted. Please reconnect Google Workspace.');
        }

        $meetingDate = $meeting->meeting_start_at
            ? $meeting->meeting_start_at->format('Y-m-d')
            : now()->format('Y-m-d');

        $docTitle = "Meeting Notes — {$meeting->title} — {$meetingDate}";

        try {
            // Create the document
            $document = new Document();
            $document->setTitle($docTitle);
            $createdDoc = $this->docsService->documents->create($document);

            $docId = $createdDoc->getDocumentId();

            // Pre-populate with meeting notes template
            $this->populateTemplate($docId, $meeting, $prep);

            $docUrl = "https://docs.google.com/document/d/{$docId}/edit";

            return [
                'doc_id'  => $docId,
                'doc_url' => $docUrl,
            ];
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleApiError($e);
            throw new RuntimeException('Failed to create Google Doc: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Populate the document with meeting notes template sections.
     *
     * TODO: Implement folder management when broader Drive scopes are available.
     */
    private function populateTemplate(string $docId, ClientMeeting $meeting, MeetingPrep $prep): void
    {
        $attendees = $this->formatAttendees($meeting);
        $agenda = $prep->recommended_agenda ?? 'No agenda generated.';

        // Build document content (inserted in reverse order since each insert pushes content down)
        $sections = [
            "Attendees\n" . $attendees . "\n\n",
            "Agenda\n" . $agenda . "\n\n",
            "Discussion Notes\n\n\n",
            "Decisions\n\n\n",
            "Action Items\n\n\n",
        ];

        $fullContent = implode('', $sections);

        $requests = [];

        $insertRequest = new DocsRequest();
        $insertText = new InsertTextRequest();
        $insertText->setText($fullContent);
        $insertText->setLocation(new \Google\Service\Docs\Location(['index' => 1]));
        $insertRequest->setInsertText($insertText);
        $requests[] = $insertRequest;

        $batchUpdate = new BatchUpdateDocumentRequest();
        $batchUpdate->setRequests($requests);

        $this->docsService->documents->batchUpdate($docId, $batchUpdate);
    }

    private function formatAttendees(ClientMeeting $meeting): string
    {
        $lines = [];

        $internal = $meeting->internal_attendees ?? [];
        if (! empty($internal)) {
            $lines[] = 'Internal:';
            foreach ($internal as $attendee) {
                $name = $attendee['name'] ?? $attendee['email'] ?? 'Unknown';
                $lines[] = "  - {$name}";
            }
        }

        $external = $meeting->external_attendees ?? [];
        if (! empty($external)) {
            $lines[] = 'External:';
            foreach ($external as $attendee) {
                $name = $attendee['name'] ?? $attendee['email'] ?? 'Unknown';
                $lines[] = "  - {$name}";
            }
        }

        return implode("\n", $lines);
    }

    private function buildGoogleClient(string $refreshToken): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setAccessType('offline');

        try {
            $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (\Exception $e) {
            $this->account->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Token refresh failed: ' . $e->getMessage(),
            ]);
            throw new RuntimeException("Failed to refresh Google token for user {$this->user->id}: " . $e->getMessage());
        }

        if ($client->isAccessTokenExpired()) {
            $this->account->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Access token expired after refresh attempt.',
            ]);
            throw new RuntimeException("Google access token expired after refresh for user {$this->user->id}.");
        }

        return $client;
    }

    private function handleGoogleApiError(\Google\Service\Exception $e): void
    {
        if ($e->getCode() === 401 || $e->getCode() === 403) {
            $this->account->update([
                'status'     => ConnectedAccountStatus::Error,
                'last_error' => 'Google Docs API auth error (' . $e->getCode() . '): ' . $e->getMessage(),
            ]);
        }

        Log::error('GoogleDocsService: Google API error', [
            'user_id' => $this->user->id,
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
        ]);
    }
}
