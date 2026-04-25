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
        Schema::create('rebate_trackers', function (Blueprint $table) {
            $table->id();

            // Liên kết với Account (Phải có bảng accounts trước)
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            
            $table->date('transaction_date')->nullable();        // Transaction date
            
            // Thông tin giao dịch
            $table->string('store_name');
            $table->string('order_id')->nullable();
            $table->decimal('order_value', 10, 2)->default(0);
            $table->decimal('cashback_percent', 5, 2)->default(0);
            $table->decimal('rebate_amount', 10, 2)->default(0); // Thêm dòng này để lưu số tiền thực nhận

            // Thông tin vận hành (Như bạn yêu cầu)
            $table->string('device')->nullable(); // Ví dụ: iPhone 13, Profile 1...
            $table->string('state')->nullable();  // Ví dụ: NY, CA, TX...
            $table->text('note')->nullable();     // Để copy/paste info order

            // Trạng thái & Thời gian
            $table->enum('status', ['clicked', 'pending', 'confirmed', 'ineligible', 'missing'])->default('clicked'); // pending, confirmed, rejected
            $table->date('payout_date')->nullable();      // Ngày trả cash

            // Tự động theo dõi User nào nhập transaction
            $table->foreignId('user_id')->constrained('users');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rebate_trackers');
    }
};
