<?php

use App\Models\UserPayment;
use App\Models\PayoutLog;

UserPayment::where('asset_group', 'paypal')->each(function($p) {
    // 1. Tìm PayoutLog liên quan để biết loại PayPal
    // Thử tìm trực tiếp qua user_payment_id trước
    $log = PayoutLog::where('user_payment_id', $p->id)->with('payoutMethod')->first();
    
    // Nếu không thấy (có thể là Leader record), tìm theo batch_id
    if (!$log && $p->batch_id) {
        $log = PayoutLog::whereHas('userPayment', function($q) use ($p) {
            $q->where('batch_id', $p->batch_id);
        })->with('payoutMethod')->first();
    }
    
    if ($log) {
        $mType = $log->payoutMethod?->type ?? 'unknown';
        $prefix = 'PayPal';
        if ($mType === 'paypal_us') $prefix = 'PayPal US';
        elseif ($mType === 'paypal_vn') $prefix = 'PayPal VN';
        
        $oldType = $p->transaction_type;
        // Thay thế "PayPal VN - " hoặc "PayPal US - " hoặc "PayPal - " bằng prefix đúng
        $newType = preg_replace('/^PayPal( VN| US)? - /', "{$prefix} - ", $oldType);
        
        if ($oldType !== $newType) {
            $p->update(['transaction_type' => $newType]);
        }
    }
});

echo "Correction completed.\n";
