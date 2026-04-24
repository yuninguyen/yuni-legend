<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

return new class extends Migration {
    public function up(): void
    {
        $email    = env('ADMIN_EMAIL');
        $username = env('ADMIN_USERNAME');
        $password = env('ADMIN_PASSWORD');

        if (!$email || !$password) {
            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => 'Admin',
                'username' => $username ?? 'admin',
                'password' => Hash::make($password),
                'role'     => 'admin',
            ]
        );
    }

    public function down(): void
    {
        $email = env('ADMIN_EMAIL');
        if ($email) {
            User::where('email', $email)->delete();
        }
    }
};
