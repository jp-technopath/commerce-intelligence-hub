<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DevelopmentRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SeedStagingFixturesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_an_explicit_staging_flag(): void
    {
        Config::set('app.env', 'staging');
        putenv('DEVFORGE_STAGING_FIXTURES_ENABLED=false');

        try {
            $this->artisan('forge:seed-staging-fixtures')
                ->expectsOutput('Set DEVFORGE_STAGING_FIXTURES_ENABLED=true for this one-time staging operation.')
                ->assertExitCode(1);
        } finally {
            putenv('DEVFORGE_STAGING_FIXTURES_ENABLED');
        }
    }

    public function test_it_loads_synthetic_qa_records_when_explicitly_enabled(): void
    {
        Config::set('app.env', 'staging');
        putenv('DEVFORGE_STAGING_FIXTURES_ENABLED=true');
        putenv('DEVFORGE_STAGING_ADMIN_EMAIL=admin+staging@technopath.co');

        User::factory()->create([
            'name' => 'Staging Administrator',
            'email' => 'admin+staging@technopath.co',
            'is_admin' => true,
        ]);

        try {
            $this->artisan('forge:seed-staging-fixtures')
                ->expectsOutput('Synthetic Commerce Hub staging fixtures are ready.')
                ->assertExitCode(0);

            $this->assertSame(1, Client::query()->where('name', 'Technopath Internal')->count());
            $this->assertSame(1, Project::query()->where('code', 'commerce-hub')->count());
            $this->assertSame(6, DevelopmentRequest::query()->where('correlation_identifier', 'like', 'staging-fixture-%')->count());
        } finally {
            putenv('DEVFORGE_STAGING_FIXTURES_ENABLED');
            putenv('DEVFORGE_STAGING_ADMIN_EMAIL');
        }
    }
}
