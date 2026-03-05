<?php

namespace App\Models;

use App\Models\Account;
use App\Models\PayoutMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutLog extends Model
{
    protected $fillable = [
        // --- Các trường định danh ---
        'user_id',              // 🟢 MỚI: Người thực hiện giao dịch
        'account_id',           // Tài khoản nguồn
        'payout_method_id',     // Ví nhận tiền/Ví bán tiền
        'parent_id',            // 🟢 MỚI: Liên kết dòng Liquidation với Withdrawal gốc

        // --- Phân loại & Trạng thái ---
        'asset_type',           // 'currency' hoặc 'gift_card'
        'transaction_type',     // 🟢 Withdrawal / Liquidation (Dùng để tính Balance)
        'status',               // 'pending', 'hold', 'completed'

        // --- Thông tin Gift Card (Nếu có) ---
        'gc_brand',
        'gc_code',
        'gc_pin',

        // --- Các con số tài chính ---
        'amount_usd',           // Số tiền gốc (Gross)
        'fee_usd',              // Phí giao dịch
        'boost_percentage',     // % Thưởng thêm (nếu có)
        'net_amount_usd',       // Số tiền thực nhận sau phí
        'exchange_rate',        // Tỷ giá (dùng khi Liquidation)
        'total_vnd',            // Tổng tiền Việt thu về (tương đương amount_vnd)

        // --- Thông tin thêm ---
        'note',
    ];

    protected static function booted()
    {

        // Logic đồng bộ Cha-Con & Google Sheets (Chạy mỗi khi nhấn Save/Create)
        static::saved(function ($payoutLog) {
            // KIỂM TRA: Nếu là dòng con (liquidation)
            if ($payoutLog->transaction_type === 'liquidation' && $payoutLog->parent_id) {
                $parent = $payoutLog->parent;

                if ($parent) {
                    // 🟢 A. CẬP NHẬT WEBSITE: Chỉ update cha khi tỷ giá hoặc tổng tiền ở con thay đổi
                    if ($payoutLog->wasChanged(['exchange_rate', 'total_vnd']) || $payoutLog->wasRecentlyCreated) {
                        $parent->updateQuietly([
                            'exchange_rate' => $payoutLog->exchange_rate,
                            'total_vnd' => $payoutLog->total_vnd,
                        ]);

                        // 🟢 B. SYNC GOOGLE SHEETS (Dòng Cha): Đẩy bản cập nhật của cha lên sheet
                        // dispatch(new \App\Jobs\SyncPayoutToSheets($parent));
                    }
                }
            }

            // YNC GOOGLE SHEETS (Dòng hiện tại): Luôn đẩy dòng vừa thao tác lên sheet
            // dispatch(new \App\Jobs\SyncPayoutToSheets($payoutLog));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function children()
    {
        // Một dòng Rút tiền có thể có nhiều dòng Bán tiền (nếu bạn bán lẻ nhiều lần)
        return $this->hasMany(PayoutLog::class, 'parent_id');
    }

    public function parent()
    {
        // Dòng Bán tiền trỏ ngược về dòng Rút tiền gốc
        return $this->belongsTo(PayoutLog::class, 'parent_id');
    }


    // Khai báo mối quan hệ để Filament hiểu
    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(PayoutMethod::class);
    }

    public function account(): BelongsTo
    {
        // Giả sử Model của bạn là Account (Platform account)
        return $this->belongsTo(Account::class, 'account_id');
    }
}
