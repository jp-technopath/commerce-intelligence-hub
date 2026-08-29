<?php

namespace App\Services;

use App\Contracts\ComputeEngineClient;
use App\Exceptions\ComputeEngineException;
use App\ValueObjects\VmTarget;
use Google\Client as GoogleClient;
use Google\Service\Compute;
use Throwable;

class GoogleComputeEngineClient implements ComputeEngineClient
{
    private ?Compute $compute = null;

    public function status(VmTarget $target): string
    {
        try {
            $instance = $this->service()->instances->get(
                $target->projectId,
                $target->zone,
                $target->vmName
            );

            return strtoupper((string) $instance->getStatus());
        } catch (Throwable $exception) {
            throw $this->controlledException('inspect', $exception);
        }
    }

    public function start(VmTarget $target): ?string
    {
        try {
            $operation = $this->service()->instances->start(
                $target->projectId,
                $target->zone,
                $target->vmName
            );

            return $operation->getName() ?: ($operation->getId() ? (string) $operation->getId() : null);
        } catch (Throwable $exception) {
            throw $this->controlledException('start', $exception);
        }
    }

    public function stop(VmTarget $target): ?string
    {
        try {
            $operation = $this->service()->instances->stop(
                $target->projectId,
                $target->zone,
                $target->vmName
            );

            return $operation->getName() ?: ($operation->getId() ? (string) $operation->getId() : null);
        } catch (Throwable $exception) {
            throw $this->controlledException('stop', $exception);
        }
    }

    private function service(): Compute
    {
        if ($this->compute !== null) {
            return $this->compute;
        }

        $client = new GoogleClient;
        $client->useApplicationDefaultCredentials();
        $client->setScopes([Compute::CLOUD_PLATFORM]);

        return $this->compute = new Compute($client);
    }

    private function controlledException(string $action, Throwable $exception): ComputeEngineException
    {
        $providerCode = $exception->getCode() !== 0 ? (string) $exception->getCode() : null;

        return new ComputeEngineException(
            "Compute Engine could not {$action} the mapped VM.",
            $providerCode,
            $exception
        );
    }
}
