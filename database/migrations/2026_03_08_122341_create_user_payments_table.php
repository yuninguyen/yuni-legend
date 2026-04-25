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
        Schema::create('user_payments', function (Blueprint $table) {
            $table->id();
            // Liên kết với bảng users
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('platform'); // Ví dụ: Rakuten, Join Honey...
            $table->string('transaction_type'); // Ví dụ: Gift Card, PayPal US...

            $table->decimal('total_usd', 15, 2); // Tổng USD gom từ các nick
            $table->decimal('exchange_rate', 15, 2); // Tỷ giá tại thời điểm chốt
            $table->decimal('total_vnd', 20, 2); // Thành tiền VND cuối cùng

            $table->string('status')->default('pending'); // Trạng thái: pending, paid
            $table->string('payment_proof')->nullable(); // Lưu đường dẫn ảnh bill chuyển khoản
            $table->text('note')->nullable(); // Ghi chú thêm (thưởng/phạt)

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_payments');
    }
};
