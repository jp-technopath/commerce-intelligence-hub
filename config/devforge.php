<?php

return [
    'orchestration_enabled' => env('DEVFORGE_ORCHESTRATION_ENABLED', true),
    'queue' => env('DEVFORGE_ORCHESTRATION_QUEUE', 'default'),
    'vm_startup_timeout_seconds' => (int) env('DEVFORGE_VM_STARTUP_TIMEOUT_SECONDS', 600),
    'vm_poll_interval_seconds' => (int) env('DEVFORGE_VM_POLL_INTERVAL_SECONDS', 10),
    'worker_heartbeat_ttl_seconds' => (int) env('DEVFORGE_WORKER_HEARTBEAT_TTL_SECONDS', 45),
    'vm_idle_shutdown_seconds' => (int) env('DEVFORGE_VM_IDLE_SHUTDOWN_SECONDS', 900),
];
