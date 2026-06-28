<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_withdrawals', function (Blueprint $table) {
            $table->text('platform_password')->nullable()->change();
            $table->text('two_fa')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('partner_withdrawals', function (Blueprint $table) {
            $table->text('platform_password')->nullable(false)->change();
            $table->text('two_fa')->nullable(false)->change();
        });
    }
};
