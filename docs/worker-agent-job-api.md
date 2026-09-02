# DevForge worker agent-job API

## Purpose

The worker API is the durable, machine-to-machine channel between Forge and a mapped project VM. It replaces interactive desktop and IAP tunnels in the automated workflow. PostgreSQL stores jobs, callbacks, status changes, and replay records, so queued work survives Forge and worker restarts.

TPF-8 defines the transport and security contract. TPF-10 owns OpenCode and model execution. TPF-19 owns advanced retry, abandoned-lease recovery, and idle-shutdown reconciliation.

## Security boundary

Deploy the API as a private Cloud Run service. Grant `roles/run.invoker` only to approved VM service accounts. Each VM obtains a Google-signed ID token from the metadata server with the API service URL as its audience and sends it in the `Authorization: Bearer` header.

The application verifies the token signature, audience, issuer, expiry, verified email, request timestamp, and request UUID. It then compares the verified email with the immutable worker identity in the Development Request routing snapshot. Cloud Run IAM is the outer boundary; application authorization is the inner project-level boundary.

No service-account keys, user credentials, IAP tunnel, SSH connection, or raw provider credentials are part of this protocol.

## Required request headers

Every endpoint requires:

- `Authorization: Bearer <Google ID token>`
- `X-DevForge-Request-Id: <UUID>`
- `X-DevForge-Timestamp: <ISO-8601 or Unix timestamp>`

Job-specific endpoints also require:

- `X-DevForge-Lease-Token: <opaque claim token>`

The timestamp must be within the configured five-minute window. Reusing a request UUID with the same identity, operation, timestamp, lease, and payload replays the original encrypted response. Reusing it for anything different is rejected and audited.

## Version 1 endpoints

All endpoints are below `/api/internal/v1/worker`:

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/heartbeat` | Register the mapped VM worker and its readiness state |
| POST | `/jobs/claim` | Atomically claim the next eligible job |
| POST | `/jobs/{job}/heartbeat` | Renew an active job lease |
| POST | `/jobs/{job}/progress` | Record bounded structured progress |
| POST | `/jobs/{job}/result` | Store a redacted structured result |
| POST | `/jobs/{job}/failure` | Store a redacted structured failure |
| GET | `/jobs/{job}/cancellation` | Read cancellation state |
| POST | `/jobs/{job}/cancelled` | Acknowledge cancellation |
| POST | `/jobs/{job}/complete` | Complete the job and request human review |

Claiming changes the Development Request to `running`. Completion changes it to `awaiting_approval`; failure and confirmed cancellation move it to their visible terminal states.

## Data minimization and retention

Worker payloads are built from an allowlist: Jira task details, immutable routing context, role, capability tier, workspace, approved context, and output contract. User records, client records, integration credentials, and unrelated Jira fields are not included. Sensitive key names are redacted before results, failures, or audit metadata are stored.

`devforge:prune-worker-api-data` deletes expired encrypted replay responses and removes detailed payload, result, failure, progress-message, and encrypted lease fields from terminal jobs after the configured retention period. Stable identifiers, hashes, states, and append-only audit events remain available.

## Operational configuration

Set `DEVFORGE_WORKER_API_AUDIENCE` to the Cloud Run service URL or approved custom audience. Configure the exact VM service-account email on every active project/environment mapping. A mapping without this identity cannot be activated or dispatched.
