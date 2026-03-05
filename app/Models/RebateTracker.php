<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity; // Bật tính năng Log
use Spatie\Activitylog\LogOptions;          // Tùy chọn Log
use Illuminate\Database\Eloquent\SoftDeletes;

class RebateTracker extends Model
{

    use LogsActivity; // Kích hoạt "máy quay" cho RebateTracker

    use SoftDeletes;

    protected $fillable = [
        'account_id',
        'transaction_date',
        'store_name',
        'order_id',
        'order_value',
        'cashback_percent',
        'rebate_amount',
        'device',
        'state',
        'note',
        'status',
        'payout_date',
        'user_id',
        'detail_transaction',

    ];

    // Cấu hình máy quay: Báo nó theo dõi cái gì
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable() // Theo dõi mọi sự thay đổi của các cột trong $fillable
            ->logOnlyDirty() // Chỉ ghi lại nếu thực sự có sửa đổi
            ->dontSubmitEmptyLogs();
    }

    //Tự động tính tiền khi Lưu
    protected static function booted()
    {
        static::saving(function ($model) {
            // Tự động tính tiền: Tiền nhận = Giá trị đơn * (% / 100)
            $model->rebate_amount = ($model->order_value ?? 0) * (($model->cashback_percent ?? 0) / 100);
        });
    }

    // Liên kết với tài khoản cashback
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    // Liên kết với người dùng hệ thống (Người nhập đơn)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

}
