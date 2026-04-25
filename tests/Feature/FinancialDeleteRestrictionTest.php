<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PayoutLog;
use App\Models\PayoutMethod;
use App\Models\RebateTracker;
use App\Models\User;
use App\Models\UserPayment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FinancialDeleteRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_force_delete_is_restricted_when_payout_logs_exist(): void
    {
        Queue::fake();

        $account = $this->createAccount();
        $method = $this->createPayoutMethod();
        $log = PayoutLog::create([
            'account_id' => $account->id,
            'payout_method_id' => $method->id,
            'asset_type' => 'currency',
            'transaction_type' => 'withdrawal',
            'status' => 'completed',
            'amount_usd' => 100,
            'fee_usd' => 0,
            'net_amount_usd' => 100,
        ]);

        try {
            $account->forceDelete();
            $this->fail('Account force delete should be restricted while payout logs exist.');
        } catch (QueryException) {
            $this->assertDatabaseHas('accounts', ['id' => $account->id]);
            $this->assertDatabaseHas('payout_logs', ['id' => $log->id]);
        }
    }

    public function test_payout_method_force_delete_is_restricted_when_payout_logs_exist(): void
    {
        Queue::fake();

        $account = $this->createAccount();
        $method = $this->createPayoutMethod();
        $log = PayoutLog::create([
            'account_id' => $account->id,
            'payout_method_id' => $method->id,
            'asset_type' => 'currency',
            'transaction_type' => 'withdrawal',
            'status' => 'completed',
            'amount_usd' => 100,
            'fee_usd' => 0,
            'net_amount_usd' => 100,
        ]);

        try {
            $method->forceDelete();
            $this->fail('Payout method force delete should be restricted while payout logs exist.');
        } catch (QueryException) {
            $this->assertDatabaseHas('payout_methods', ['id' => $method->id]);
            $this->assertDatabaseHas('payout_logs', ['id' => $log->id]);
        }
    }

    public function test_user_delete_is_restricted_when_user_payments_exist(): void
    {
        $user = User::factory()->create();
        $payment = UserPayment::create([
            'user_id' => $user->id,
            'platform' => 'Rakuten',
            'transaction_type' => 'Gift Card',
            'total_usd' => 100,
            'exchange_rate' => 25000,
            'total_vnd' => 2500000,
        ]);

        try {
            $user->delete();
            $this->fail('User delete should be restricted while user payments exist.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertDatabaseHas('user_payments', ['id' => $payment->id]);
        }
    }

    public function test_account_force_delete_is_restricted_when_rebate_trackers_exist(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = $this->createAccount();
        $tracker = RebateTracker::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'store_name' => 'Rakuten',
            'order_value' => 200,
            'cashback_percent' => 10,
            'status' => 'confirmed',
        ]);

        try {
            $account->forceDelete();
            $this->fail('Account force delete should be restricted while rebate trackers exist.');
        } catch (QueryException) {
            $this->assertDatabaseHas('accounts', ['id' => $account->id]);
            $this->assertDatabaseHas('rebate_trackers', ['id' => $tracker->id]);
        }
    }

    private function createAccount(): Account
    {
        return Account::create([
            'platform' => null,
            'password' => 'secret',
        ]);
    }

    private function createPayoutMethod(): PayoutMethod
    {
        return PayoutMethod::create([
            'name' => 'Main PayPal',
            'type' => 'paypal_us',
            'current_balance' => 0,
        ]);
    }
}
