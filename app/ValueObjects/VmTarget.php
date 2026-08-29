<?php

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class VmTarget
{
    public function __construct(
        public string $projectId,
        public string $zone,
        public string $vmName
    ) {
        if (! preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/', $projectId)) {
            throw new InvalidArgumentException('The routing snapshot contains an invalid GCP project ID.');
        }

        if (! preg_match('/^[a-z0-9-]+-[a-z0-9]+[0-9]-[a-z]$/', $zone)) {
            throw new InvalidArgumentException('The routing snapshot contains an invalid GCP zone.');
        }

        if (! preg_match('/^[a-z](?:[-a-z0-9]{0,61}[a-z0-9])?$/', $vmName)) {
            throw new InvalidArgumentException('The routing snapshot contains an invalid VM name.');
        }
    }

    public static function fromRoutingSnapshot(?array $snapshot): self
    {
        $gcp = $snapshot['gcp'] ?? null;

        if (! is_array($gcp)) {
            throw new InvalidArgumentException('The Development Request does not have a valid execution target snapshot.');
        }

        return new self(
            (string) ($gcp['project_id'] ?? ''),
            (string) ($gcp['zone'] ?? ''),
            (string) ($gcp['vm_name'] ?? '')
        );
    }

    public function key(): string
    {
        return hash('sha256', implode('|', [$this->projectId, $this->zone, $this->vmName]));
    }
}
