<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapStagingAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_or_resets_only_the_staging_admin_from_runtime_secrets(): void
    {
        Config::set('app.env', 'staging');
        $this->app->instance('env', 'staging');
        putenv('DEVFORGE_STAGING_ADMIN_EMAIL=admin+staging@technopath.co');
        putenv('DEVFORGE_STAGING_ADMIN_PASSWORD=0123456789abcdef0123456789abcdef');

        try {
            $this->artisan('forge:bootstrap-staging-admin')
                ->expectsOutput('Staging administrator ready: admin+staging@technopath.co')
                ->assertExitCode(0);

            $admin = User::query()->where('email', 'admin+staging@technopath.co')->sole();

            $this->assertTrue($admin->is_admin);
            $this->assertTrue(Hash::check('0123456789abcdef0123456789abcdef', $admin->password));
        } finally {
            putenv('DEVFORGE_STAGING_ADMIN_EMAIL');
            putenv('DEVFORGE_STAGING_ADMIN_PASSWORD');
        }
    }
}
