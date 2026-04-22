<?php

namespace App\Policies;

use App\Models\PayoutMethod;
use App\Models\User;

class PayoutMethodPolicy
{
    public function before(User $user, string $ability): bool|null
    {
        if ($user->isAdmin() || $user->isFinance()) {
            return true;
        }
        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isFinance();
    }

    public function view(User $user, PayoutMethod $method): bool
    {
        return $user->isAdmin() || $user->isFinance();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, PayoutMethod $method): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, PayoutMethod $method): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, PayoutMethod $method): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, PayoutMethod $method): bool
    {
        return $user->isAdmin();
    }
}
