<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sử dụng Raw SQL để cập nhật enum an toàn trong MySQL
        DB::statement("ALTER TABLE partner_withdrawals MODIFY COLUMN status ENUM('new', 'pending', 'processing', 'completed', 'wrong_pass', 'banned') DEFAULT 'new'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE partner_withdrawals MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'wrong_pass', 'banned') DEFAULT 'pending'");
    }
};
