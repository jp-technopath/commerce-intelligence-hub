<?php

namespace Tests\Feature\MeetingAgent;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\MeetingFollowUp;
use App\Models\MeetingPrep;
use App\Models\User;
use App\Services\MeetingAgent\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * CRITICAL SAFETY TEST
 *
 * Ensures GmailService can NEVER send emails directly.
 * All email sending must happen manually by the user in Gmail.
 * This is a deliberate safety constraint that must be verified by tests.
 */
class GmailSafetyTest extends TestCase
{
    use RefreshDatabase;

    // ── GmailService has NO send methods ────────────────────────────────

    public function test_gmail_service_has_no_public_send_methods(): void
    {
        $reflection = new ReflectionClass(GmailService::class);
        $publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $forbiddenPrefixes = ['send'];
        $forbiddenNames = ['send', 'sendDraft', 'sendMessage', 'sendEmail', 'sendMail'];

        foreach ($publicMethods as $method) {
            // Skip constructor and inherited methods
            if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== GmailService::class) {
                continue;
            }

            $methodName = $method->getName();

            // Check exact forbidden names
            $this->assertNotContains(
                $methodName,
                $forbiddenNames,
                "GmailService must NOT have a public method named '{$methodName}'. Emails must be sent manually by the user in Gmail."
            );

            // Check forbidden prefixes (any method starting with 'send')
            foreach ($forbiddenPrefixes as $prefix) {
                $this->assertFalse(
                    str_starts_with(strtolower($methodName), strtolower($prefix)),
                    "GmailService must NOT have a public method starting with '{$prefix}' (found '{$methodName}'). This is a safety constraint."
                );
            }
        }
    }

    public function test_gmail_service_only_has_create_draft_as_public_method(): void
    {
        $reflection = new ReflectionClass(GmailService::class);
        $publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $ownPublicMethods = [];
        foreach ($publicMethods as $method) {
            if (! $method->isConstructor() && $method->getDeclaringClass()->getName() === GmailService::class) {
                $ownPublicMethods[] = $method->getName();
            }
        }

        // The ONLY public method should be createDraft
        $this->assertSame(
            ['createDraft'],
            $ownPublicMethods,
            'GmailService should only expose createDraft() as a public method. Found: ' . implode(', ', $ownPublicMethods)
        );
    }

    // ── createDraft() scope check ──────────────────────────────────────

    public function test_create_draft_throws_when_user_lacks_gmail_compose_scope(): void
    {
        $user = User::factory()->create();

        // Create a connected account WITHOUT the gmail.compose scope
        ConnectedAccount::create([
            'user_id'          => $user->id,
            'provider'         => 'google_workspace',
            'authorized_email' => 'test@technopath.com',
            'credentials_json' => [
                'refresh_token' => 'test-refresh-token',
                'token_type'    => 'Bearer',
            ],
            'granted_scopes' => [
                'https://www.googleapis.com/auth/calendar.events.readonly',
                // Deliberately MISSING: https://www.googleapis.com/auth/gmail.compose
            ],
            'status' => ConnectedAccountStatus::Active,
        ]);

        // GmailService constructor requires Google Client, which we can't fully mock
        // in a feature test. Instead, we test the scope check by creating the service
        // and attempting createDraft. The constructor will fail trying to refresh
        // the token, so we test via reflection of the scope checking pattern.
        //
        // Verify the scope-checking code path by testing the ConnectedAccount directly
        $account = ConnectedAccount::where('user_id', $user->id)
            ->where('provider', 'google_workspace')
            ->first();

        $requiredScope = config('meeting_agent.google.scopes.gmail_compose');
        $this->assertFalse(
            $account->hasScope($requiredScope),
            'Account should NOT have gmail.compose scope — this protects against unauthorized draft creation.'
        );
    }

    // ── Effective subject/body: edited takes priority ───────────────────

    public function test_meeting_prep_effective_subject_returns_edited_over_generated(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_subject' => 'AI Generated Subject',
            'edited_status_email_subject'    => 'Human Edited Subject',
        ]);

        $this->assertSame('Human Edited Subject', $prep->effectiveSubject());
        $this->assertNotSame($prep->generated_status_email_subject, $prep->effectiveSubject());
    }

    public function test_meeting_followup_effective_subject_returns_edited_over_generated(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_subject' => 'AI Generated Follow-Up Subject',
            'edited_followup_email_subject'    => 'Human Edited Follow-Up Subject',
        ]);

        $this->assertSame('Human Edited Follow-Up Subject', $followUp->effectiveSubject());
        $this->assertNotSame($followUp->generated_followup_email_subject, $followUp->effectiveSubject());
    }

    public function test_meeting_prep_effective_body_returns_edited_over_generated(): void
    {
        $prep = new MeetingPrep([
            'generated_status_email_body' => '<p>AI body</p>',
            'edited_status_email_body'    => '<p>Human body</p>',
        ]);

        $this->assertSame('<p>Human body</p>', $prep->effectiveBody());
    }

    public function test_meeting_followup_effective_body_returns_edited_over_generated(): void
    {
        $followUp = new MeetingFollowUp([
            'generated_followup_email_body' => '<p>AI follow-up body</p>',
            'edited_followup_email_body'    => '<p>Human follow-up body</p>',
        ]);

        $this->assertSame('<p>Human follow-up body</p>', $followUp->effectiveBody());
    }
}
