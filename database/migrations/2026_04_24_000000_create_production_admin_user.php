<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::updateOrCreate(
            ['email' => 'yuninguyen.it@gmail.com'],
            [
                'name' => 'Admin',
                'username' => 'yuninguyen',
                'password' => Hash::make('@Yuni2026'),
                'role' => 'admin',
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        User::where('email', 'yuninguyen.it@gmail.com')->delete();
    }
};
