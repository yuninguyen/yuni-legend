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
        $connection = Schema::getConnection();
        $drv = $connection->getDriverName();

        if ($drv === 'pgsql') {
            // Drop old check constraint if it exists
            DB::statement('ALTER TABLE partner_withdrawals DROP CONSTRAINT IF EXISTS partner_withdrawals_status_check');
            
            // Set the new default value
            DB::statement("ALTER TABLE partner_withdrawals ALTER COLUMN status SET DEFAULT 'new'");
            
            // Add the new check constraint with all values including 'new'
            DB::statement("ALTER TABLE partner_withdrawals ADD CONSTRAINT partner_withdrawals_status_check CHECK (status IN ('new', 'pending', 'processing', 'completed', 'wrong_pass', 'banned'))");
        } else {
            Schema::table('partner_withdrawals', function (Blueprint $table) {
                $table->enum('status', ['new', 'pending', 'processing', 'completed', 'wrong_pass', 'banned'])
                    ->default('new')
                    ->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = Schema::getConnection();
        $drv = $connection->getDriverName();

        if ($drv === 'pgsql') {
            // First update any 'new' statuses to 'pending' to satisfy the old constraint
            DB::table('partner_withdrawals')->where('status', 'new')->update(['status' => 'pending']);

            // Drop check constraint
            DB::statement('ALTER TABLE partner_withdrawals DROP CONSTRAINT IF EXISTS partner_withdrawals_status_check');
            
            // Set the default back to 'pending'
            DB::statement("ALTER TABLE partner_withdrawals ALTER COLUMN status SET DEFAULT 'pending'");
            
            // Add the old check constraint
            DB::statement("ALTER TABLE partner_withdrawals ADD CONSTRAINT partner_withdrawals_status_check CHECK (status IN ('pending', 'processing', 'completed', 'wrong_pass', 'banned'))");
        } else {
            Schema::table('partner_withdrawals', function (Blueprint $table) {
                $table->enum('status', ['pending', 'processing', 'completed', 'wrong_pass', 'banned'])
                    ->default('pending')
                    ->change();
            });
        }
    }
};
