<?php

namespace Tests\Feature;

use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FinancialPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_only_their_own_user_payment(): void
    {
        $owner = $this->userWithRole('staff');
        $otherStaff = $this->userWithRole('staff');
        $payment = $this->createPayment($owner);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $payment));
        $this->assertFalse(Gate::forUser($otherStaff)->allows('view', $payment));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $payment));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $payment));
    }

    public function test_finance_can_manage_user_payments_through_policy_bypass(): void
    {
        $finance = $this->userWithRole('finance');
        $payment = $this->createPayment($this->userWithRole('staff'));

        $this->assertTrue(Gate::forUser($finance)->allows('view', $payment));
        $this->assertTrue(Gate::forUser($finance)->allows('update', $payment));
        $this->assertTrue(Gate::forUser($finance)->allows('delete', $payment));
    }

    public function test_staff_cannot_update_completed_payout_logs(): void
    {
        $staff = $this->userWithRole('staff');
        $log = new PayoutLog([
            'user_id' => $staff->id,
            'status' => 'completed',
        ]);

        $this->assertTrue(Gate::forUser($staff)->allows('view', $log));
        $this->assertFalse(Gate::forUser($staff)->allows('update', $log));
        $this->assertFalse(Gate::forUser($staff)->allows('delete', $log));
    }

    public function test_staff_can_update_their_own_uncompleted_payout_logs_only(): void
    {
        $staff = $this->userWithRole('staff');
        $otherStaff = $this->userWithRole('staff');
        $log = new PayoutLog([
            'user_id' => $staff->id,
            'status' => 'pending',
        ]);

        $this->assertTrue(Gate::forUser($staff)->allows('update', $log));
        $this->assertFalse(Gate::forUser($otherStaff)->allows('update', $log));
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function createPayment(User $user): UserPayment
    {
        return UserPayment::create([
            'user_id' => $user->id,
            'platform' => 'Rakuten',
            'transaction_type' => 'Gift Card',
            'total_usd' => 100,
            'exchange_rate' => 25000,
            'total_vnd' => 2500000,
        ]);
    }
}
