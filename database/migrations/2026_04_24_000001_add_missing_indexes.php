<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->index('asset_type');
            $table->index('transaction_type');
            $table->index('status');
        });

        Schema::table('rebate_trackers', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->dropIndex(['asset_type']);
            $table->dropIndex(['transaction_type']);
            $table->dropIndex(['status']);
        });

        Schema::table('rebate_trackers', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
