<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Models\InvestorExpense;
use App\Models\UserPayment;
use Spatie\Activitylog\Traits\LogsActivity; // Bật tính năng Log
use Spatie\Activitylog\LogOptions;          // Tùy chọn Log

class User extends Authenticatable implements FilamentUser
{
    use LogsActivity; // Kích hoạt "máy quay" cho User

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Định nghĩa mối quan hệ: Một User có nhiều Accounts
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'user_id');
    }

    public function rebateTrackers(): HasMany
    {
        return $this->hasMany(RebateTracker::class, 'user_id');
    }

    public function payoutLogs(): HasMany
    {
        return $this->hasMany(PayoutLog::class, 'user_id');
    }

    public function investorExpenses(): HasMany
    {
        return $this->hasMany(InvestorExpense::class);
    }

    public function userPayments(): HasMany
    {
        return $this->hasMany(UserPayment::class, 'user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isFinance(): bool
    {
        return $this->role === 'finance';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff' || $this->role === 'operator';
    }

    // Thêm hàm này để khóa cổng Admin Panel
    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        // Cho phép Admin, Finance và Staff đăng nhập vào Panel
        return in_array($this->role, ['admin', 'staff', 'operator', 'finance']);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username', // Thêm dòng này
        'role', // Thêm dòng này
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Cấu hình theo dõi toàn bộ các cột được phép điền
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
