<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addIndexIfNotExists('payout_logs', ['asset_type', 'transaction_type', 'status']);
        $this->addIndexIfNotExists('rebate_trackers', ['status']);
    }

    protected function addIndexIfNotExists(string $tableName, array $columns): void
    {
        $connection = Schema::getConnection();
        $drv = $connection->getDriverName();

        foreach ($columns as $column) {
            $indexName = "{$tableName}_{$column}_index";
            $exists = false;

            if ($drv === 'sqlite') {
                $exists = collect($connection->select("PRAGMA index_list('{$tableName}')"))
                    ->contains('name', $indexName);
            } elseif ($drv === 'pgsql') {
                $exists = collect($connection->select(
                    "select 1 from pg_indexes where tablename = ? and indexname = ?",
                    [$tableName, $indexName]
                ))->isNotEmpty();
            } elseif ($drv === 'mysql') {
                $exists = collect($connection->select(
                    "select 1 from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ?",
                    [$tableName, $indexName]
                ))->isNotEmpty();
            }

            if ($exists) {
                continue;
            }

            try {
                Schema::table($tableName, function (Blueprint $table) use ($column) {
                    $table->index($column);
                });
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                if (!str_contains($msg, 'already exists') && !str_contains($msg, 'Duplicate key name')) {
                    throw $e;
                }
            }
        }
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
