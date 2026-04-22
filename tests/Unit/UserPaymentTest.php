<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createPayment(?User $user = null): UserPayment
    {
        $user ??= User::factory()->create();

        return UserPayment::create([
            'user_id'          => $user->id,
            'platform'         => 'Rakuten',
            'transaction_type' => 'Gift Card',
            'total_usd'        => 100.00,
            'exchange_rate'    => 25000.00,
            'total_vnd'        => 2500000.00,
        ]);
    }

    private function createPayoutLog(UserPayment $payment): PayoutLog
    {
        $account = Account::create([
            'platform' => null,
            'password' => 'secret',
        ]);

        return PayoutLog::create([
            'user_payment_id'  => $payment->id,
            'user_id'          => $payment->user_id,
            'account_id'       => $account->id,
            'asset_type'       => 'currency',
            'transaction_type' => 'withdrawal',
            'status'           => 'completed',
            'amount_usd'       => 100.00,
            'fee_usd'          => 0.00,
            'net_amount_usd'   => 100.00,
        ]);
    }

    public function test_soft_delete_nullifies_user_payment_id_on_linked_logs(): void
    {
        $payment = $this->createPayment();
        $log = $this->createPayoutLog($payment);

        $this->assertNotNull($log->user_payment_id);

        $payment->delete();

        $this->assertNull($log->fresh()->user_payment_id);
    }

    public function test_soft_delete_does_not_physically_remove_payout_logs(): void
    {
        $payment = $this->createPayment();
        $log = $this->createPayoutLog($payment);

        $payment->delete();

        $this->assertDatabaseHas('payout_logs', ['id' => $log->id]);
        $this->assertSoftDeleted('user_payments', ['id' => $payment->id]);
    }

    public function test_force_delete_also_nullifies_user_payment_id(): void
    {
        $payment = $this->createPayment();
        $log = $this->createPayoutLog($payment);

        $payment->forceDelete();

        $this->assertNull($log->fresh()->user_payment_id);
        $this->assertDatabaseMissing('user_payments', ['id' => $payment->id]);
    }
}
