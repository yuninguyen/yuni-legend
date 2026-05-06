<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_expenses', function (Blueprint $table) {
            $table->decimal('amount_usd', 20, 2)->default(0)->after('amount_vnd');
            $table->string('currency')->default('VND')->after('amount_usd'); // VND, USD
        });

        Schema::table('user_payments', function (Blueprint $table) {
            $table->decimal('total_deductions_usd', 20, 2)->default(0)->after('total_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('investor_expenses', function (Blueprint $table) {
            $table->dropColumn(['amount_usd', 'currency']);
        });

        Schema::table('user_payments', function (Blueprint $table) {
            $table->dropColumn('total_deductions_usd');
        });
    }
};
