<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $name = env('INITIAL_ADMIN_NAME', 'Admin');
        $email = env('INITIAL_ADMIN_EMAIL');
        $password = env('INITIAL_ADMIN_PASSWORD');

        if (!$email || !$password) {
            $this->command->warn('Missing INITIAL_ADMIN_EMAIL or INITIAL_ADMIN_PASSWORD environment variables. Skipping admin creation.');
            return;
        }

        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Admin user '{$email}' secured.");
    }
}
