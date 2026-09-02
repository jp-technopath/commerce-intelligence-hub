# DevForge mapped VM lifecycle

TPF-7 adds the application-side controller for starting and stopping the VM captured in a Development Request's immutable routing snapshot. It does not use IAP tunnels and does not accept a service-account key. In deployed environments, Google Application Default Credentials resolve the attached `devforge-orchestrator` identity created by TPF-6.

## Startup and readiness

1. A routed request moves to `queued`.
2. Forge schedules one unique VM-readiness job for the request.
3. The controller validates the snapshotted GCP project, zone, and VM name, records a stable execution-target key, and moves the request to `starting_vm`.
4. Forge inspects the VM through the Compute Engine API. A terminated VM is started; a running or concurrently starting VM is reused.
5. A running VM moves the request to `waiting_for_worker`.
6. The worker registry must contain a fresh `ready` heartbeat before the controller reports the target ready. TPF-8 will expose the authenticated machine endpoint that records this heartbeat and dispatches the durable agent job.

The startup deadline is stored on the request, so a Forge process restart does not reset the timeout. Provider failures and readiness timeouts move the request to `failed` with a controlled reason and auditable error code. Retrying failed work remains part of TPF-19.

## Safe idle shutdown

The `devforge:shutdown-idle-vms` scheduler evaluates mapped runtime records every minute. It starts the idle timer only when the target has no queued, starting, waiting, running, or cancelling Development Requests. After the configured idle period, it checks Compute Engine and submits a stop operation. A target with active work is never selected for shutdown.

Manual start and stop overrides must be recorded through `VmLifecycleManager::recordManualOverride`. Only a super administrator can record an override, and a manual stop is rejected while active work exists.

## Audit evidence

`vm_lifecycle_actions` is append-only and records startup, reuse, worker readiness, timeouts, stop decisions, manual overrides, actor identity, safe error codes, and GCP operation IDs. Credentials, tokens, environment values, and raw provider responses are never persisted.

## Configuration

- `DEVFORGE_VM_STARTUP_TIMEOUT_SECONDS`
- `DEVFORGE_VM_POLL_INTERVAL_SECONDS`
- `DEVFORGE_WORKER_HEARTBEAT_TTL_SECONDS`
- `DEVFORGE_VM_IDLE_SHUTDOWN_SECONDS`
- `DEVFORGE_ORCHESTRATION_ENABLED`
- `DEVFORGE_ORCHESTRATION_QUEUE`

The queue worker must process the configured orchestration queue. The Laravel scheduler must run once per minute for automatic idle shutdown.
