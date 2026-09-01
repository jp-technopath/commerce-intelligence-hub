<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContainerRuntimeContractTest extends TestCase
{
    public function test_docker_build_excludes_secrets_and_database_exports(): void
    {
        $dockerIgnore = file_get_contents(base_path('.dockerignore'));

        $this->assertIsString($dockerIgnore);
        $this->assertStringContainsString(".env\n", $dockerIgnore);
        $this->assertStringContainsString('production_*.sql', $dockerIgnore);
        $this->assertStringContainsString('vendor', $dockerIgnore);
        $this->assertStringContainsString('node_modules', $dockerIgnore);
    }

    #[DataProvider('runtimeRoles')]
    public function test_entrypoint_supports_each_control_plane_role(string $role, string $command): void
    {
        $entrypoint = file_get_contents(base_path('docker/forge-entrypoint.sh'));

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString("    {$role})", $entrypoint);
        $this->assertStringContainsString($command, $entrypoint);
    }

    public function test_queue_job_is_bounded_and_drains_before_exiting(): void
    {
        $entrypoint = file_get_contents(base_path('docker/forge-entrypoint.sh'));

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('--stop-when-empty', $entrypoint);
        $this->assertStringContainsString('--max-time=', $entrypoint);
    }

    public function test_cloud_sql_proxy_is_keyless_and_cleaned_up_for_short_lived_jobs(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $entrypoint = file_get_contents(base_path('docker/forge-entrypoint.sh'));

        $this->assertIsString($dockerfile);
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('cloud-sql-connectors/cloud-sql-proxy:2.24.1', $dockerfile);
        $this->assertStringContainsString('--psc', $entrypoint);
        $this->assertStringContainsString('trap stop_proxy EXIT INT TERM', $entrypoint);
        $this->assertStringContainsString('run_and_cleanup php artisan', $entrypoint);
        $this->assertStringNotContainsString('credentials-file', $entrypoint);
    }

    public function test_early_bootstrap_handles_an_unavailable_database(): void
    {
        $provider = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

        $this->assertIsString($provider);
        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*if \(\$this->app->runningInConsole\(\) === false \|\| Schema::hasTable\(\'permissions\'\)\)/s',
            $provider,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function runtimeRoles(): array
    {
        return [
            'web' => ['web', 'apache2-foreground'],
            'queue' => ['queue', 'queue:work'],
            'scheduler' => ['scheduler', 'schedule:run'],
            'bootstrap' => ['bootstrap', 'forge:bootstrap-staging-admin'],
            'migrate' => ['migrate', 'migrate --force'],
        ];
    }
}
