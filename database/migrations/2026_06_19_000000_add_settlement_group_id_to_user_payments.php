<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_payments', function (Blueprint $table) {
            $table->string('settlement_group_id')->nullable()->after('batch_id');
        });

        // Backfill: preserve current linkage for existing rows before batch_id can be
        // reassigned further by the "Batch Selected" grouping action.
        DB::table('user_payments')
            ->whereNull('settlement_group_id')
            ->update(['settlement_group_id' => DB::raw('batch_id')]);
    }

    public function down(): void
    {
        Schema::table('user_payments', function (Blueprint $table) {
            $table->dropColumn('settlement_group_id');
        });
    }
};
