<?php

namespace App\Exceptions;

use RuntimeException;

class AgentJobOperationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
