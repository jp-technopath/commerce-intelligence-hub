<?php

namespace App\Services;

use App\Models\WorkerApiSecurityEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkerSecurityAuditor
{
    public function record(
        string $eventType,
        string $reasonCode,
        ?string $workerIdentity = null,
        ?string $requestIdentifier = null,
        ?string $operation = null,
        ?array $metadata = null
    ): void {
        try {
            WorkerApiSecurityEvent::query()->create([
                'event_type' => $eventType,
                'worker_identity' => $workerIdentity,
                'request_identifier' => $requestIdentifier,
                'operation' => $operation,
                'reason_code' => $reasonCode,
                'metadata' => $metadata,
            ]);
        } catch (Throwable) {
            Log::warning('Worker API security event could not be persisted.', [
                'event_type' => $eventType,
                'reason_code' => $reasonCode,
                'operation' => $operation,
            ]);
        }
    }
}
