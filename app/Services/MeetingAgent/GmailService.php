<?php

namespace App\Services\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Gmail as Google_Service_Gmail;
use Google\Service\Gmail\Draft as Google_Service_Gmail_Draft;
use Google\Service\Gmail\Message as Google_Service_Gmail_Message;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gmail Service — Draft creation and email sending.
 *
 * Supports creating drafts and sending emails via the Gmail API.
 * All actions are performed on behalf of the authenticated user.
 */
class GmailService
{
    private ConnectedAccount $account;
    private Google_Service_Gmail $gmailService;

    public function __construct(private readonly User $user)
    {
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
        $this->gmailService = new Google_Service_Gmail($client);
    }

    /**
     * Create a draft in the connected user's Gmail mailbox.
     * Returns the Gmail draft ID.
     *
     * @throws RuntimeException if user lacks gmail.compose scope
     */
    public function createDraft(string $to, string $subject, string $body, array $cc = []): string
    {
        // Check for gmail.compose scope using full URL from config
        $requiredScope = config('meeting_agent.google.scopes.gmail_compose');
        if (! $this->account->hasScope($requiredScope)) {
            throw new RuntimeException('User does not have the gmail.compose scope granted. Please reconnect Google Workspace.');
        }

        // Build RFC 2822 MIME message
        $mime = $this->buildMimeMessage($to, $subject, $body, $cc);

        // Base64url encode
        $encodedMessage = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

        try {
            $message = new Google_Service_Gmail_Message();
            $message->setRaw($encodedMessage);

            $draft = new Google_Service_Gmail_Draft();
            $draft->setMessage($message);

            $createdDraft = $this->gmailService->users_drafts->create('me', $draft);

            return $createdDraft->getId();
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleApiError($e);
            throw new RuntimeException('Failed to create Gmail draft: ' . $e->getMessage());
        }
    }


    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    private function buildMimeMessage(string $to, string $subject, string $body, array $cc = []): string
    {
        $authorizedEmail = $this->account->authorized_email ?? 'me';

        // RFC 2047 encode subject for non-ASCII characters (em dashes, accents, etc.)
        $encodedSubject = mb_detect_encoding($subject, 'ASCII', true) === 'ASCII'
            ? $subject
            : '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [
            "From: {$authorizedEmail}",
            "To: {$to}",
            "Subject: {$encodedSubject}",
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        if (! empty($cc)) {
            $headers[] = 'Cc: ' . implode(', ', $cc);
        }

        $encodedBody = rtrim(chunk_split(base64_encode($body)));

        return implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
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
                'last_error' => 'Gmail API auth error (' . $e->getCode() . '): ' . $e->getMessage(),
            ]);
        }

        Log::error('GmailService: Google API error', [
            'user_id' => $this->user->id,
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
        ]);
    }
}
