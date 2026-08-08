<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessJiraWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class JiraWebhookController extends Controller
{
    /**
     * Handle incoming Jira webhooks idempotently.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $webhookEvent = $payload['webhookEvent'] ?? $request->header('X-Atlassian-Webhook-Event');
        $eventIdentifier = $request->header('X-Atlassian-Webhook-Identifier')
            ?? md5(($payload['timestamp'] ?? '') . json_encode($payload));

        $cacheKey = "jira_webhook_processed:{$eventIdentifier}";

        // Idempotency check: Ignore duplicate deliveries within 24 hours
        if (Cache::has($cacheKey)) {
            return response()->json(['status' => 'ignored', 'reason' => 'duplicate_delivery']);
        }

        Cache::put($cacheKey, true, now()->addHours(24));

        // Dispatch background job for async processing
        ProcessJiraWebhookJob::dispatch($webhookEvent, $payload);

        return response()->json(['status' => 'accepted']);
    }
}
