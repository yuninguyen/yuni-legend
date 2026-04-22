<?php

namespace App\Policies;

use App\Models\PayoutLog;
use App\Models\User;

class PayoutLogPolicy
{
    /**
     * Admin và Finance có toàn quyền - không kiểm tra thêm.
     * Staff đi qua từng method riêng.
     */
    public function before(User $user, string $ability): bool|null
    {
        if ($user->isAdmin() || $user->isFinance()) {
            return true;
        }
        return null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PayoutLog $log): bool
    {
        return $log->user_id === $user->id;
    }

    // Staff tạo payout log (gift card redeem / note cho admin xử lý PayPal)
    public function create(User $user): bool
    {
        return true;
    }

    // Staff chỉ sửa record của mình khi chưa completed
    /**
     * Trước đây: return $log->user_id === $user->id;
     * Lỗi: Staff có thể sửa đơn đã 'completed', dẫn đến sai số dư ví.
     */
    public function update(User $user, PayoutLog $log): bool
    {
        return $log->user_id === $user->id && $log->status !== 'completed';
    }

    // Chỉ Admin & Finance xóa — Staff không xóa được
    public function delete(User $user, PayoutLog $log): bool
    {
        return false; // Admin & Finance đã bypass ở before()
    }

    public function restore(User $user, PayoutLog $log): bool
    {
        return false; // Admin & Finance đã bypass ở before()
    }

    public function forceDelete(User $user, PayoutLog $log): bool
    {
        return false; // Admin & Finance đã bypass ở before()
    }
}
