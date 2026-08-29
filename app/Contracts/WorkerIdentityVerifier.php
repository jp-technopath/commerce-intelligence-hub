<?php

namespace App\Contracts;

use App\ValueObjects\WorkerIdentity;

interface WorkerIdentityVerifier
{
    public function verify(string $token, string $audience): WorkerIdentity;
}
