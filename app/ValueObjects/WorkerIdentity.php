<?php

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class WorkerIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public string $audience,
        public int $issuedAt,
        public int $expiresAt
    ) {
        if ($subject === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The verified worker identity is incomplete.');
        }
    }
}
