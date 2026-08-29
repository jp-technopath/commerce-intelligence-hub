<?php

namespace App\Exceptions;

use RuntimeException;

class ComputeEngineException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
