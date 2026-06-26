<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@technopath.com.au'],
            [
                'name'     => 'Technopath Admin',
                'password' => Hash::make('changeme123!'),
            ]
        );

        $this->command->info('Admin user seeded: admin@technopath.com.au / changeme123!');
        $this->command->warn('IMPORTANT: Change the admin password immediately after first login.');
    }
}
