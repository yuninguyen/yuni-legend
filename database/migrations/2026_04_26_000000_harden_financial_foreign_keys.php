<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->replaceForeignKey('payout_logs', 'account_id', 'accounts', 'restrict');
        $this->replaceForeignKey('payout_logs', 'payout_method_id', 'payout_methods', 'restrict');
        $this->replaceForeignKey('user_payments', 'user_id', 'users', 'restrict');
        $this->replaceForeignKey('rebate_trackers', 'account_id', 'accounts', 'restrict');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->replaceForeignKey('payout_logs', 'account_id', 'accounts', 'cascade');
        $this->replaceForeignKey('payout_logs', 'payout_method_id', 'payout_methods', 'cascade');
        $this->replaceForeignKey('user_payments', 'user_id', 'users', 'cascade');
        $this->replaceForeignKey('rebate_trackers', 'account_id', 'accounts', 'cascade');
    }

    private function replaceForeignKey(string $tableName, string $column, string $references, string $onDelete): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($column, $references, $onDelete) {
            $table->dropForeign([$column]);

            $foreign = $table->foreign($column)->references('id')->on($references);

            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'restrict' => $foreign->restrictOnDelete(),
            };
        });
    }
};
