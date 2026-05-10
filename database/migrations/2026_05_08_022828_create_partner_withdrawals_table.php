<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform');
            $table->string('email');
            $table->text('email_password');
            $table->string('recovery_email')->nullable();
            $table->text('two_fa')->nullable();
            $table->text('platform_password');
            $table->decimal('amount_usd', 12, 2)->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'wrong_pass', 'banned'])->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_withdrawals');
    }
};
