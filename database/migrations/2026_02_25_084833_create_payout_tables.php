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
        // 1. Tạo bảng quản lý các ví/tài khoản nhận tiền
        Schema::create('payout_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->index(); // paypal_us, paypal_vn, bank // 🟢 Thêm index để lọc nhanh loại ví
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->timestamps();
        });

        // 2. Tạo bảng quản lý giao dịch rút/bán tiền
        Schema::create('payout_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            // Liên kết với bảng accounts (Platform) và bảng payout_methods (Ví nhận)
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('payout_method_id')->constrained()->restrictOnDelete();

            $table->string('asset_type'); // paypal, gift_card
            $table->string('gc_brand')->nullable();
            $table->string('transaction_type'); // withdrawal, liquidation

            $table->decimal('amount_usd', 15, 2);
            $table->decimal('fee_usd', 15, 2)->default(0);
            $table->decimal('net_amount_usd', 15, 2);

            $table->decimal('exchange_rate', 15, 2)->nullable();
            $table->decimal('total_vnd', 15, 2)->nullable();

            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Phải drop bảng PayoutLogs trước vì nó chứa khóa ngoại trỏ tới PayoutMethods
        Schema::dropIfExists('payout_logs');
        Schema::dropIfExists('payout_methods');
    }
};
