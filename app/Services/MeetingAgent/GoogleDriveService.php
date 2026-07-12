<?php

namespace App\Services\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ClientMeeting;
use App\Models\ConnectedAccount;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Docs as Google_Service_Docs;
use Google\Service\Drive as Google_Service_Drive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Drive integration for searching and retrieving
 * Google Meet transcripts and meeting notes documents.
 */
class GoogleDriveService
{
    private ConnectedAccount $account;
    private Google_Service_Drive $driveService;
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
        $this->driveService = new Google_Service_Drive($client);
        $this->docsService = new Google_Service_Docs($client);
    }

    /**
     * Search for Google Meet transcript and notes files related to a meeting.
     *
     * Google Meet saves:
     * - Transcripts as Google Docs named like "{Meeting Title} - Transcript" in "Meet Recordings" folder
     * - Meeting notes as Google Docs linked to calendar events
     *
     * @return array{transcripts: array, notes: array}
     */
    public function findMeetingDocuments(ClientMeeting $meeting): array
    {
        $title = $meeting->title;
        $meetingDate = $meeting->meeting_start_at;

        $transcripts = [];
        $notes = [];

        try {
            // Search for transcript files
            $transcriptFiles = $this->searchDriveFiles($title, $meetingDate, 'transcript');

            foreach ($transcriptFiles as $file) {
                $transcripts[] = [
                    'id'       => $file->getId(),
                    'name'     => $file->getName(),
                    'url'      => $file->getWebViewLink(),
                    'modified' => $file->getModifiedTime(),
                    'mimeType' => $file->getMimeType(),
                ];
            }

            // Search for meeting notes
            $noteFiles = $this->searchDriveFiles($title, $meetingDate, 'notes');

            foreach ($noteFiles as $file) {
                $notes[] = [
                    'id'       => $file->getId(),
                    'name'     => $file->getName(),
                    'url'      => $file->getWebViewLink(),
                    'modified' => $file->getModifiedTime(),
                    'mimeType' => $file->getMimeType(),
                ];
            }

            Log::info('GoogleDriveService: found meeting documents', [
                'meeting_id'  => $meeting->id,
                'transcripts' => count($transcripts),
                'notes'       => count($notes),
            ]);
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleApiError($e);
            throw new RuntimeException('Failed to search Google Drive: ' . $e->getMessage());
        }

        return [
            'transcripts' => $transcripts,
            'notes'       => $notes,
        ];
    }

    /**
     * Read the text content of a Google Doc by its file ID.
     */
    public function readDocumentContent(string $documentId): string
    {
        try {
            $doc = $this->docsService->documents->get($documentId);
            return $this->extractTextFromDoc($doc);
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleApiError($e);
            throw new RuntimeException('Failed to read Google Doc: ' . $e->getMessage());
        }
    }

    /**
     * Read and parse a Google Doc, extracting notes and transcript separately (supporting tabs).
     *
     * @return array{notes: string, transcript: string}
     */
    public function readDocumentParsed(string $documentId): array
    {
        try {
            $doc = $this->docsService->documents->get($documentId, [
                'includeTabsContent' => true
            ]);

            $tabs = $doc->getTabs();
            if ($tabs && count($tabs) > 0) {
                $notesSections = [];
                $transcriptSections = [];

                foreach ($tabs as $tab) {
                    $props = $tab->getTabProperties();
                    $title = $props ? $props->getTitle() : 'Untitled Tab';
                    $docTab = $tab->getDocumentTab();
                    
                    if ($docTab) {
                        $text = $this->extractTextFromDocTab($docTab);
                        if (stripos($title, 'transcript') !== false) {
                            $transcriptSections[] = $text;
                        } else {
                            // Format non-transcript tabs nicely with headers
                            $notesSections[] = "### {$title}\n\n" . $text;
                        }
                    }
                }

                return [
                    'notes'      => trim(implode("\n\n", $notesSections)),
                    'transcript' => trim(implode("\n\n", $transcriptSections)),
                ];
            }

            // Fallback for non-tabbed documents
            $text = $this->extractTextFromDoc($doc);
            return [
                'notes'      => $text,
                'transcript' => '',
            ];
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleApiError($e);
            throw new RuntimeException('Failed to read Google Doc: ' . $e->getMessage());
        }
    }

    /**
     * Find and extract transcript and notes content for a meeting.
     * Returns the combined text from all found documents.
     *
     * @return array{transcript: string, notes: string, files_found: array}
     */
    public function pullMeetingTranscript(ClientMeeting $meeting): array
    {
        $documents = $this->findMeetingDocuments($meeting);

        $transcript = '';
        $notes = '';
        $filesFound = [];

        // Track seen file IDs to avoid duplicates
        $seenFileIds = [];

        // Pull notes content
        foreach ($documents['notes'] as $file) {
            if ($file['mimeType'] === 'application/vnd.google-apps.document' && ! in_array($file['id'], $seenFileIds)) {
                $parsed = $this->readDocumentParsed($file['id']);
                
                if (! empty($parsed['notes'])) {
                    $notes .= ($notes ? "\n\n---\n\n" : '') . $parsed['notes'];
                }
                if (! empty($parsed['transcript'])) {
                    $transcript .= ($transcript ? "\n\n---\n\n" : '') . $parsed['transcript'];
                }
                
                $seenFileIds[] = $file['id'];
                $filesFound[] = $file;
            }
        }

        // Pull transcript content (if distinct from notes)
        foreach ($documents['transcripts'] as $file) {
            if ($file['mimeType'] === 'application/vnd.google-apps.document' && ! in_array($file['id'], $seenFileIds)) {
                $parsed = $this->readDocumentParsed($file['id']);
                
                if (! empty($parsed['notes'])) {
                    $notes .= ($notes ? "\n\n---\n\n" : '') . $parsed['notes'];
                }
                if (! empty($parsed['transcript'])) {
                    $transcript .= ($transcript ? "\n\n---\n\n" : '') . $parsed['transcript'];
                }
                
                $seenFileIds[] = $file['id'];
                $filesFound[] = $file;
            }
        }

        return [
            'transcript'  => $transcript,
            'notes'       => $notes,
            'files_found' => $filesFound,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Search Google Drive for files matching meeting criteria.
     */
    private function searchDriveFiles(string $meetingTitle, ?Carbon $meetingDate, string $type): array
    {
        // Clean up the meeting title for search
        $cleanTitle = preg_replace('/[^a-zA-Z0-9\s]/', '', $meetingTitle);
        $titleWords = array_filter(explode(' ', $cleanTitle));
        $significantWords = array_filter($titleWords, fn ($w) => strlen($w) > 2);
        // Fallback to all words if no word is > 2 characters
        $wordsToUse = count($significantWords) > 0 ? $significantWords : $titleWords;

        // Build multiple search strategies from most specific to broadest
        $queries = [];

        if (count($wordsToUse) > 0) {
            // Use the first word as the primary search identifier
            $primaryWord = reset($wordsToUse);
            
            $typeKeywords = ($type === 'transcript')
                ? ['transcript', 'Transcript']
                : ['notes', 'Notes', 'Meeting Notes'];

            // Strategy 1: Match meeting title keyword AND specific type keywords (e.g., "Biz" and "Transcript")
            foreach ($typeKeywords as $typeKeyword) {
                $queries[] = "name contains '{$primaryWord}' and name contains '{$typeKeyword}'";
            }

            // Strategy 2: Fallback for notes only — match the title keyword even without "Notes" in the filename
            if ($type === 'notes') {
                $queries[] = "name contains '{$primaryWord}'";
            }
        }

        $allFiles = [];
        $seenIds = [];

        foreach ($queries as $nameQuery) {
            $query = "({$nameQuery}) and mimeType = 'application/vnd.google-apps.document' and trashed = false";

            // Add a wide date filter if we have a meeting date (±7 days)
            if ($meetingDate) {
                $startDate = $meetingDate->copy()->subDays(7)->format('Y-m-d\TH:i:s');
                $endDate = $meetingDate->copy()->addDays(7)->format('Y-m-d\TH:i:s');
                $query .= " and modifiedTime >= '{$startDate}' and modifiedTime <= '{$endDate}'";
            }

            try {
                $results = $this->driveService->files->listFiles([
                    'q'        => $query,
                    'fields'   => 'files(id, name, webViewLink, modifiedTime, mimeType)',
                    'orderBy'  => 'modifiedTime desc',
                    'spaces'   => 'drive',
                    'pageSize' => 10,
                ]);

                foreach ($results->getFiles() as $file) {
                    if (! isset($seenIds[$file->getId()])) {
                        $seenIds[$file->getId()] = true;
                        $allFiles[] = $file;
                    }
                }

                // If we found results with a specific query, no need for broader fallbacks
                if (count($allFiles) > 0) {
                    break;
                }
            } catch (\Google\Service\Exception $e) {
                Log::warning('GoogleDriveService: search query failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $allFiles;
    }

    /**
     * Extract plain text from a Google Docs Document object.
     */
    private function extractTextFromDoc($document): string
    {
        return $this->extractTextFromBody($document?->getBody());
    }

    /**
     * Extract plain text from a Google Docs DocumentTab object.
     */
    private function extractTextFromDocTab($docTab): string
    {
        return $this->extractTextFromBody($docTab?->getBody());
    }

    /**
     * Extract plain text from a Google Docs Body object.
     */
    private function extractTextFromBody($body): string
    {
        if (! $body) {
            return '';
        }

        $text = '';
        $content = $body->getContent() ?? [];

        foreach ($content as $element) {
            $paragraph = $element->getParagraph();
            if ($paragraph) {
                foreach ($paragraph->getElements() as $elem) {
                    $textRun = $elem->getTextRun();
                    if ($textRun) {
                        $text .= $textRun->getContent();
                    }
                }
            }

            $table = $element->getTable();
            if ($table) {
                foreach ($table->getTableRows() as $row) {
                    foreach ($row->getTableCells() as $cell) {
                        foreach ($cell->getContent() as $cellContent) {
                            $cellParagraph = $cellContent->getParagraph();
                            if ($cellParagraph) {
                                foreach ($cellParagraph->getElements() as $elem) {
                                    $textRun = $elem->getTextRun();
                                    if ($textRun) {
                                        $text .= $textRun->getContent();
                                    }
                                }
                                $text .= "\t";
                            }
                        }
                        $text .= "\n";
                    }
                }
            }
        }

        return trim($text);
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
                'last_error' => 'Google Drive API auth error (' . $e->getCode() . '): ' . $e->getMessage(),
            ]);
        }

        Log::error('GoogleDriveService: Google API error', [
            'user_id' => $this->user->id,
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
        ]);
    }
}
