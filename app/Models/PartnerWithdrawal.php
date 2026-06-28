<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Account;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PartnerWithdrawal extends Model
{
    use LogsActivity;

    protected $fillable = [
        'partner_id',
        'account_id',
        'platform',
        'email',
        'email_password',
        'recovery_email',
        'two_fa',
        'platform_password',
        'amount_usd',
        'status',
        'assigned_to',
        'note',
    ];

    protected $casts = [
        'email_password'    => 'encrypted',
        'two_fa'            => 'encrypted',
        'platform_password' => 'encrypted',
        'amount_usd'        => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
