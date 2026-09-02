<?php

namespace App\Http\Middleware;

use App\Contracts\WorkerIdentityVerifier;
use App\Exceptions\WorkerAuthenticationException;
use App\Services\WorkerSecurityAuditor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateWorkerRequest
{
    public function __construct(
        private readonly WorkerIdentityVerifier $verifier,
        private readonly WorkerSecurityAuditor $auditor
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $audience = trim((string) config('devforge.worker_api_audience'));
        if ($audience === '') {
            $this->audit($request, 'configuration_error', 'worker_api_not_configured');
            return $this->error('worker_api_not_configured', 'Worker API authentication is not configured.', 503);
        }

        $maxBytes = (int) config('devforge.worker_api_max_payload_bytes');
        if (($request->server('CONTENT_LENGTH') ?? 0) > $maxBytes) {
            $this->audit($request, 'request_rejected', 'payload_too_large');
            return $this->error('payload_too_large', 'The request payload exceeds the allowed size.', 413);
        }

        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            $this->audit($request, 'authentication_failed', 'authentication_required');
            return $this->error('authentication_required', 'A Google worker identity token is required.', 401);
        }

        try {
            $identity = $this->verifier->verify($token, $audience);
        } catch (WorkerAuthenticationException) {
            $this->audit($request, 'authentication_failed', 'invalid_worker_identity');
            return $this->error('invalid_worker_identity', 'The worker identity could not be verified.', 401);
        } catch (Throwable) {
            $this->audit($request, 'authentication_error', 'worker_identity_unavailable');
            return $this->error('worker_identity_unavailable', 'Worker authentication is temporarily unavailable.', 503);
        }

        $requestIdentifier = (string) $request->header('X-DevForge-Request-Id');
        if (! Str::isUuid($requestIdentifier)) {
            $this->audit($request, 'request_rejected', 'invalid_request_identifier', $identity->email);
            return $this->error('invalid_request_identifier', 'X-DevForge-Request-Id must be a UUID.', 422);
        }

        $timestamp = $this->parseTimestamp((string) $request->header('X-DevForge-Timestamp'));
        if ($timestamp === null) {
            $this->audit($request, 'request_rejected', 'invalid_request_timestamp', $identity->email);
            return $this->error('invalid_request_timestamp', 'X-DevForge-Timestamp must be an ISO-8601 or Unix timestamp.', 422);
        }

        $allowedSkew = (int) config('devforge.worker_api_request_skew_seconds');
        if (abs(now()->timestamp - $timestamp->timestamp) > $allowedSkew) {
            $this->audit($request, 'request_rejected', 'expired_request', $identity->email);
            return $this->error('expired_request', 'The worker request timestamp is outside the allowed window.', 401);
        }

        $request->attributes->set('worker_identity', $identity);
        $request->attributes->set('worker_request_identifier', $requestIdentifier);
        $request->attributes->set('worker_requested_at', $timestamp);

        $response = $next($request);
        if ($response->getStatusCode() >= 400) {
            $body = json_decode((string) $response->getContent(), true);
            $reasonCode = (string) data_get(
                $body,
                'error.code',
                'http_'.$response->getStatusCode()
            );
            $this->audit(
                $request,
                $reasonCode === 'request_replay_conflict'
                    ? 'replay_rejected'
                    : 'request_rejected',
                $reasonCode,
                $identity->email
            );
        }

        return $response;
    }

    private function parseTimestamp(string $value): ?CarbonImmutable
    {
        try {
            if ($value !== '' && ctype_digit($value)) {
                return CarbonImmutable::createFromTimestampUTC((int) $value);
            }

            return $value === '' ? null : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function audit(
        Request $request,
        string $eventType,
        string $reasonCode,
        ?string $workerIdentity = null
    ): void {
        $requestIdentifier = (string) $request->header('X-DevForge-Request-Id');
        $this->auditor->record(
            $eventType,
            $reasonCode,
            $workerIdentity,
            Str::isUuid($requestIdentifier) ? $requestIdentifier : null,
            $request->route()?->getName() ?: $request->path()
        );
    }
}
