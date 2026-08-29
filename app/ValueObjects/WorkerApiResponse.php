<?php

namespace App\ValueObjects;

final readonly class WorkerApiResponse
{
    public function __construct(
        public array $body,
        public int $status = 200,
        public bool $replayed = false
    ) {}

    public function asReplay(): self
    {
        return new self($this->body, $this->status, true);
    }
}
