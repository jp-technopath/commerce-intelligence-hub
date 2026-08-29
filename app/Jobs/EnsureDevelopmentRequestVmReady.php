<?php

namespace App\Jobs;

use App\Models\DevelopmentRequest;
use App\Services\VmLifecycleManager;
use App\Services\AgentJobQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnsureDevelopmentRequestVmReady implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 120;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $developmentRequestId)
    {
        $this->onQueue(config('devforge.queue'));
    }

    public function handle(VmLifecycleManager $manager, AgentJobQueue $jobs): void
    {
        $request = DevelopmentRequest::query()->find($this->developmentRequestId);

        if ($request === null || $request->isTerminalState()) {
            return;
        }

        $result = $manager->ensureRequestVmReady($request);

        if ($result->workerReady) {
            $jobs->enqueueForRequest($request->freshOrFail());

            return;
        }

        if ($result->shouldPoll()) {
            $this->release(config('devforge.vm_poll_interval_seconds'));
        }
    }

    public function uniqueId(): string
    {
        return "development-request-vm:{$this->developmentRequestId}";
    }
}
