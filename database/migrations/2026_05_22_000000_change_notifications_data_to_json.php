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
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
        } else {
            Schema::table('notifications', function (Blueprint $table) {
                $table->json('data')->change();
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
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text');
        } else {
            Schema::table('notifications', function (Blueprint $table) {
                $table->text('data')->change();
            });
        }
    }
};
