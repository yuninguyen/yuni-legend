<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Email;
use App\Models\PayoutLog;
use App\Models\PayoutMethod;
use App\Models\RebateTracker;
use App\Models\Brand;
use App\Models\Platform;
use App\Models\User;
use App\Filament\Resources\Traits\HasUsStates;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GoogleSyncService
{
    // Dùng Trait HasUsStates đã có sẵn trong project thay vì
    // khai báo lại $usStates 2 lần bên trong formatAccount() và formatTracker().
    use HasUsStates;

    protected GoogleSheetService $sheetService;

    public function __construct(GoogleSheetService $sheetService)
    {
        $this->sheetService = $sheetService;
    }

    /**
     * Helper: Format ngày an toàn, trả về 'N/A' nếu null hoặc lỗi.
     */
    protected function formatDate($date, string $format = 'd/m/Y'): string
    {
        if (!$date)
            return 'N/A';
        try {
            return Carbon::parse($date)->format($format);
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    /**
     * Chuyển đổi ngày từ định dạng d/m/Y (Sheet) sang Y-m-d (Database)
     */
    protected function parseDate(?string $date): ?string
    {
        if (!$date || $date === 'N/A') return null;

        try {
            // Thử parse theo định dạng d/m/Y H:i:s hoặc d/m/Y
            if (str_contains($date, ':')) {
                return Carbon::createFromFormat('d/m/Y H:i:s', trim($date))->format('Y-m-d H:i:s');
            }
            return Carbon::createFromFormat('d/m/Y', trim($date))->format('Y-m-d');
        } catch (\Exception $e) {
            // Nếu lỗi format, thử parse tự động bằng Carbon
            try {
                return Carbon::parse($date)->format('Y-m-d');
            } catch (\Exception $e2) {
                return null;
            }
        }
    }

    /**
     * Helper: Chuyển mảng status thô (của Account) thành chuỗi hiển thị.
     *
     * ✅ FIX #8: Account::$casts đã cast 'status' => 'array', nên $record->status
     * LUÔN LÀ array. Không cần json_decode() thủ công nữa.
     */
    protected function mapAccountStatuses(mixed $rawStatuses): string
    {
        $statusArray = (array) ($rawStatuses ?? []);

        $mapped = array_map(function (string $status): string {
            return match ($status) {
                'used' => 'In Use',
                'limited' => 'PayPal Limited',
                'linked' => 'Linked PayPal',
                'unlinked' => 'Unlinked PayPal',
                'not_linked' => 'Not Linked to PayPal',
                'no_paypal_needed' => 'No PayPal Required',
                default => ucfirst(str_replace('_', ' ', $status)),
            };
        }, array_filter($statusArray));

        return implode(' → ', $mapped);
    }

    /**
     * Helper: Chuyển mã tiểu bang thành chuỗi "TX - Texas".
     */
    protected function formatState(?string $state): string
    {
        if (!$state)
            return 'N/A';
        // HasUsStates khai báo $usStates là public static array nên dùng self::
        return "{$state} - " . (self::$usStates[$state] ?? $state);
    }

    // =========================================================================
    // 1. ACCOUNTS
    // =========================================================================

    public static array $accountHeaders = [
        'ID',
        'Email Address',
        'Email Password',
        'Recovery Email',
        '2FA/Code',
        'Note (Email)',
        'Status (Email)',
        'Platform',
        'Platform Password',
        'State',
        'Device',
        'Create Date',
        'Status',
        'Owner',
        'Note',
        'Device Linked PayPal',
        'Date Linked PayPal',
        'Personal Information',
        'Last Sync'
    ];

    public function formatAccount(Account $record): array
    {
        $rawEmailStatus = $record->email?->status;
        $emailStatusLabel = match ($rawEmailStatus) {
            'active' => 'Live',
            'disabled' => 'Disabled',
            'locked' => 'Locked',
            null => 'N/A',
            default => ucfirst((string) $rawEmailStatus),
        };

        return [
            $record->id,
            $record->email?->email ?? 'N/A',
            $record->email?->email_password ?? 'N/A',
            $record->email?->recovery_email ?? 'N/A',
            $record->email?->two_factor_code ?? 'N/A',
            $record->email?->note ?? 'N/A',
            $emailStatusLabel,
            $record->platform,
            $record->password,
            $this->formatState($record->state),    // ✅ Dùng helper thay vì inline $usStates
            $record->device ?? 'N/A',
            $this->formatDate($record->account_created_at),
            $this->mapAccountStatuses($record->status), // ✅ Dùng helper
            $record->user?->name ?? 'N/A',
            $record->note ?? '',
            $record->device_linked_paypal ?? 'N/A',
            $this->formatDate($record->paypal_linked_at),
            $record->paypal_info ?? 'N/A',
            now(config('app.timezone'))->format('d/m/Y H:i:s'),
        ];
    }

    public function syncAccounts($records = null, ?string $spreadsheetId = null): array
    {
        if ($records instanceof Builder) {
            $records = $records->with(['email', 'user'])->get();
        } elseif ($records === null) {
            $records = Account::with(['email', 'user'])->get();
        }

        $grouped = collect($records)->groupBy(fn($r) => $r->platform ?: 'General');

        $totalUpdated = 0;
        $totalAppended = 0;

        foreach ($grouped as $platform => $items) {
            $targetTab = ucfirst($platform) . '_Accounts';
            $rows = $items->map(fn($r) => $this->formatAccount($r))->values()->toArray();

            $this->sheetService->createSheetIfNotExist($targetTab, $spreadsheetId);
            $result = $this->sheetService->upsertRows($rows, $targetTab, self::$accountHeaders, $spreadsheetId);

            $this->sheetService->formatColumnsAsClip($targetTab, 5, 6, $spreadsheetId);   // Note (Email)
            $this->sheetService->formatColumnsAsClip($targetTab, 14, 15, $spreadsheetId); // Platform Note
            $this->sheetService->formatColumnsAsClip($targetTab, 17, 18, $spreadsheetId); // Personal Information

            $totalUpdated += $result['updated'];
            $totalAppended += $result['appended'];
        }

        return ['updated' => $totalUpdated, 'appended' => $totalAppended, 'tabs' => $grouped->count()];
    }

    // =========================================================================
    // 2. EMAILS
    // =========================================================================

    public static array $emailHeaders = [
        'ID',
        'Status',
        'Email Address',
        'Password',
        'Recovery Email',
        '2FA Code',
        'Date Create',
        'Provider',
        'Usage',
        'Platforms',
        'Note'
    ];

    public function formatEmail(Email $record, array $platforms_map = []): array
    {
        // 1. Capitalize Provider (gmail -> Gmail)
        $provider = $record->provider ? ucfirst($record->provider) : 'Other';

        // 2. Calculate Usage (Count of associated accounts)
        $usage = $record->accounts->count() > 0 ? (string) $record->accounts->count() : 'N/A';

        // 3. Map Platform slugs to full names for Platforms column
        $platforms = $record->accounts->pluck('platform')
            ->map(fn($s) => $platforms_map[$s] ?? $s)
            ->unique()->implode(', ') ?: 'N/A';

        return [
            $record->id,
            match ($record->status) {
                'active' => 'Live',
                'disabled' => 'Disabled',
                'locked' => 'Locked',
                default => ucfirst((string) $record->status),
            },
            $record->email,
            $record->email_password,
            $record->recovery_email,
            $record->two_factor_code,
            $this->formatDate($record->email_created_at),
            $provider,
            $usage,
            $platforms,
            $record->note,
        ];
    }

    public function syncEmails($records = null, ?string $sheetName = null, ?string $spreadsheetId = null): array
    {
        if ($records instanceof Builder) {
            $records = $records->with(['accounts'])->get();
        } elseif ($records === null) {
            // Eager load accounts and their nested info to avoid N+1 and get platforms
            $records = Email::with(['accounts'])->get();
        }

        $targetTab = $sheetName ?: 'Emails';
        // Hoist Platform lookup outside the loop to avoid N+1 queries
        $platforms_map = Platform::pluck('name', 'slug')->toArray();
        $rows = collect($records)->map(fn($r) => $this->formatEmail($r, $platforms_map))->values()->toArray();

        $this->sheetService->createSheetIfNotExist($targetTab, $spreadsheetId);
        $result = $this->sheetService->upsertRows($rows, $targetTab, self::$emailHeaders, $spreadsheetId);


        // 🎨 Format Status colors (Live, Disabled, Locked)
        $statusIdx = array_search('Status', self::$emailHeaders);
        $this->sheetService->applyFormattingWithRules($targetTab, $statusIdx, [
            'Live' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85], // Light Green
            'Disabled' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],  // Light Red
            'Locked' => ['red' => 0.9, 'green' => 0.4, 'blue' => 0.4],  // Deep Red
        ], $spreadsheetId);

        // ✂️ Column clipping (Keep it neat)
        $this->sheetService->formatColumnsAsClip($targetTab, 2, 4, $spreadsheetId);   // Email Address, Password
        $this->sheetService->formatColumnsAsClip($targetTab, 9, 11, $spreadsheetId);  // Platforms, Note

        return $result;
    }

    /**
     * ✅ CHIỀU 2: SHEET -> WEB
     * Đồng bộ dữ liệu từ Google Sheet (Tab Emails) về Database.
     * Dựa trên ID để tìm và cập nhật bản ghi hiện có.
     */
    public function importEmails(?string $sheetName = null, ?string $spreadsheetId = null): array
    {
        $sheetName = $sheetName ?: 'Emails';
        // Đọc toàn bộ dữ liệu (bao gồm cả Header)
        $rows = $this->sheetService->readSheet('A:K', $sheetName, $spreadsheetId);
        
        $updated = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;

        if (empty($rows)) {
            return ['updated' => 0, 'created' => 0, 'failed' => 0];
        }

        // Bỏ qua hàng tiêu đề
        array_shift($rows);

        foreach ($rows as $index => $row) {
            // Mapping dữ liệu theo cấu trúc Tab Emails:
            // 0: ID, 1: Status, 2: Email, 3: Password, 4: Recovery Email, 5: 2FA Code, ...
            $emailRaw = trim($row[2] ?? '');

            // Nếu cột Email trống thì bỏ qua
            if (empty($emailRaw) || !str_contains($emailRaw, '@')) {
                $skipped++;
                continue;
            }

            try {
                // 2. Tìm hoặc Tạo mới dựa trên Email
                $emailRecord = Email::where('email', $emailRaw)->first();
                $isNew = false;

                if (!$emailRecord) {
                    $emailRecord = new Email();
                    $emailRecord->email = $emailRaw;
                    $isNew = true;
                }

                // 3. Mapping dữ liệu:
                // Status mapping
                $emailRecord->status = $this->sanitizeStatus($row[1] ?? 'active');

                // Password
                if (!empty($row[3])) $emailRecord->email_password = trim($row[3]);

                // Recovery Email
                if (isset($row[4])) $emailRecord->recovery_email = trim($row[4]) ?: null;

                // 2FA Code
                if (isset($row[5])) $emailRecord->two_factor_code = trim($row[5]) ?: null;

                // Date Create
                if (!empty($row[6])) {
                    $emailRecord->email_created_at = $this->parseDate($row[6]);
                }

                // Note (giả sử cột 10 là Note)
                if (isset($row[10])) $emailRecord->note = trim($row[10]);

                $emailRecord->save();

                if ($isNew) {
                    $created++;
                } else {
                    $updated++;
                }

            } catch (\Exception $e) {
                Log::error("Import Email Row Error at index {$index}: " . $e->getMessage());
                $failed++;
            }
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skipped
        ];
    }

    // =========================================================================
    // 3. PAYOUT METHODS
    // =========================================================================

    public static array $payoutMethodHeaders = [
        'ID',
        'Wallet Name',
        'Method Type',
        'Current Balance (USD)',
        'Email Address',
        'Email Password',
        'PayPal Account',
        'PayPalpassword',
        'Authenticator Code',
        'Full Name',
        'Date of Birth',
        'SSN / Tax ID',
        'Phone Number',
        'Full Address',
        'Question Security 1',
        'Answer 1',
        'Question Security 2',
        'Answer 2',
        'Proxy Type',
        'IP Address',
        'Location',
        'ISP (Network Provider)',
        'Browser Name',
        'Device',
        'Status',
        'Note',
        'Trạng thái kích hoạt',
        'Last sync'
    ];

    public function formatPayoutMethod(PayoutMethod $record): array
    {
        return [
            (string) $record->id,
            (string) $record->name,
            (string) strtoupper(str_replace('_', ' ', $record->type)),
            (string) number_format((float) ($record->current_balance ?? 0), 2, '.', ''),
            (string) ($record->email ?? 'N/A'),
            (string) ($record->password ?? 'N/A'),
            (string) ($record->paypal_account ?? 'N/A'),
            (string) ($record->paypal_password ?? 'N/A'),
            (string) ($record->auth_code ?? 'N/A'),
            (string) ($record->full_name ?? 'N/A'),
            $this->formatDate($record->dob),
            (string) ($record->ssn ?? 'N/A'),
            (string) ($record->phone ?? 'N/A'),
            (string) ($record->address ?? 'N/A'),
            (string) ($record->question_1 ?? 'N/A'),
            (string) ($record->answer_1 ?? 'N/A'),
            (string) ($record->question_2 ?? 'N/A'),
            (string) ($record->answer_2 ?? 'N/A'),
            (string) ($record->proxy_type ?? 'N/A'),
            (string) ($record->ip_address ?? 'N/A'),
            (string) ($record->location ?? 'N/A'),
            (string) ($record->isp ?? 'N/A'),
            (string) ($record->browser ?? 'N/A'),
            (string) ($record->device ?? 'N/A'),
            (string) ucwords((string) $record->status ?: 'N/A'),
            (string) ($record->note ?? ''),
            (string) ($record->is_active ? 'On' : 'Off'),
            now(config('app.timezone'))->format('d/m/Y H:i'),
        ];
    }

    public function syncPayoutMethods($records = null): array
    {
        if ($records instanceof Builder) {
            $records = $records->get();
        } elseif ($records === null) {
            $records = PayoutMethod::all();
        }

        $targetTab = 'Payout_Methods';
        $rows = collect($records)->map(fn($r) => $this->formatPayoutMethod($r))->values()->toArray();

        $this->sheetService->createSheetIfNotExist($targetTab);
        $result = $this->sheetService->upsertRows($rows, $targetTab, self::$payoutMethodHeaders);

        $statusIdx = array_search('Status', self::$payoutMethodHeaders);
        $this->sheetService->applyFormattingWithRules($targetTab, $statusIdx, [
            'Active' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85],
            'Limited' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],
            'Permanently Limited' => ['red' => 0.9, 'green' => 0.5, 'blue' => 0.5],
            'Restored' => ['red' => 0.8, 'green' => 0.8, 'blue' => 1.0],
        ]);

        $this->sheetService->formatColumnsAsClip($targetTab, 4, 8);
        $this->sheetService->formatColumnsAsClip($targetTab, 13, 14);
        $this->sheetService->formatColumnsAsClip($targetTab, 25, 26);

        return $result;
    }

    // =========================================================================
    // 4. PAYOUT LOGS
    // =========================================================================

    public static array $payoutLogHeaders = [
        'ID',
        'Date',
        'Email',
        'Platform',
        'Wallet',
        'Asset type',
        'Gift Card Brand',
        'Card number',
        'PIN',
        'Transaction type',
        'Amount',
        'Fee',
        'Boost (%)',
        'Net USD',
        'Rate',
        'VND',
        'Status',
        'Note'
    ];

    /**
     * ✅ FIX #6: Nhận $platformNames từ ngoài (đã query 1 lần) thay vì
     * gọi Platform::where() mỗi lần format → tránh N+1 query.
     */
    public function formatPayoutLog(PayoutLog $record, array $platformNames = []): array
    {
        $platformName = $platformNames[$record->account?->platform]
            ?? strtoupper($record->account?->platform ?? 'N/A');

        return [
            (string) $record->id,
            $this->formatDate($record->created_at, 'd/m/Y H:i'),
            (string) ($record->account?->email?->email ?? 'N/A'),
            (string) $platformName,
            (string) ($record->payoutMethod?->name ?? ($record->asset_type === 'gift_card' ? 'In-Hand' : 'N/A')),
            (string) strtoupper(str_replace('_', ' ', $record->asset_type ?? 'N/A')),
            (string) ucwords(str_replace('_', ' ', $record->gc_brand ?? 'N/A')),
            (string) ($record->gc_code ?? 'N/A'),
            (string) ($record->gc_pin ?? 'N/A'),
            (string) ucfirst($record->transaction_type ?? 'N/A'),
            (string) number_format((float) ($record->amount_usd ?? 0), 2, '.', ''),
            (string) number_format((float) ($record->fee_usd ?? 0), 2, '.', ''),
            (string) ($record->boost_percentage ?? 0) . '%',
            (string) number_format((float) ($record->net_amount_usd ?? 0), 2, '.', ''),
            (string) number_format((float) ($record->exchange_rate ?? 0), 0, '.', ','),
            (string) number_format((float) ($record->total_vnd ?? 0), 0, '.', ','),
            (string) ucfirst((string) $record->status),
            (string) ($record->note ?? ''),
        ];
    }

    public function syncPayoutLogs($records = null): array
    {
        if ($records instanceof Builder) {
            $records = $records->with(['account.email', 'payoutMethod', 'user'])->get();
        } elseif ($records === null) {
            $records = PayoutLog::with(['account.email', 'payoutMethod', 'user'])->orderBy('created_at', 'desc')->get();
        } elseif ($records instanceof \Illuminate\Support\Collection || is_array($records)) {
            $records = Collection::make($records);
            $records->load(['account.email', 'payoutMethod']);
        }

        // ✅ FIX #6: Query Platform 1 lần, truyền vào format loop
        $platformNames = Platform::pluck('name', 'slug')->toArray();

        $targetTab = 'Payout_Logs';
        $rows = collect($records)
            ->map(fn($r) => $this->formatPayoutLog($r, $platformNames))
            ->values()->toArray();

        $this->sheetService->createSheetIfNotExist($targetTab);
        $result = $this->sheetService->upsertRows($rows, $targetTab, self::$payoutLogHeaders);

        // 🎨 Format Status colors (Pending, Completed, Rejected)
        $statusIdx = array_search('Status', self::$payoutLogHeaders);
        $this->sheetService->applyFormattingWithRules($targetTab, $statusIdx, [
            'Pending' => ['red' => 1.0, 'green' => 0.9, 'blue' => 0.6], // Yellowish
            'Completed' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85], // Green
            'Rejected' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],  // Red
        ]);

        // ✂️ Column clipping
        $this->sheetService->formatColumnsAsClip($targetTab, 7, 9);   // Card number, PIN
        $this->sheetService->formatColumnsAsClip($targetTab, 17, 18); // Note

        return $result;
    }

    // =========================================================================
    // 5. REBATE TRACKERS
    // =========================================================================

    public static array $trackerHeaders = [
        'ID',
        'Email Address',
        'Password',
        'Platform',
        'User',
        'Account Status Tracking',
        'Transaction Date',
        'Store Name',
        'Order ID',
        'Order Value ($)',
        'Cashback Percent (%)',
        'Rebate Amount ($)',
        'Status',
        'Payout Date',
        'Device',
        'State',
        'Note',
        'Detail Transaction'
    ];

    public function formatTracker(RebateTracker $record): array
    {
        // ✅ FIX #8: $record->account->status luôn là array (đã cast)
        $statusString = $this->mapAccountStatuses($record->account?->status);

        return [
            $record->id,
            $record->account?->email?->email ?? 'N/A',
            $record->account?->password ?? 'N/A',
            $record->account?->platform ?? 'N/A',
            $record->user?->name ?? 'N/A',
            $statusString ?: 'N/A',
            $this->formatDate($record->transaction_date, 'Y-m-d'),
            $record->store_name ?? 'N/A',
            $record->order_id ?? 'N/A',
            $record->order_value ?? 0,
            $record->cashback_percent ?? 0,
            $record->rebate_amount ?? 0,
            match ($record->status) {
                'pending' => 'Pending',
                'confirmed' => 'Confirmed',
                'ineligible' => 'Ineligible',
                'missing' => 'Missing',
                'clicked' => 'Clicked / Ordered',
                default => ucfirst(trim((string) $record->status ?: 'N/A')),
            },
            $this->formatDate($record->payout_date, 'Y-m-d'),
            $record->device ?? 'N/A',
            $this->formatState($record->state),  // ✅ Dùng helper
            $record->note ?? 'N/A',
            $record->detail_transaction ?? 'N/A',
        ];
    }

    public function syncTrackers($records = null): array
    {
        if ($records instanceof Builder) {
            $records = $records->with(['account.email', 'user'])->get();
        } elseif ($records === null) {
            $records = RebateTracker::with(['account.email', 'user'])->get();
        } elseif ($records instanceof \Illuminate\Support\Collection || is_array($records)) {
            $records = Collection::make($records);
            $records->load(['account.email', 'user']);
        }

        $recordsCollection = collect($records);

        $allTab = 'All_Rebate_Tracker';
        $allRows = $recordsCollection->map(fn($t) => $this->formatTracker($t))->values()->toArray();
        $this->sheetService->createSheetIfNotExist($allTab);
        $this->sheetService->upsertRows($allRows, $allTab, self::$trackerHeaders);
        $this->applyTrackerFormatting($allTab);

        $grouped = $recordsCollection->groupBy(fn($t) => $t->account?->platform ?: 'General');

        $totalUpdated = 0;
        $totalAppended = 0;

        foreach ($grouped as $platform => $items) {
            $targetTab = ucfirst($platform) . '_Tracker';
            $rows = $items->map(fn($t) => $this->formatTracker($t))->values()->toArray();

            $this->sheetService->createSheetIfNotExist($targetTab);
            $result = $this->sheetService->upsertRows($rows, $targetTab, self::$trackerHeaders);
            $this->applyTrackerFormatting($targetTab);

            $totalUpdated += $result['updated'];
            $totalAppended += $result['appended'];
        }

        return ['updated' => $totalUpdated, 'appended' => $totalAppended, 'tabs' => $grouped->count() + 1];
    }

    // =========================================================================
    // 6. BRANDS
    // =========================================================================

    public static array $brandHeaders = [
        'Brand Name',
        'Platform',
        'Boost (%)',
        'Maximum Withdrawal Limit ($)',
        'Rate (đ)'
    ];

    public function formatBrand(Brand $record, array $platformNames = []): array
    {
        $platformName = $platformNames[$record->platform] ?? $record->platform;

        return [
            $record->name,
            $platformName,
            ($record->boost_percentage ?? 0) . '%',
            (is_null($record->maximum_limit) || $record->maximum_limit == 0) ? 'No Limit' : $record->maximum_limit,
            $record->gc_rate ?? 0,
        ];
    }

    public function syncBrands($records = null, ?string $spreadsheetId = null): array
    {
        if ($records instanceof Builder) {
            $records = $records->get();
        } elseif ($records === null) {
            $records = Brand::all();
        }

        // Pre-load platform names để tránh N+1 query trong formatBrand()
        $platformNames = Platform::pluck('name', 'slug')->toArray();

        $targetTab = 'Brand';
        $rows = collect($records)->map(fn($r) => $this->formatBrand($r, $platformNames))->values()->toArray();

        $this->sheetService->createSheetIfNotExist($targetTab, $spreadsheetId);
        $result = $this->sheetService->upsertRows($rows, $targetTab, self::$brandHeaders, $spreadsheetId);

        return $result;
    }

    public function importBrands(?string $spreadsheetId = null): array
    {
        $id = $spreadsheetId ?: (config('services.google.import_spreadsheet_id') ?: config('services.google.spreadsheet_id'));
        $sheetName = 'Brand';

        $rows = $this->sheetService->readSheet('A:E', $sheetName, $id);

        $updated = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;

        if (empty($rows)) {
            return ['updated' => 0, 'created' => 0, 'failed' => 0];
        }

        // Bỏ qua hàng tiêu đề
        array_shift($rows);

        // Lấy danh sách platform để map name -> slug
        $platforms = Platform::all();

        foreach ($rows as $index => $row) {
            $brandName = trim($row[0] ?? '');
            $platformName = trim($row[1] ?? '');

            if (empty($brandName)) {
                $skipped++;
                continue;
            }

            try {
                // Map platform name to slug
                $platformSlug = $platforms->first(function ($p) use ($platformName) {
                    return strtolower($p->name) === strtolower($platformName) || strtolower($p->slug) === strtolower($platformName);
                })?->slug ?? \Str::slug($platformName);

                // Lookup: name + platform (case-insensitive, chấp nhận cả slug lẫn display name)
                // Không dùng slug vì records cũ có slug format khác (không có platform suffix)
                $brand = Brand::withTrashed()
                    ->whereRaw('LOWER(name) = ?', [strtolower($brandName)])
                    ->where(function ($q) use ($platformSlug, $platformName) {
                        $q->whereRaw('LOWER(platform) = ?', [strtolower($platformSlug)])
                          ->orWhereRaw('LOWER(platform) = ?', [strtolower($platformName)]);
                    })
                    ->first();

                $isNew = false;
                if (!$brand) {
                    $brand = new Brand();
                    $brand->slug = \Str::slug($brandName . '-' . $platformSlug);
                    $isNew = true;
                } elseif ($brand->trashed()) {
                    $brand->restore();
                }

                // Normalize platform value sang slug
                $brand->name = $brandName;
                $brand->platform = $platformSlug;

                // Cập nhật các trường khác
                if (isset($row[2])) $brand->boost_percentage = $this->parseNumeric($row[2]);
                if (isset($row[3])) {
                    $limitVal = trim($row[3]);
                    $brand->maximum_limit = (strtolower($limitVal) === 'no limit' || empty($limitVal)) ? null : $this->parseNumeric($limitVal);
                }
                if (isset($row[4])) $brand->gc_rate = $this->parseNumeric($row[4]);

                $brand->save();

                if ($isNew) {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (\Exception $e) {
                Log::error("Import Brand Row Error at index {$index}: " . $e->getMessage());
                $failed++;
            }
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skipped
        ];
    }

    // =========================================================================
    // 7. PLATFORMS
    // =========================================================================

    public static array $platformHeaders = [
        'Platform Name',
        'Sort'
    ];

    public function formatPlatform(Platform $record): array
    {
        return [
            $record->name,
            $record->sort_order ?? 0,
        ];
    }

    public function importPlatforms(?string $spreadsheetId = null): array
    {
        $id = $spreadsheetId ?: (config('services.google.import_spreadsheet_id') ?: config('services.google.spreadsheet_id'));
        $sheetName = 'Platform';

        $rows = $this->sheetService->readSheet('A:B', $sheetName, $id);

        $updated = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;

        if (empty($rows)) {
            return ['updated' => 0, 'created' => 0, 'failed' => 0];
        }

        // Bỏ qua hàng tiêu đề
        array_shift($rows);

        foreach ($rows as $index => $row) {
            $platformName = trim($row[0] ?? '');

            if (empty($platformName)) {
                $skipped++;
                continue;
            }

            try {
                $platform = Platform::where('name', $platformName)->first();
                $isNew = false;

                if (!$platform) {
                    $platform = new Platform();
                    $platform->name = $platformName;
                    $platform->slug = \Str::slug($platformName);
                    $platform->is_active = true;
                    $isNew = true;
                }

                if (isset($row[1])) {
                    $platform->sort_order = (int) $this->parseNumeric($row[1]);
                }

                $platform->save();

                if ($isNew) {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (\Exception $e) {
                Log::error("Import Platform Row Error at index {$index}: " . $e->getMessage());
                $failed++;
            }
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skipped
        ];
    }

    // =========================================================================
    // 8. USERS
    // =========================================================================

    public static array $userHeaders = [
        'Holder',
        'Username',
        'Email Address',
        'Role'
    ];

    public function formatUser(User $record): array
    {
        return [
            $record->name,
            $record->username,
            $record->email,
            $record->role,
        ];
    }

    public function importUsers(?string $spreadsheetId = null): array
    {
        $id = $spreadsheetId ?: (config('services.google.import_spreadsheet_id') ?: config('services.google.spreadsheet_id'));
        $sheetName = 'User';

        $rows = $this->sheetService->readSheet('A:D', $sheetName, $id);

        $updated = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;

        if (empty($rows)) {
            return ['updated' => 0, 'created' => 0, 'failed' => 0];
        }

        // Bỏ qua hàng tiêu đề
        array_shift($rows);

        foreach ($rows as $index => $row) {
            $holder = trim($row[0] ?? '');
            $username = trim($row[1] ?? '');
            $email = trim($row[2] ?? '');
            $roleInput = strtolower(trim($row[3] ?? 'operator'));

            // Map role names (Hỗ trợ cả label tiếng Anh/Việt trong Sheet)
            $role = match($roleInput) {
                'admin specialist', 'admin' => 'admin',
                'financial officer', 'finance' => 'finance',
                'system operator', 'operator', 'staff', 'nhân viên' => 'operator',
                default => 'operator',
            };

            if (empty($username) || empty($email)) {
                $skipped++;
                continue;
            }

            try {
                // Ưu tiên tìm theo username trước, sau đó mới đến email
                $user = User::where('username', $username)->first();
                if (!$user) {
                    $user = User::where('email', $email)->first();
                }

                $isNew = false;

                if (!$user) {
                    $user = new User();
                    $user->username = $username;
                    $user->email = $email;
                    $user->password = \Hash::make('password123');
                    $isNew = true;
                }

                // 🛑 BẢO VỆ NGƯỜI DÙNG HIỆN TẠI & ADMINS
                // 1. Không đổi role của chính mình
                if (auth()->check() && $user->id === auth()->id()) {
                    $user->name = $holder ?: $username;
                    $user->save();
                    $updated++;
                    continue;
                }

                // 2. Không cho phép hạ cấp Admin qua Sync (để tránh mất quyền truy cập hệ thống)
                if (!$isNew && $user->isAdmin() && $role !== 'admin') {
                    $role = 'admin'; 
                }

                $user->name = $holder ?: $username;
                $user->role = $role;

                // Cập nhật lại email/username nếu có thay đổi (email thường là duy nhất)
                $user->email = $email;
                $user->username = $username;

                $user->save();

                if ($isNew) {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (\Exception $e) {
                Log::error("Import User Row Error at index {$index}: " . $e->getMessage());
                $failed++;
            }
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skipped
        ];
    }

    // =========================================================================
    // SYNC RECORD (Dùng cho Jobs/Observers - sync 1 bản ghi)
    // =========================================================================

    public function syncRecord($record, string $action = 'upsert', ?string $platform = null): void
    {
        $modelClass = get_class($record);
        $targetTabs = [];
        $headers = [];
        $formattedRow = [];

        // 1. Xác định Tab và Header dựa trên Model
        switch ($modelClass) {
            case Email::class:
                $targetTabs = ['Emails'];
                $headers = self::$emailHeaders;
                $formattedRow = $this->formatEmail($record);
                break;

            case Account::class:
                $p = $record->platform ?: ($platform ?: 'General');
                $targetTabs = [ucfirst($p) . '_Accounts'];
                $headers = self::$accountHeaders;
                $formattedRow = $this->formatAccount($record);
                break;

            case RebateTracker::class:
                $p = $record->account?->platform ?: ($platform ?: 'General');
                $targetTabs = ['All_Rebate_Tracker', ucfirst($p) . '_Tracker'];
                $headers = self::$trackerHeaders;
                $formattedRow = $this->formatTracker($record);
                break;

            case PayoutLog::class:
                // ✅ FIX #6: Cache platform names ngay cả khi sync 1 record
                $platformNames = Platform::pluck('name', 'slug')->toArray();
                $targetTabs = ['Payout_Logs'];
                $headers = self::$payoutLogHeaders;
                $formattedRow = $this->formatPayoutLog($record, $platformNames);
                break;

            case PayoutMethod::class:
                $targetTabs = ['Payout_Methods'];
                $headers = self::$payoutMethodHeaders;
                $formattedRow = $this->formatPayoutMethod($record);
                break;

            case Brand::class:
                $targetTabs = ['Brand'];
                $headers = self::$brandHeaders;
                $formattedRow = $this->formatBrand($record);
                break;

            case Platform::class:
                $targetTabs = ['Platform'];
                $headers = self::$platformHeaders;
                $formattedRow = $this->formatPlatform($record);
                break;

            case User::class:
                $targetTabs = ['User'];
                $headers = self::$userHeaders;
                $formattedRow = $this->formatUser($record);
                break;
        }

        if (empty($targetTabs))
            return;

        // 2. Thực hiện Action (Upsert hoặc Delete)
        foreach ($targetTabs as $tabName) {
            if ($action === 'delete') {
                $this->sheetService->deleteRowsByIds([(string) $record->id], $tabName);
                continue;
            }

            // Upsert
            $this->sheetService->createSheetIfNotExist($tabName);
            $this->sheetService->upsertRows([$formattedRow], $tabName, $headers);

            // Áp dụng định dạng riêng cho từng tab (nếu cần)
            $this->applySpecificTabFormatting($tabName);
        }
    }

    // =========================================================================
    // ✅ CHIỀU 2: SHEET -> WEB (ACCOUNTS)
    // =========================================================================

    public function importAccounts(?string $spreadsheetId = null): array
    {
        $id = $spreadsheetId ?? $this->sheetService->getSpreadsheetId();

        $sheetMappings = [
            'RKT_account_import' => 'Rakuten',
            'RMN_account_import' => 'RetailMeNot',
            'JH_account_import' => 'JoinHoney',
            'AJ_account_import' => 'ActiveJunky',
            'TCB_account_import' => 'TopCashback',
            'Price_account_import' => 'Price',
        ];

        $totalUpdated = 0;
        $totalCreated = 0;
        $totalFailed = 0;
        $totalSkipped = 0;

        foreach ($sheetMappings as $sheetName => $platform) {
            try {
                $rows = $this->sheetService->readSheet('A:O', $sheetName, $id);
                if (empty($rows)) continue;

                // Bỏ qua hàng tiêu đề
                array_shift($rows);

                foreach ($rows as $index => $row) {
                    $emailRaw = trim($row[0] ?? '');
                    if (empty($emailRaw) || !str_contains($emailRaw, '@')) {
                        $totalSkipped++;
                        continue;
                    }

                    try {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($row, $platform, &$totalCreated, &$totalUpdated) {
                            // 1. Sync Email
                            $email = Email::firstOrNew(['email' => trim($row[0])]);
                            $email->email_password = trim($row[1] ?? $email->email_password);
                            $email->recovery_email = trim($row[2] ?? $email->recovery_email) ?: null;
                            $email->two_factor_code = trim($row[3] ?? $email->two_factor_code) ?: null;
                            $email->note = trim($row[4] ?? $email->note) ?: null;

                            $statusEmailRaw = strtolower(trim($row[5] ?? 'active'));
                            $email->status = ($statusEmailRaw === 'live' || $statusEmailRaw === 'active') ? 'active' : $statusEmailRaw;
                            $email->save();

                            // 2. Sync Account
                            $account = Account::firstOrNew([
                                'email_id' => $email->id,
                                'platform' => $platform
                            ]);

                            $isNew = !$account->exists;

                            $account->password = trim($row[6] ?? $account->password);
                            $account->state = trim($row[7] ?? $account->state);
                            $account->device = trim($row[8] ?? $account->device);

                            if (!empty($row[9])) {
                                $account->account_created_at = $this->parseDate($row[9]);
                            }

                            // Status Account (Array)
                            if (!empty($row[10])) {
                                $account->status = [$this->sanitizeStatus($row[10])];
                            }

                            $account->note = trim($row[11] ?? $account->note);

                            // Extra columns for Rakuten or others if present
                            if ($platform === 'Rakuten') {
                                if (isset($row[12])) $account->device_linked_paypal = trim($row[12]) ?: null;
                                if (!empty($row[13])) $account->paypal_linked_at = $this->parseDate($row[13]);
                                if (isset($row[14])) $account->paypal_info = trim($row[14]) ?: null;
                            }

                            $account->save();

                            if ($isNew) {
                                $totalCreated++;
                            } else {
                                $totalUpdated++;
                            }
                        });
                    } catch (\Exception $e) {
                        Log::error("Import Account Row Error [{$platform}] at index {$index}: " . $e->getMessage());
                        $totalFailed++;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Import Sheet Error [{$sheetName}]: " . $e->getMessage());
                $totalFailed++;
            }
        }

        return [
            'updated' => $totalUpdated,
            'created' => $totalCreated,
            'failed' => $totalFailed,
            'skipped' => $totalSkipped
        ];
    }

    // ==========================================
    // FORMATTING HELPERS
    // ==========================================

    protected function applySpecificTabFormatting(string $tabName): void
    {
        if ($tabName === 'Emails') {
            $statusIdx = array_search('Status', self::$emailHeaders);
            $this->sheetService->applyFormattingWithRules($tabName, $statusIdx, [
                'Live' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85],
                'Disabled' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],
            ]);
            $this->sheetService->formatColumnsAsClip($tabName, 2, 4);

        } elseif ($tabName === 'Payout_Logs') {
            $statusIdx = array_search('Status', self::$payoutLogHeaders);
            $this->sheetService->applyFormattingWithRules($tabName, $statusIdx, [
                'Pending' => ['red' => 1.0, 'green' => 0.9, 'blue' => 0.6],
                'Completed' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85],
                'Rejected' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],
            ]);
            $this->sheetService->formatColumnsAsClip($tabName, 7, 9);

        } elseif ($tabName === 'Payout_Methods') {
            $statusIdx = array_search('Status', self::$payoutMethodHeaders);
            $this->sheetService->applyFormattingWithRules($tabName, $statusIdx, [
                'Active' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85],
            ]);
            $this->sheetService->formatColumnsAsClip($tabName, 4, 8);

        } elseif (str_ends_with($tabName, '_Accounts')) {
            $this->sheetService->formatColumnsAsClip($tabName, 5, 6);
            $this->sheetService->formatColumnsAsClip($tabName, 14, 15);
            $this->sheetService->formatColumnsAsClip($tabName, 17, 18);

        } elseif (str_contains($tabName, '_Tracker')) {
            $this->applyTrackerFormatting($tabName);
        }
    }

    protected function applyTrackerFormatting(string $targetTab): void
    {
        $statusIdx = array_search('Status', self::$trackerHeaders);
        $this->sheetService->applyFormattingWithRules($targetTab, $statusIdx, [
            'Confirmed' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85],
            'Clicked / Ordered' => ['red' => 1.0, 'green' => 1.0, 'blue' => 0.8],
            'Pending' => ['red' => 1.0, 'green' => 0.9, 'blue' => 0.6],
            'Ineligible' => ['red' => 0.9, 'green' => 0.5, 'blue' => 0.5],
            'Missing' => ['red' => 1.0, 'green' => 0.8, 'blue' => 0.8],
        ]);

        $this->sheetService->formatColumnsAsClip($targetTab, 16, 18); // Note & Detail Transaction
    }

    /**
     * ✅ CHIỀU 2: SHEET -> WEB (Dành cho Tracker/Đơn hàng)
     * Đồng bộ dữ liệu từ Google Sheet (6 tab Tracker) về Database.
     */
    public function importTrackers(?string $spreadsheetId = null): array
    {
        $id = $spreadsheetId ?: (config('services.google.import_spreadsheet_id') ?: config('services.google.spreadsheet_id'));

        $sheetMappings = [
            'RKT_Tracker'   => 'Rakuten',
            'RMN_Tracker'   => 'RetailMeNot',
            'JH_Tracker'    => 'JoinHoney',
            'AJ_Tracker'    => 'ActiveJunky',
            'TCB_Tracker'   => 'TopCashback',
            'Price_Tracker' => 'Price',
        ];

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalFailed = 0;
        $totalSkipped = 0;

        foreach ($sheetMappings as $sheetName => $platform) {
            try {
                // Đọc dải ô A:P (16 cột)
                $rows = $this->sheetService->readSheet('A:P', $sheetName, $id);
                if (empty($rows)) continue;

                // Bỏ qua hàng tiêu đề
                array_shift($rows);

                foreach ($rows as $index => $row) {
                    $emailRaw = trim($row[0] ?? '');
                    if (empty($emailRaw) || !str_contains($emailRaw, '@')) {
                        $totalSkipped++;
                        continue;
                    }

                    try {
                        DB::transaction(function () use ($row, $platform, &$totalCreated, &$totalUpdated, &$totalFailed) {
                            // 1. Resolve Email & Account (Giống importAccounts)
                            $email = Email::firstOrCreate(['email' => trim($row[0])]);
                            
                            $account = Account::firstOrCreate([
                                'email_id' => $email->id,
                                'platform' => $platform
                            ]);

                            // Cập nhật thông tin Account nếu có trong sheet
                            if (!empty($row[1])) $account->password = trim($row[1]);
                            // Status (Account Status Tracking)
                            if (!empty($row[3])) {
                                $account->status = $this->sanitizeStatus($row[3], asArray: true);
                            }
                            if ($account->isDirty()) $account->save();

                            // 2. Resolve User
                            $userName = trim($row[2] ?? '');
                            $userId = null;
                            if (!empty($userName)) {
                                $userId = User::where('name', $userName)->value('id');
                            }

                            // 3. Sync Tracker
                            $orderId = trim($row[6] ?? '');
                            if (empty($orderId)) return; // Bắt buộc có Order ID

                            $tracker = RebateTracker::firstOrNew([
                                'account_id' => $account->id,
                                'order_id'   => $orderId
                            ]);

                            $isNew = !$tracker->exists;

                            // Mapping các trường
                            if (!empty($row[4])) $tracker->transaction_date = $this->parseDate($row[4]);
                            $tracker->store_name = trim($row[5] ?? $tracker->store_name);
                            
                            // Các giá trị số
                            $tracker->order_value = $this->parseNumeric($row[7] ?? 0);
                            $tracker->cashback_percent = $this->parseNumeric($row[8] ?? 0);
                            
                            // Nếu trong sheet có cột Rebate Amount (index 9) thì ưu tiên lấy, 
                            // nếu không Model sẽ tự tính lúc save().
                            if (!empty($row[9])) {
                                $tracker->rebate_amount = $this->parseNumeric($row[9]);
                            }

                            // Status & Payout Date
                            // Đối với Tracker, sanitizeStatus trả về chuỗi (mặc định asArray = false)
                            $statusRaw = $this->sanitizeStatus($row[10] ?? '');
                            // Mapping Tracker Status specific for Enum
                            $trackerStatus = match($statusRaw) {
                                'active', 'live', 'confirmed' => 'confirmed',
                                'pending' => 'pending',
                                'ineligible', 'rejected' => 'ineligible',
                                'missing' => 'missing',
                                default => 'clicked'
                            };
                            $tracker->status = $trackerStatus;
                            if (!empty($row[11])) $tracker->payout_date = $this->parseDate($row[11]);

                            // Info khác
                            $tracker->device = trim($row[12] ?? $tracker->device);
                            $tracker->state = trim($row[13] ?? $tracker->state);
                            $tracker->note = trim($row[14] ?? $tracker->note);
                            $tracker->detail_transaction = trim($row[15] ?? $tracker->detail_transaction);
                            $tracker->user_id = $userId ?: $tracker->user_id;

                            $tracker->save();

                            if ($isNew) {
                                $totalCreated++;
                            } else {
                                $totalUpdated++;
                            }
                        });
                    } catch (\Exception $e) {
                        Log::error("Row Import Error [{$sheetName}] row " . ($index + 2) . ": " . $e->getMessage());
                        $totalFailed++;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Import Sheet Error [{$sheetName}]: " . $e->getMessage());
                $totalFailed++;
            }
        }

        return [
            'created' => $totalCreated,
            'updated' => $totalUpdated,
            'failed'  => $totalFailed,
            'skipped' => $totalSkipped
        ];
    }

    /**
     * ✅ CHIỀU 2: SHEET -> WEB (Dành cho Payout Methods)
     * Đồng bộ dữ liệu từ Google Sheet (Tab Payout_Method) về Database.
     */
    public function importPayoutMethods(?string $spreadsheetId = null): array
    {
        $id = $spreadsheetId ?: (config('services.google.import_spreadsheet_id') ?: config('services.google.spreadsheet_id'));
        $sheetName = 'Payout_Method';

        // Đọc 23 cột (A:W)
        $rows = $this->sheetService->readSheet('A:W', $sheetName, $id);
        if (empty($rows)) {
            return ['updated' => 0, 'created' => 0, 'failed' => 0];
        }

        // Bỏ qua hàng tiêu đề
        array_shift($rows);

        $totalUpdated = 0;
        $totalCreated = 0;
        $totalFailed = 0;

        foreach ($rows as $index => $row) {
            $name = trim($row[0] ?? '');
            if (empty($name)) continue;

            try {
                $payoutMethod = PayoutMethod::firstOrNew(['name' => $name]);
                $isNew = !$payoutMethod->exists;

                // Mapping dữ liệu
                // 1: Method Type
                $payoutMethod->type = strtolower(str_replace(' ', '_', trim($row[1] ?? 'paypal')));
                // 2: Email Address
                $payoutMethod->email = trim($row[2] ?? $payoutMethod->email);
                // 3: Email Password
                $payoutMethod->password = trim($row[3] ?? $payoutMethod->password);
                // 4: PayPal Account
                $payoutMethod->paypal_account = trim($row[4] ?? $payoutMethod->paypal_account);
                // 5: PayPal Password
                $payoutMethod->paypal_password = trim($row[5] ?? $payoutMethod->paypal_password);
                // 6: Authenticator Code
                $payoutMethod->auth_code = trim($row[6] ?? $payoutMethod->auth_code);
                // 7: Full Name
                $payoutMethod->full_name = trim($row[7] ?? $payoutMethod->full_name);
                // 8: Date of Birth
                if (!empty($row[8])) {
                    $payoutMethod->dob = $this->parseDate($row[8]);
                }
                // 9: SSN / Tax ID
                $payoutMethod->ssn = trim($row[9] ?? $payoutMethod->ssn);
                // 10: Phone Number
                $payoutMethod->phone = trim($row[10] ?? $payoutMethod->phone);
                // 11: Full Address
                $payoutMethod->address = trim($row[11] ?? $payoutMethod->address);
                // 12: Question Security 1
                $payoutMethod->question_1 = trim($row[12] ?? $payoutMethod->question_1);
                // 13: Answer 1
                $payoutMethod->answer_1 = trim($row[13] ?? $payoutMethod->answer_1);
                // 14: Question Security 2
                $payoutMethod->question_2 = trim($row[14] ?? $payoutMethod->question_2);
                // 15: Answer 2
                $payoutMethod->answer_2 = trim($row[15] ?? $payoutMethod->answer_2);
                // 16: Proxy Type
                $payoutMethod->proxy_type = trim($row[16] ?? $payoutMethod->proxy_type);
                // 17: IP Address
                $payoutMethod->ip_address = trim($row[17] ?? $payoutMethod->ip_address);
                // 18: Location
                $payoutMethod->location = trim($row[18] ?? $payoutMethod->location);
                // 19: ISP (Network Provider)
                $payoutMethod->isp = trim($row[19] ?? $payoutMethod->isp);
                // 20: Browser Name
                $payoutMethod->browser = trim($row[20] ?? $payoutMethod->browser);
                // 21: Device
                $payoutMethod->device = trim($row[21] ?? $payoutMethod->device);
                // 22: Note
                $payoutMethod->note = trim($row[22] ?? $payoutMethod->note);
                
                // Mặc định kích hoạt nếu import mới
                if ($isNew) {
                    $payoutMethod->is_active = true;
                    $payoutMethod->status = 'active';
                }

                $payoutMethod->save();

                if ($isNew) {
                    $totalCreated++;
                } else {
                    $totalUpdated++;
                }

            } catch (\Exception $e) {
                Log::error("Import Payout Method Row Error at index {$index}: " . $e->getMessage());
                $totalFailed++;
            }
        }

        return [
            'updated' => $totalUpdated,
            'created' => $totalCreated,
            'failed' => $totalFailed
        ];
    }

    /**
     * Chuyển đổi chuỗi tiền/phần trăm từ Google Sheet sang số Float chuẩn.
     * Xử lý cả dấu phẩy (,) và dấu chấm (.) làm dấu thập phân.
     */
    private function parseNumeric($value): float
    {
        if (empty($value)) return 0;
        
        $value = (string) $value;
        // Loại bỏ ký hiệu tiền tệ, phần trăm và khoảng trắng
        $value = str_replace(['$', '%', ' ', '₫'], '', $value);
        
        // Nếu có cả dấu chấm và dấu phẩy (ví dụ: 1,234.56 hoặc 1.234,56)
        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strpos($value, ',') < strpos($value, '.')) {
                // Kiểu Mỹ: 1,234.56 -> Xóa dấu phẩy
                $value = str_replace(',', '', $value);
            } else {
                // Kiểu Âu/Việt: 1.234,56 -> Xóa dấu chấm, đổi phẩy thành chấm
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            }
        } else {
            // Chỉ có một loại dấu ngăn cách
            // Nếu dấu phẩy xuất hiện ở cuối (cách 2-3 chữ số), coi đó là dấu thập phân
            if (str_contains($value, ',')) {
                $value = str_replace(',', '.', $value);
            }
        }
        
        return (float) $value;
    }

    /**
     * Chuẩn hóa Status để khớp với Translation Keys (Xóa dấu cách, viết thường, mapping...)
     * Hỗ trợ chuỗi có dấu mũi tên (Timeline) hoặc dấu phẩy.
     */
    private function sanitizeStatus(?string $status, bool $asArray = false)
    {
        if (empty($status)) {
            return $asArray ? ['active'] : 'active';
        }

        $status = (string) $status;
        
        // Sử dụng Regex mạnh mẽ hơn để tách chuỗi:
        // Tách bởi bất kỳ tổ hợp nào của dấu gạch ngang, dấu mũi tên, dấu bằng, dấu lớn hơn: ->, →, =>, >>, ---
        // Hoặc dấu phẩy (,)
        $parts = preg_split('/\s*[\-=>→>]+\s*|,\s*/u', $status, -1, PREG_SPLIT_NO_EMPTY);
        
        if (count($parts) > 1) {
            $sanitized = array_map(fn($p) => $this->sanitizeSingleStatus($p), $parts);
            $sanitized = array_values(array_filter($sanitized));
            return $asArray ? $sanitized : implode(' -> ', $sanitized);
        }

        $sanitized = $this->sanitizeSingleStatus($status);
        return $asArray ? [$sanitized] : $sanitized;
    }

    /**
     * Xử lý chuẩn hóa cho một status đơn lẻ
     */
    private function sanitizeSingleStatus(string $status): string
    {
        $status = strtolower(trim($status));
        // Xóa tất cả ký tự không phải chữ cái, số, dấu cách, gạch ngang
        $status = preg_replace('/[^a-z0-9\s\-_]/', '', $status);
        
        $status = str_replace([' ', '-'], '_', $status);
        
        // Loại bỏ các dấu gạch dưới dư thừa (ví dụ: linked__paypal -> linked_paypal)
        $status = preg_replace('/_+/', '_', $status);
        // Loại bỏ gạch dưới ở đầu và cuối
        $status = trim($status, '_');

        $mappings = [
            'in_use'             => 'used',
            'live'               => 'live',
            'active'             => 'active',
            'not_linked'         => 'not_linked_paypal',
            'not_linked_paypal'  => 'not_linked_paypal',
            'linked'             => 'linked_paypal',
            'linked_paypal'      => 'linked_paypal',
            'unlinked'           => 'unlinked_paypal',
            'unlinked_paypal'    => 'unlinked_paypal',
            'paypal_limited'     => 'paypal_limited',
            'limited'            => 'paypal_limited',
            'no_paypal'          => 'no_paypal_required',
            'no_paypal_needed'   => 'no_paypal_required',
            'no_paypal_required' => 'no_paypal_required',
            'banned'             => 'banned',
            'locked'             => 'locked',
            'disabled'           => 'disabled',
        ];
        
        return $mappings[$status] ?? $status;
    }
}
