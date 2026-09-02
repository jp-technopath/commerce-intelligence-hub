<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class BootstrapStagingAdminCommand extends Command
{
    protected $signature = 'forge:bootstrap-staging-admin';

    protected $description = 'Create or reset the protected Cloud Run staging administrator';

    public function handle(): int
    {
        if (! app()->environment('staging')) {
            $this->error('The staging administrator can only be bootstrapped in the staging environment.');

            return self::FAILURE;
        }

        $email = trim((string) env('DEVFORGE_STAGING_ADMIN_EMAIL'));
        $password = (string) env('DEVFORGE_STAGING_ADMIN_PASSWORD');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('DEVFORGE_STAGING_ADMIN_EMAIL must be a valid email address.');

            return self::FAILURE;
        }

        if (strlen($password) < 16) {
            $this->error('DEVFORGE_STAGING_ADMIN_PASSWORD must be at least 16 characters.');

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Staging Administrator',
                'password' => Hash::make($password),
                'is_admin' => true,
            ],
        );

        // Never print or log the password. The caller owns secure delivery.
        $this->info("Staging administrator ready: {$email}");

        return self::SUCCESS;
    }
}
