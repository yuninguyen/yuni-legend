<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutLogExchangeTest extends TestCase
{
    use RefreshDatabase;

    private function createWithdrawal(?User $user = null): PayoutLog
    {
        $user ??= User::factory()->create();

        $account = Account::create([
            'platform' => null,
            'password' => 'secret',
        ]);

        return PayoutLog::create([
            'user_id'          => $user->id,
            'account_id'       => $account->id,
            'asset_type'       => 'currency',
            'transaction_type' => 'withdrawal',
            'status'           => 'completed',
            'amount_usd'       => 100.00,
            'fee_usd'          => 0.00,
            'net_amount_usd'   => 100.00,
        ]);
    }

    // Mirror the exact guard query used in the Exchange action.
    private function findCompletedLiquidation(PayoutLog $parent): ?PayoutLog
    {
        return PayoutLog::query()
            ->where('parent_id', $parent->id)
            ->where('transaction_type', 'liquidation')
            ->where('status', 'completed')
            ->first();
    }

    public function test_guard_blocks_when_completed_liquidation_already_exists(): void
    {
        $withdrawal = $this->createWithdrawal();

        // Simulate a previously completed exchange
        PayoutLog::create([
            'parent_id'        => $withdrawal->id,
            'user_id'          => $withdrawal->user_id,
            'account_id'       => $withdrawal->account_id,
            'asset_type'       => 'currency',
            'transaction_type' => 'liquidation',
            'status'           => 'completed',
            'amount_usd'       => 100.00,
            'fee_usd'          => 0.00,
            'net_amount_usd'   => 100.00,
            'exchange_rate'    => 25000.00,
            'total_vnd'        => 2500000.00,
        ]);

        $existingLiquidation = $this->findCompletedLiquidation($withdrawal);

        $this->assertNotNull($existingLiquidation);
        // Guard condition from the Exchange action
        $this->assertTrue($withdrawal->user_payment_id !== null || $existingLiquidation !== null);
    }

    public function test_guard_blocks_when_record_is_already_settled(): void
    {
        $user = User::factory()->create();
        $withdrawal = $this->createWithdrawal($user);

        $payment = UserPayment::create([
            'user_id'          => $user->id,
            'platform'         => 'Rakuten',
            'transaction_type' => 'Gift Card',
            'total_usd'        => 100.00,
            'exchange_rate'    => 25000.00,
            'total_vnd'        => 2500000.00,
        ]);
        $withdrawal->update(['user_payment_id' => $payment->id]);

        $fresh = $withdrawal->fresh();
        $existingLiquidation = $this->findCompletedLiquidation($fresh);

        $this->assertNotNull($fresh->user_payment_id);
        // Guard condition from the Exchange action
        $this->assertTrue($fresh->user_payment_id !== null || $existingLiquidation !== null);
    }

    public function test_guard_allows_clean_unsettled_record(): void
    {
        $withdrawal = $this->createWithdrawal();

        $existingLiquidation = $this->findCompletedLiquidation($withdrawal);

        $this->assertNull($withdrawal->user_payment_id);
        $this->assertNull($existingLiquidation);
        // Guard condition must be false — exchange should be allowed
        $this->assertFalse($withdrawal->user_payment_id !== null || $existingLiquidation !== null);
    }
}
