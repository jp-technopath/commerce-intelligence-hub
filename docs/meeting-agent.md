# Customer Meeting Agent – Technical Documentation

> **Module Scope:** AI-powered meeting preparation and follow-up generation for customer meetings, integrated with Google Calendar, Gmail, Jira, and Google Docs.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Setup & Configuration](#setup--configuration)
4. [Usage Workflow](#usage-workflow)
5. [Jira Status Mapping](#jira-status-mapping)
6. [Security & Safety Constraints](#security--safety-constraints)
7. [Troubleshooting](#troubleshooting)
8. [Testing](#testing)

---

## Overview

The Customer Meeting Agent automates the preparation and follow-up process for customer meetings. It:

- **Scans Google Calendar** for upcoming meetings with external attendees
- **Fetches Jira ticket data** for the associated project, grouped by status
- **Generates AI-powered meeting prep** including internal summaries, status email drafts, and agenda recommendations
- **Creates AI-powered follow-up** including meeting summaries, follow-up email drafts, action items, and decisions
- **Creates Gmail drafts** (never sends directly) so users review before sending
- **Creates Google Docs** for internal meeting prep documents

### Key Design Decisions

| Decision | Rationale |
|---|---|
| Gmail drafts only (no send) | Safety: ensures human review before any client communication |
| ConnectedAccount model (not Integration) | Workspace credentials are user-scoped, not tenant/integration-scoped |
| Full URL scope checking | Prevents partial-string scope matching bugs |
| Session-bound OAuth nonce | CSRF protection on OAuth callbacks |
| Edited content overrides generated | Human edits always take priority over AI output |

---

## Architecture

### Models

| Model | Table | Purpose |
|---|---|---|
| `ConnectedAccount` | `connected_accounts` | Stores per-user Google Workspace OAuth credentials and granted scopes |
| `ClientMeeting` | `client_meetings` | Central record for detected or manually created meetings |
| `MeetingPrep` | `meeting_preps` | AI-generated pre-meeting prep (summary, email draft, agenda) |
| `MeetingFollowUp` | `meeting_follow_ups` | AI-generated post-meeting follow-up (summary, email draft, action items) |
| `MeetingActionItem` | `meeting_action_items` | Individual action items (user-created from AI suggestions) |

### Services (`App\Services\MeetingAgent\`)

| Service | Responsibility |
|---|---|
| `AiProviderService` | Multi-provider AI abstraction (OpenRouter, OpenAI, Gemini) with JSON retry logic |
| `JiraService` | Jira Cloud API integration – JQL search and status-grouped snapshots |
| `GoogleCalendarService` | Calendar scanning, meeting detection, client matching |
| `GmailService` | Gmail draft creation (**ONE public method: `createDraft()`**) |
| `GoogleDocsService` | Google Docs creation for internal prep documents |
| `AiMeetingPrepService` | Prompt construction and AI orchestration for meeting prep |
| `AiFollowUpService` | Prompt construction and AI orchestration for follow-up |

### Jobs (`App\Jobs\MeetingAgent\`)

| Job | Purpose |
|---|---|
| `ScanUpcomingClientMeetings` | Scans Google Calendar for upcoming client meetings |
| `GenerateMeetingPrep` | Generates AI meeting prep for a specific meeting |
| `GenerateMeetingFollowUp` | Generates AI follow-up for a completed meeting |

### Enums (`App\Enums\`)

| Enum | Values |
|---|---|
| `MeetingStatus` | `detected`, `needs_mapping`, `prep_generated`, `ready`, `completed`, `followup_generated`, `canceled` |
| `MeetingSource` | `manual`, `google_calendar` |
| `ActionItemStatus` | `open`, `in_progress`, `completed`, `canceled` |
| `ActionItemSource` | `manual`, `ai_suggested` |
| `ConnectedAccountStatus` | `active`, `revoked`, `expired`, `error` |

### Data Flow

```
┌──────────────┐    ┌─────────────────┐    ┌──────────────────┐
│   Google      │    │  ScanUpcoming   │    │  ClientMeeting   │
│   Calendar    │───▶│  ClientMeetings │───▶│   (detected)     │
│   API         │    │  Job            │    │                  │
└──────────────┘    └─────────────────┘    └────────┬─────────┘
                                                     │
                    ┌─────────────────┐              │
                    │  GenerateMeeting│◀─────────────┘
                    │  Prep Job       │
                    └────────┬────────┘
                             │
              ┌──────────────┼──────────────┐
              ▼              ▼              ▼
        ┌──────────┐  ┌──────────┐  ┌──────────────┐
        │  Jira    │  │  AI      │  │  MeetingPrep │
        │  Service │  │  Provider│  │  (created)   │
        └──────────┘  │  Service │  └──────────────┘
                      └──────────┘
                                    ┌──────────────────┐
                                    │  User Reviews &  │
                                    │  Edits Content   │
                                    └────────┬─────────┘
                                             │
                    ┌─────────────────┐      │
                    │  Gmail Draft    │◀─────┘
                    │  Created        │
                    │  (user sends    │
                    │   manually)     │
                    └─────────────────┘
```

---

## Setup & Configuration

### Environment Variables

Add the following to your `.env` file:

```env
# ── Customer Meeting Agent ────────────────────────────────

# AI Provider (openrouter | openai | gemini)
AI_PROVIDER=openrouter
OPENROUTER_API_KEY=
OPENROUTER_MODEL=openai/gpt-4o
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
AI_MAX_TOKENS=4096

# Jira Cloud Integration
JIRA_BASE_URL=https://yourorg.atlassian.net
JIRA_EMAIL=bot@yourcompany.com
JIRA_API_TOKEN=

# Company Email Domains (comma-separated, for attendee classification)
COMPANY_EMAIL_DOMAINS=technopath.com

# Google OAuth (shared with GA4, configured in config/google.php)
# GOOGLE_CLIENT_ID=
# GOOGLE_CLIENT_SECRET=
# GOOGLE_REDIRECT_URI=
```

### Configuration File

All meeting agent config lives in `config/meeting_agent.php`:

| Key | Description | Default |
|---|---|---|
| `ai.provider` | AI provider selection | `openrouter` |
| `ai.openrouter_key` | OpenRouter API key | `null` |
| `ai.openrouter_model` | OpenRouter model | `openai/gpt-4o` |
| `ai.openai_key` | OpenAI API key | `null` |
| `ai.openai_model` | OpenAI model | `gpt-4o` |
| `ai.gemini_key` | Gemini API key | `null` |
| `ai.gemini_model` | Gemini model | `gemini-2.5-flash` |
| `ai.max_tokens` | Max response tokens | `4096` |
| `jira.base_url` | Jira Cloud base URL | `null` |
| `jira.email` | Jira API email | `null` |
| `jira.api_token` | Jira API token | `null` |
| `jira.status_mappings` | Status-to-bucket mapping | See config |
| `calendar.scan_days_ahead` | Days to scan ahead | `7` |
| `calendar.company_domains` | Internal email domains | `[]` |
| `calendar.exclude_patterns` | Regex patterns to exclude | See config |
| `calendar.include_hashtag` | Force-include hashtag | `#customer-meeting` |
| `google.scopes.*` | Named scope constants | Full URLs |

### Google Cloud Console Setup

1. Create OAuth 2.0 Client ID (Web application type)
2. Add authorized redirect URI: `{APP_URL}/google/oauth/callback`
3. Enable APIs:
   - Google Calendar API
   - Gmail API
   - Google Docs API
   - Google Drive API
4. Configure OAuth consent screen with required scopes

### AI Provider Fallback Chain

```
openrouter (if key set) → openai (if key set) → gemini (if key set) → RuntimeException
```

The `AiProviderService` constructor resolves the provider at instantiation time. If the configured provider has no API key, it falls back to the next available provider.

---

## Usage Workflow

### 1. Connect Google Workspace

1. User navigates to the Client Meetings panel
2. Clicks **Connect Google Workspace**
3. Redirected to Google consent screen requesting:
   - `calendar.events.readonly` – Read calendar events
   - `gmail.compose` – Create email drafts
   - `drive.file` – Create Google Docs
4. After consent, a `ConnectedAccount` record is created with granted scopes

### 2. Scan Calendar

1. `ScanUpcomingClientMeetings` job runs (scheduled or manual trigger)
2. Reads events from the user's Google Calendar for the next N days
3. Filters using `isLikelyClientMeeting()`:
   - ✅ External attendee present (non-company-domain email)
   - ✅ Event title/description contains `#customer-meeting`
   - ✅ Known client name in title
   - ❌ Title matches exclude pattern (standup, 1:1, internal, etc.)
4. Creates `ClientMeeting` records with `detected` status
5. Matches clients by name or attendee domain

### 3. Generate Meeting Prep

1. User triggers `GenerateMeetingPrep` for a specific meeting
2. Job fetches Jira tickets for the associated project
3. Builds AI prompt with Jira snapshot + client context
4. AI generates:
   - Internal summary
   - Customer status email (subject + body)
   - Recommended agenda
5. Creates `MeetingPrep` record
6. Meeting status → `prep_generated`

### 4. Review & Edit

1. User reviews AI-generated content in the Filament panel
2. Edits subject/body as needed (saved to `edited_*` fields)
3. `effectiveSubject()` / `effectiveBody()` always return edited if available

### 5. Create Gmail Draft

1. User clicks "Create Draft" in the panel
2. `GmailService::createDraft()` creates a draft in Gmail
3. User opens Gmail, reviews the draft, and sends manually

### 6. Post-Meeting Follow-Up

1. After the meeting, user enters raw notes/transcript
2. `GenerateMeetingFollowUp` job processes with AI:
   - Meeting summary
   - Follow-up email draft
   - Suggested action items
   - Decisions and open questions
3. Creates `MeetingFollowUp` record
4. Meeting status → `followup_generated`

### 7. Action Items

- AI-suggested action items are stored as JSON on `MeetingFollowUp`
- They are **NOT** auto-created as `MeetingActionItem` records
- User explicitly accepts/creates action items from suggestions

---

## Jira Status Mapping

The `JiraService` groups fetched tickets into status buckets based on `config('meeting_agent.jira.status_mappings')`:

| Bucket | Jira Statuses | Purpose |
|---|---|---|
| `completed_since_last_meeting` | Done, Closed, Resolved, Complete | Work delivered since last sync |
| `in_progress` | In Progress, Development, In Dev | Active work |
| `blocked_or_on_hold` | Blocked, On Hold | Items needing attention |
| `ready_for_review` | Ready for QA, Ready for Review, UAT | Items ready for client review |
| `needs_customer_input` | Waiting on Customer, Needs Clarification | Items blocked on client |
| `high_priority` | Any issue with Highest/High priority | Flagged for discussion |
| `other` | Unmapped statuses | Catch-all bucket |

### Customizing Status Mappings

Edit `config/meeting_agent.php` → `jira.status_mappings` to match your Jira workflow:

```php
'status_mappings' => [
    'completed' => ['Done', 'Closed', 'Resolved', 'Shipped'],
    'in_progress' => ['In Progress', 'Development', 'Coding'],
    // ... add your custom statuses
],
```

Matching is **case-insensitive** — `Done`, `DONE`, and `done` all match.

---

## Security & Safety Constraints

### Gmail Safety (CRITICAL)

> **`GmailService` has ONE public method: `createDraft()`. There are NO send methods.**

This is a deliberate, non-negotiable safety constraint. All emails must be:
1. Created as drafts in the user's Gmail
2. Reviewed by the user in Gmail
3. Sent manually by the user

The `GmailSafetyTest` enforces this via reflection — it will fail if any public method starting with "send" is added to `GmailService`.

### OAuth Security

| Mechanism | Protection |
|---|---|
| Session-bound nonce | CSRF protection on OAuth callbacks |
| User ID in state | Prevents cross-user token injection |
| Nonce consumed on use | Prevents replay attacks |
| Domain whitelist | Only allowed email domains can create accounts via Google login |

### Scope Management

- All scope checks use **full Google URLs** via `config('meeting_agent.google.scopes.*')`
- Partial scope strings (e.g., `gmail.compose`) will **never** match
- `ConnectedAccount::hasScope()` performs exact string comparison
- `GmailService::createDraft()` verifies `gmail.compose` scope before API call

### Credential Security

- OAuth refresh tokens are stored in `ConnectedAccount.credentials_json` (encrypted column)
- Test assertions never compare against actual token values
- Credentials are cleared on revocation

### Data Access Control

- `ClientMeetingPolicy` enforces:
  - Owners can view/update their meetings
  - Admins can view/update/delete any meeting
  - Non-owners cannot access other users' meetings
  - Unassigned meetings are visible to all

---

## Troubleshooting

### Common Issues

#### "No AI provider configured"
**Cause:** No API key set for any AI provider.
**Fix:** Set at least one of `OPENROUTER_API_KEY`, `OPENAI_API_KEY`, or `GEMINI_API_KEY` in `.env`.

#### "Jira is not configured"
**Cause:** `JIRA_BASE_URL` is not set or empty.
**Fix:** Set `JIRA_BASE_URL`, `JIRA_EMAIL`, and `JIRA_API_TOKEN` in `.env`.

#### "No refresh token received"
**Cause:** Google didn't return a refresh token on OAuth callback.
**Fix:** The user needs to revoke access at [myaccount.google.com/permissions](https://myaccount.google.com/permissions) and re-authorize.

#### "Authorization failed: invalid session state"
**Cause:** OAuth nonce mismatch — session expired or tampered.
**Fix:** User should retry the authorization flow. Ensure session driver is configured properly.

#### "Authorization failed: user mismatch"
**Cause:** The authenticated user doesn't match the user_id in the OAuth state.
**Fix:** User should log out and log back in, then retry.

#### Calendar scan finds no meetings
**Possible causes:**
1. No `ConnectedAccount` with `calendar.events.readonly` scope
2. `COMPANY_EMAIL_DOMAINS` not set (can't distinguish internal vs external)
3. All meetings match exclude patterns
4. No external attendees on any events

**Debug:** Check `ConnectedAccount` granted_scopes and verify `COMPANY_EMAIL_DOMAINS`.

#### AI returns invalid JSON
**Behavior:** `AiProviderService::completeJson()` automatically retries once with a repair prompt.
**If still failing:** Check AI provider status, increase `AI_MAX_TOKENS`, or try a different model.

### Logs

Meeting agent operations are logged with descriptive context:
- `Google login: nonce mismatch` — OAuth security check failed
- `Google workspace: token exchange failed` — Token exchange with Google API failed
- `Jira API request failed` — Jira connectivity issue

---

## Testing

### Test Structure

```
tests/
├── Unit/MeetingAgent/
│   ├── ConnectedAccountModelTest.php   # hasScope(), needsReconnect(), credentials
│   ├── MeetingPrepModelTest.php        # effectiveSubject/Body priority
│   ├── MeetingFollowUpModelTest.php    # effectiveSubject/Body, action items cast
│   ├── AiProviderServiceTest.php       # Provider selection, JSON retry, API errors
│   ├── JiraServiceTest.php             # JQL search, status grouping, config validation
│   ├── GoogleCalendarServiceTest.php   # Meeting detection rules
│   └── UserHelpersTest.php             # Workspace account, scope checking
├── Feature/MeetingAgent/
│   ├── GmailSafetyTest.php             # CRITICAL: No send methods, scope enforcement
│   ├── GoogleWorkspaceOAuthTest.php    # OAuth flow, nonce, revoke
│   ├── GoogleLoginTest.php             # Login flow, domain validation
│   ├── ClientMeetingPolicyTest.php     # Authorization rules
│   ├── MeetingPrepFlowTest.php         # End-to-end prep generation
│   ├── MeetingFollowUpFlowTest.php     # End-to-end follow-up generation
│   └── CalendarSyncTest.php            # Upsert, dedup, attendee classification
```

### Running Tests

```bash
# Run all meeting agent tests
php artisan test --filter=MeetingAgent

# Run only unit tests
php artisan test tests/Unit/MeetingAgent/

# Run only feature tests
php artisan test tests/Feature/MeetingAgent/

# Run critical safety test
php artisan test --filter=GmailSafetyTest
```

### Test Conventions

- Database tests use `RefreshDatabase` trait with SQLite `:memory:`
- HTTP calls mocked with `Http::fake()`
- Google API objects mocked with PHPUnit mocks
- Authenticated requests use `$this->actingAs($user)`
- No sensitive values (tokens, keys) asserted in test output
