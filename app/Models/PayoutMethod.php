<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PayoutMethod extends Model
{
    use LogsActivity, HasFactory, SoftDeletes;

    /**
     * CỜ ĐỒNG BỘ: Ngăn chặn vòng lặp vô tận khi đồng bộ từ Google Sheets.
     */
    public static bool $syncingFromSheet = false;

    // Tên bảng trong Database (để chắc chắn không lệch)
    protected $table = 'payout_methods';

    // Cho phép lưu các trường này vào Database
    protected $fillable = [
        'name',
        'type',
        'current_balance',
        'exchange_rate',
        'email',
        'password',
        'paypal_account',
        'paypal_password',
        'full_name',
        'dob',
        'ssn',
        'phone',
        'address',
        'auth_code',
        'question_1',
        'answer_1',
        'question_2',
        'answer_2',
        'proxy_type',
        'ip_address',
        'location',
        'isp',
        'browser',
        'device',
        'status',
        'note',
        'is_active', // 🟢 Thêm dòng này để điều khiển được công tắc bật/tắt
    ];

    protected $casts = [
        'password' => 'encrypted',
        'paypal_password' => 'encrypted',
        'ssn' => 'encrypted',
        'auth_code' => 'encrypted',
        'answer_1' => 'encrypted',
        'answer_2' => 'encrypted',
    ];

    // Cấu hình theo dõi toàn bộ các cột được phép điền
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Quan hệ với bảng Logs (để sau này tính toán số dư)
    public function payoutLogs(): HasMany
    {
        return $this->hasMany(PayoutLog::class);
    }

    public function latestPayoutLogs(): HasMany
    {
        return $this->hasMany(PayoutLog::class)->latest()->limit(20);
    }
}
