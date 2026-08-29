<?php

namespace App\ValueObjects;

final readonly class VmReadinessResult
{
    public function __construct(
        public string $state,
        public bool $workerReady,
        public ?string $operationId = null
    ) {}

    public function shouldPoll(): bool
    {
        return ! $this->workerReady && in_array($this->state, ['starting', 'waiting_for_worker'], true);
    }
}
