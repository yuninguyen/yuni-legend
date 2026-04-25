<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\PayoutLog;
use App\Models\PayoutMethod;
use App\Observers\PayoutLogObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayoutMethodBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_balance_uses_completed_withdrawals_minus_completed_liquidations(): void
    {
        $account = Account::create([
            'platform' => null,
            'password' => 'secret',
        ]);
        $method = PayoutMethod::create([
            'name' => 'Main PayPal',
            'type' => 'paypal_us',
            'current_balance' => 0,
        ]);

        PayoutLog::withoutEvents(function () use ($account, $method) {
            PayoutLog::create([
                'account_id' => $account->id,
                'payout_method_id' => $method->id,
                'asset_type' => 'currency',
                'transaction_type' => 'withdrawal',
                'status' => 'completed',
                'amount_usd' => 150,
                'fee_usd' => 10,
                'net_amount_usd' => 140,
            ]);

            PayoutLog::create([
                'account_id' => $account->id,
                'payout_method_id' => $method->id,
                'asset_type' => 'currency',
                'transaction_type' => 'liquidation',
                'status' => 'completed',
                'amount_usd' => 55,
                'fee_usd' => 0,
                'net_amount_usd' => 55,
            ]);

            PayoutLog::create([
                'account_id' => $account->id,
                'payout_method_id' => $method->id,
                'asset_type' => 'currency',
                'transaction_type' => 'withdrawal',
                'status' => 'pending',
                'amount_usd' => 999,
                'fee_usd' => 0,
                'net_amount_usd' => 999,
            ]);
        });

        (new PayoutLogObserver())->created(PayoutLog::query()->where('status', 'completed')->first());

        $this->assertEqualsWithDelta(85.00, (float) $method->fresh()->current_balance, 0.001);
    }
}
