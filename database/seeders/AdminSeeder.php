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
        $name = config('app.initial_admin_name', 'Admin');
        $email = config('app.initial_admin_email');
        $password = config('app.initial_admin_password');

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
