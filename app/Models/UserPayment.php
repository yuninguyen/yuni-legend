<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity; // Bật tính năng Log
use Spatie\Activitylog\LogOptions;          // Tùy chọn Log

class UserPayment extends Model
{
    use LogsActivity, HasFactory, SoftDeletes; // Kích hoạt "máy quay" và xóa mềm

    protected static function booted()
    {
        static::deleting(function ($payment) {
            if ($payment->isForceDeleting()) {
                return;
            }

            $payment->payoutLogs()->withTrashed()->update(['user_payment_id' => null]);
        });

        // Giữ nguyên hook forceDelete hiện có để các liên kết còn sót lại
        // vẫn được giải phóng trước khi bản ghi bị xóa hẳn.
        static::forceDeleting(function ($payment) {
            $payment->payoutLogs()->withTrashed()->update(['user_payment_id' => null]);
        });
    }

    // Các cột được phép lưu dữ liệu (Mass Assignment)
    protected $fillable = [
        'user_id',
        'platform',
        'transaction_type',
        'total_usd',
        'exchange_rate',
        'payout_rate',
        'payout_percentage',
        'total_vnd',
        'profit_vnd',
        'status', // pending, paid
        'batch_id',
        'asset_group',
        'payment_date',
        'payment_proof',
        'note',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payment_date' => 'datetime',
    ];

    // Cấu hình máy quay: Báo nó theo dõi cái gì
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable() // Theo dõi mọi sự thay đổi của các cột trong $fillable
            ->logOnlyDirty() // Chỉ ghi lại nếu thực sự có sửa đổi
            ->dontSubmitEmptyLogs();
    }

    // 🟢 Mối quan hệ: 1 Phiếu lương thuộc về 1 User
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // 🟢 Mối quan hệ: 1 Phiếu lương chứa NHIỀU đơn Payout Logs con
    public function payoutLogs(): HasMany
    {
        return $this->hasMany(PayoutLog::class);
    }

    // 🟢 ACCESSOR: Tính tổng tiền của cả Batch để hiện trên tiêu đề Group
    public function getTotalVndPayoutForBatchAttribute(): float
    {
        if (empty($this->batch_id))
            return $this->total_vnd;

        return static::where('batch_id', $this->batch_id)->sum('total_vnd');
    }
}
