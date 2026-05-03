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

    protected $casts = [
        'order_value'      => 'decimal:2',
        'cashback_percent' => 'decimal:2',
        'rebate_amount'    => 'decimal:2',
        'transaction_date' => 'date',
        'payout_date'      => 'date',
    ];

    protected $fillable = [
        'batch_id',
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
            // 1. Nếu status là không đủ điều kiện (ineligible) -> set 0
            if ($model->status === 'ineligible') {
                $model->rebate_amount = 0;
                return;
            }

            // 2. Nếu tài khoản bị banned -> set 0
            $account = $model->account;
            if ($account && in_array('banned', (array)($account->status ?? []))) {
                $model->rebate_amount = 0;
                return;
            }

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

    public function getAccountBatchGroupAttribute()
    {
        return $this->account_id . '_' . ($this->batch_id ?? 'uncategorized');
    }
}
