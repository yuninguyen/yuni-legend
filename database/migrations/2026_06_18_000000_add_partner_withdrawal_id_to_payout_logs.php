<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->change();
            $table->foreignId('partner_withdrawal_id')->nullable()->after('account_id')
                ->constrained('partner_withdrawals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_withdrawal_id');
            $table->foreignId('account_id')->nullable(false)->change();
        });
    }
};
