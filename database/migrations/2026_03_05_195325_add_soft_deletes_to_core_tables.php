<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('core_tables', function (Blueprint $table) {
            Schema::table('emails', fn(Blueprint $table) => $table->softDeletes());
            Schema::table('accounts', fn(Blueprint $table) => $table->softDeletes());
            Schema::table('rebate_trackers', fn(Blueprint $table) => $table->softDeletes());
            Schema::table('payout_logs', fn(Blueprint $table) => $table->softDeletes());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('core_tables', function (Blueprint $table) {
            Schema::table('emails', fn(Blueprint $table) => $table->dropSoftDeletes());
            Schema::table('accounts', fn(Blueprint $table) => $table->dropSoftDeletes());
            Schema::table('rebate_trackers', fn(Blueprint $table) => $table->dropSoftDeletes());
            Schema::table('payout_logs', fn(Blueprint $table) => $table->dropSoftDeletes());
        });
    }
};
