<?php

namespace App\Models;

use App\Models\Account;
use App\Models\PayoutMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity; // Bật tính năng Log
use Spatie\Activitylog\LogOptions;          // Tùy chọn Log
use Illuminate\Database\Eloquent\SoftDeletes;

class PayoutLog extends Model
{
    use LogsActivity; // Kích hoạt máy quay cho PayoutLog
    use SoftDeletes;

    /**
     * CỜ ĐỒNG BỘ: Ngăn chặn vòng lặp vô tận khi đồng bộ từ Google Sheets.
     * Dùng static để flag tồn tại xuyên suốt request, kể cả khi Observer nhận
     * một instance mới từ pipeline của Eloquent.
     */
    public static bool $syncingFromSheet = false;

    protected static function booted()
    {
        static::deleting(function ($log) {
            // 🟢 TỰ ĐỘNG XÓA CON: Khi xóa đơn cha (Withdrawal), các dòng con (Liquidation) sẽ bị xóa theo
            $log->children()->delete();
        });

        // Thêm mới: Force delete thì xoá hẳn children
        static::forceDeleting(function ($log) {
            $log->children()->withTrashed()->forceDelete();
        });

        static::restoring(function ($log) {
            // 🟢 TỰ ĐỘNG KHÔI PHỤC: Khi khôi phục đơn cha, các dòng con cũng quay lại
            $log->children()->withTrashed()->restore();
        });
    }
    protected $fillable = [
        // --- Các trường định danh ---
        'user_id',              // 🟢 MỚI: Người thực hiện giao dịch
        'account_id',           // Tài khoản nguồn
        'payout_method_id',     // Ví nhận tiền/Ví bán tiền
        'parent_id',            // 🟢 MỚI: Liên kết dòng Liquidation với Withdrawal gốc
        'user_payment_id',      // 🟢 MỚI: Liên kết dòng Withdrawal với Phiếu lương
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

    protected $casts = [
        'gc_code' => 'encrypted',
        'gc_pin' => 'encrypted',
        'amount_usd' => 'decimal:2',
        'fee_usd' => 'decimal:2',
        'net_amount_usd' => 'decimal:2',
        'boost_percentage' => 'decimal:2',
        'exchange_rate' => 'decimal:2',
        'total_vnd' => 'decimal:0',
    ];

    // Cấu hình theo dõi toàn bộ các cột được phép điền
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // =========================================================================
    // RELATIONS
    // =========================================================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function children(): HasMany
    {
        // Một dòng Rút tiền có thể có nhiều dòng Bán tiền (nếu bạn bán lẻ nhiều lần)
        return $this->hasMany(PayoutLog::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        // Dòng Bán tiền trỏ ngược về dòng Rút tiền gốc
        return $this->belongsTo(PayoutLog::class, 'parent_id');
    }


    // Khai báo mối quan hệ để Filament hiểu
    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(PayoutMethod::class);
    }

    // 🟢 Mối quan hệ: Đơn này nằm trong Phiếu lương nào?
    public function userPayment(): BelongsTo
    {
        return $this->belongsTo(UserPayment::class);
    }

    public function account(): BelongsTo
    {
        // Giả sử Model của bạn là Account (Platform account)
        return $this->belongsTo(Account::class, 'account_id');
    }
}
