<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_expenses', function (Blueprint $table) {
            $table->decimal('amount_vnd', 20, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('investor_expenses', function (Blueprint $table) {
            $table->decimal('amount_vnd', 20, 2)->change();
        });
    }
};
