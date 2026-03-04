<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Email extends Model
{
    public $timestamps = false; // Tắt tự động cập nhật created_at và updated_at

    protected $casts = [
        'email_created_at' => 'date',
    ];

    // Cho phép lưu các cột này vào database
    protected $fillable = [
        'status',
        'email',
        'email_password',
        'recovery_email',
        'two_factor_code',
        'email_created_at',   
        'note',
        'provider',
    ];

    protected static function booted()
    {
        static::creating(function ($emailModel) {
            // Nếu cột provider đang trống, hệ thống sẽ tự bóc tách từ email
            if (empty($emailModel->provider) && !empty($emailModel->email)) {
                $email = $emailModel->email;

                // Lấy phần domain sau dấu @ (ví dụ: gmail.com)
                $domain = substr(strrchr($email, "@"), 1);

                // Lấy tên provider (ví dụ: gmail)
                $providerName = explode('.', $domain)[0];

                // Lưu vào database dưới dạng chữ thường để đồng bộ
                $emailModel->provider = strtolower($providerName);
            }
        });
    }

    // Thiết lập quan hệ: Một Email có thể dùng cho NHIỀU Account Platform
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
