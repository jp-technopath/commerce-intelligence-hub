<?php

namespace App\Console\Commands;

use App\Services\VmLifecycleManager;
use Illuminate\Console\Command;

class ShutdownIdleVmsCommand extends Command
{
    protected $signature = 'devforge:shutdown-idle-vms';

    protected $description = 'Stop mapped Development Request VMs after their configured idle period';

    public function handle(VmLifecycleManager $manager): int
    {
        $results = collect($manager->shutdownIdleTargets())->countBy()->sortKeys();

        if ($results->isEmpty()) {
            $this->info('No mapped VM runtime records require evaluation.');

            return self::SUCCESS;
        }

        $results->each(fn (int $count, string $outcome) => $this->line("{$outcome}: {$count}"));

        return $results->has('stop_failed') ? self::FAILURE : self::SUCCESS;
    }
}
