<?php

namespace App\Console\Commands;

use Database\Seeders\CommerceHubStagingFixtureSeeder;
use Illuminate\Console\Command;

class SeedStagingFixturesCommand extends Command
{
    protected $signature = 'forge:seed-staging-fixtures';

    protected $description = 'Load guarded synthetic QA fixtures into the staging database';

    public function handle(): int
    {
        if (! app()->environment('staging')) {
            $this->error('Staging fixtures can only be loaded in the staging environment.');

            return self::FAILURE;
        }

        if (! filter_var(env('DEVFORGE_STAGING_FIXTURES_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('Set DEVFORGE_STAGING_FIXTURES_ENABLED=true for this one-time staging operation.');

            return self::FAILURE;
        }

        $this->callSilently('db:seed', [
            '--class' => CommerceHubStagingFixtureSeeder::class,
            '--force' => true,
        ]);

        // The fixture seeder deliberately emits no record contents or credentials.
        $this->info('Synthetic Commerce Hub staging fixtures are ready.');

        return self::SUCCESS;
    }
}
