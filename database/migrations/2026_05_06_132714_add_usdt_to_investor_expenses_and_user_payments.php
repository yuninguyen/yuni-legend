<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_expenses', function (Blueprint $table) {
            $table->decimal('amount_usdt', 20, 2)->default(0)->after('amount_usd');
        });

        Schema::table('user_payments', function (Blueprint $table) {
            $table->decimal('total_deductions_usdt', 20, 2)->default(0)->after('total_deductions_usd');
        });
    }

    public function down(): void
    {
        Schema::table('investor_expenses', function (Blueprint $table) {
            $table->dropColumn('amount_usdt');
        });

        Schema::table('user_payments', function (Blueprint $table) {
            $table->dropColumn('total_deductions_usdt');
        });
    }
};
