<?php

namespace Tests\Unit;

use App\Models\PayoutMethod;
use App\Services\GoogleSheetService;
use App\Services\GoogleSyncService;
use Tests\TestCase;

class GoogleSheetServiceTest extends TestCase
{
    public function test_format_payout_method_normalizes_balance_without_calling_google_api(): void
    {
        $sheetService = $this->getMockBuilder(GoogleSheetService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $syncService = new GoogleSyncService($sheetService);

        $method = new PayoutMethod([
            'name' => 'Main PayPal',
            'type' => 'paypal_us',
            'current_balance' => 123.456,
            'is_active' => true,
        ]);
        $method->id = 10;

        $row = $syncService->formatPayoutMethod($method);

        $this->assertSame('10', $row[0]);
        $this->assertSame('PAYPAL US', $row[2]);
        $this->assertSame('123.46', $row[3]);
    }
}
