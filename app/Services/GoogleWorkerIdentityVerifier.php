<?php

namespace App\Services;

use App\Contracts\WorkerIdentityVerifier;
use App\Exceptions\WorkerAuthenticationException;
use App\ValueObjects\WorkerIdentity;
use Google\Auth\AccessToken;
use Throwable;

class GoogleWorkerIdentityVerifier implements WorkerIdentityVerifier
{
    private const ALLOWED_ISSUERS = [
        AccessToken::OAUTH2_ISSUER,
        AccessToken::OAUTH2_ISSUER_HTTPS,
    ];

    public function verify(string $token, string $audience): WorkerIdentity
    {
        try {
            $claims = (new AccessToken)->verify($token, [
                'audience' => $audience,
                'throwException' => true,
            ]);
        } catch (Throwable $exception) {
            throw new WorkerAuthenticationException('The worker identity token is invalid.', previous: $exception);
        }

        if (! is_array($claims) || ! in_array($claims['iss'] ?? null, self::ALLOWED_ISSUERS, true)) {
            throw new WorkerAuthenticationException('The worker identity token issuer is invalid.');
        }

        if (($claims['email_verified'] ?? false) !== true && ($claims['email_verified'] ?? null) !== 'true') {
            throw new WorkerAuthenticationException('The worker identity email is not verified.');
        }

        try {
            return new WorkerIdentity(
                subject: (string) ($claims['sub'] ?? ''),
                email: strtolower((string) ($claims['email'] ?? '')),
                audience: (string) ($claims['aud'] ?? ''),
                issuedAt: (int) ($claims['iat'] ?? 0),
                expiresAt: (int) ($claims['exp'] ?? 0),
            );
        } catch (Throwable $exception) {
            throw new WorkerAuthenticationException('The verified worker identity is incomplete.', previous: $exception);
        }
    }
}
