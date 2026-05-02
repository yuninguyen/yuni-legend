<?php

use App\Models\UserPayment;

UserPayment::all()->each(function($p) {
    $type = $p->transaction_type;
    
    // 1. Remove [LEADER Split] and any name in brackets to get the base string
    $baseType = preg_replace('/\s*\[.*?\]/', '', $type);
    
    // 2. Clean up base string (remove stray parentheses and fix prefix)
    $baseType = str_replace(['PayPal (', 'PayPal - '], 'PayPal VN - ', $baseType);
    $baseType = rtrim($baseType, ' )');
    
    // 3. Rebuild the string
    $newType = $baseType;
    
    // If it's a Leader Split (we know by checking if it originally had LEADER Split OR if it's for a Leader role user?)
    // Actually, just check if it's a leader record in a batch.
    
    // Simplest: If it was originally a Leader Split record, it should have the User's name in brackets.
    // I'll check if this user has a leader-ish role or if another record exists in batch.
    
    $isLeaderRecord = str_contains($p->transaction_type, 'LEADER Split') || str_contains($p->transaction_type, '[Yuni]') || str_contains($p->transaction_type, '[Kevin]');
    
    if ($isLeaderRecord) {
        $newType = $baseType . " [{$p->user->name}]";
    } else {
        $newType = $baseType;
    }
    
    if ($p->transaction_type !== $newType) {
        $p->update(['transaction_type' => $newType]);
    }
});

echo "All existing records cleaned up.\n";
