<?php

return [
    'orchestration_enabled' => env('DEVFORGE_ORCHESTRATION_ENABLED', true),
    'queue' => env('DEVFORGE_ORCHESTRATION_QUEUE', 'default'),
    'vm_startup_timeout_seconds' => (int) env('DEVFORGE_VM_STARTUP_TIMEOUT_SECONDS', 600),
    'vm_poll_interval_seconds' => (int) env('DEVFORGE_VM_POLL_INTERVAL_SECONDS', 10),
    'worker_heartbeat_ttl_seconds' => (int) env('DEVFORGE_WORKER_HEARTBEAT_TTL_SECONDS', 45),
    'vm_idle_shutdown_seconds' => (int) env('DEVFORGE_VM_IDLE_SHUTDOWN_SECONDS', 900),
    'worker_api_audience' => env('DEVFORGE_WORKER_API_AUDIENCE'),
    'worker_api_request_skew_seconds' => (int) env('DEVFORGE_WORKER_API_REQUEST_SKEW_SECONDS', 300),
    'worker_api_request_retention_hours' => (int) env('DEVFORGE_WORKER_API_REQUEST_RETENTION_HOURS', 24),
    'worker_api_max_payload_bytes' => (int) env('DEVFORGE_WORKER_API_MAX_PAYLOAD_BYTES', 262144),
    'agent_job_lease_seconds' => (int) env('DEVFORGE_AGENT_JOB_LEASE_SECONDS', 120),
    'agent_job_detail_retention_days' => (int) env('DEVFORGE_AGENT_JOB_DETAIL_RETENTION_DAYS', 30),
];
