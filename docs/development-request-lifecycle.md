# Development Request Lifecycle

## Overview

The Development Request lifecycle is an **internal orchestration state machine** that tracks the progression of a development or investigation request through the system's processing pipeline. It is **distinct from and separate from** Jira delivery status, PM system status, or the state of related PR/deployment activities.

## Purpose

Development Request lifecycle models the system's internal workflow state:

- **Draft** → Request is being composed or pending initial submission
- **Queued** → Request is queued for processing
- **Starting VM** → System is initializing a VM or environment
- **Waiting for Worker** → VM is ready; system is waiting for a worker to pick up the request
- **Running** → Active processing/development is underway
- **Awaiting Approval** → Processing complete; awaiting human approval
- **Approved** → Changes approved; ready for merge/deployment
- **Changes Requested** → Feedback provided; request reverted for rework
- **Rejected** → Request rejected; terminal state
- **Cancelling** → Cancellation has been requested and cleanup is in progress
- **Cancelled** → Request cancelled; terminal state
- **Failed** → Processing failed; terminal state
- **Completed** → Request completed successfully; terminal state

## Relationship to Other Status Fields

### Jira/PM Delivery Status
The `jira_snapshot` and `pm_work_item_id` fields track metadata about an associated Jira issue or PM work item, but **the development request lifecycle is independent**. A request can be in any lifecycle state regardless of Jira status.

**Example:** A request can be in `running` state while its associated Jira issue is still `In Progress` or `In Review`.

### Investigation/Development/QA/PR/Deployment State
These represent logical phases or milestone events in the broader development workflow. The development request **internal state** coordinates system actions but does not directly model these higher-level phases.

## Terminal States and Reopening

Terminal states (**rejected**, **cancelled**, **failed**, **completed**) are immutable—no transitions are allowed from these states. If a request reaches a terminal state and work must resume, a **new linked request** is created with the `parent_request_id` pointing to the original request.

Reopening is **structural only** in this task (the foreign key relationship); the actual reopen operation is out of scope.

## Transition Rules

All transitions are validated against an explicit transition map. Only the following transitions are allowed:

```
draft → queued | cancelled
queued → starting_vm | cancelling
starting_vm → waiting_for_worker | failed | cancelling
waiting_for_worker → running | failed | cancelling
running → awaiting_approval | failed | cancelling
awaiting_approval → approved | changes_requested | rejected
changes_requested → queued | cancelled
approved → completed | changes_requested
cancelling → cancelled | failed
```

Terminal states have no outward transitions.

## Audit and Idempotency

All state transitions are recorded in `development_request_status_histories` (append-only). Each history entry captures:

- **old_state** and **new_state**: State before and after the transition
- **actor_user_id**: The user who triggered the transition (if human)
- **actor_type** and **actor_label**: For system transitions (e.g., `actor_type = 'system'`, `actor_label = 'VM initialization timeout'`)
- **reason**: Explanation for the state change
- **correlation_identifier**: Tracing identifier for the system action
- **idempotency_key**: Ensures exactly-once processing for retries
- **metadata**: Additional context-specific data
- **created_at**: Timestamp of the transition

### Durable Idempotency

To ensure safe retries and duplicate-request resilience:

1. The system locks the request row before transition
2. Existing history is checked for an idempotency key match
3. Exact retries (same key, same from/to state) return the existing history entry
4. Reused keys with different payloads are rejected
5. The update and history creation are atomic

PostgreSQL's unique constraint on `(development_request_id, idempotency_key)` enforces durability, even with null values.

## Authorization

Transitions requiring human decisions are guarded by permissions:

- **Approval/rejection/changes-requested**: Require `development_requests.approve` permission
- **Cancellation by user**: Require `development_requests.cancel` permission
- **Submission/resubmission by owner**: Require `development_requests.update` (or `create` if consistent with existing patterns)
- **System transitions**: Use `actor_type = 'system'` with an `actor_label` (no user actor required)

## Integration Points

### Webhook Callbacks
Webhooks from external systems (VM, worker, runner) typically trigger system-side state transitions. These should use:

- **actor_type**: `'webhook'` or `'callback'`
- **actor_label**: Source system label (e.g., `'VM initialization service'`)
- **correlation_identifier**: Webhook run ID or event ID
- **idempotency_key**: Webhook event ID (to prevent duplicate processing)

Callbacks are safe even if retried or received out of order, because:
- Idempotency keys prevent duplicate transitions
- Invalid transitions are rejected with clear errors
- History is append-only; state can be reconstructed

### Request Context
The request stores only non-secret, non-deployment context:

- **client_id**, **project_id**: Scope of the request
- **environment_key**: Target environment identifier (string, not a foreign key)
- **source_type** / **source_id**: Where the request originated
- **priority**: Priority level
- **correlation_identifier**: Unique identifier for tracing

## Implementation Notes

- The `DevelopmentRequestStatus` enum (PHP backed enum) enforces type safety for state values
- State transitions use a static transition map for explicit validation
- The service handles locking, idempotency, and audit automatically
- Tests cover the full transition matrix, terminal immutability, and idempotent retry semantics
