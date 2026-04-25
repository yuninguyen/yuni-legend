<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\RebateTracker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RebateTrackerCashbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebate_amount_is_calculated_from_order_value_and_cashback_percent(): void
    {
        Queue::fake();

        $tracker = RebateTracker::create([
            'account_id' => $this->createAccount()->id,
            'user_id' => User::factory()->create()->id,
            'store_name' => 'Rakuten',
            'order_value' => 250,
            'cashback_percent' => 8,
            'status' => 'confirmed',
        ]);

        $this->assertSame('20.00', $tracker->fresh()->rebate_amount);
    }

    public function test_ineligible_rebate_is_zeroed(): void
    {
        Queue::fake();

        $tracker = RebateTracker::create([
            'account_id' => $this->createAccount()->id,
            'user_id' => User::factory()->create()->id,
            'store_name' => 'Rakuten',
            'order_value' => 250,
            'cashback_percent' => 8,
            'status' => 'ineligible',
        ]);

        $this->assertSame('0.00', $tracker->fresh()->rebate_amount);
    }

    public function test_banned_account_rebate_is_zeroed(): void
    {
        Queue::fake();

        $tracker = RebateTracker::create([
            'account_id' => $this->createAccount(['banned'])->id,
            'user_id' => User::factory()->create()->id,
            'store_name' => 'Rakuten',
            'order_value' => 250,
            'cashback_percent' => 8,
            'status' => 'confirmed',
        ]);

        $this->assertSame('0.00', $tracker->fresh()->rebate_amount);
    }

    /**
     * @param array<int, string> $status
     */
    private function createAccount(array $status = ['active']): Account
    {
        return Account::create([
            'platform' => null,
            'password' => 'secret',
            'status' => $status,
        ]);
    }
}
