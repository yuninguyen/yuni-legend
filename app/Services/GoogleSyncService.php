<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Email;
use App\Models\PayoutLog;
use App\Models\PayoutMethod;
use App\Models\RebateTracker;
use App\Models\Platform;
use App\Filament\Resources\Traits\HasUsStates;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

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
            now('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s'),
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
        // Đọc toàn bộ dữ liệu (bao gồm cả Header để kiểm tra)
        $rows = $this->sheetService->readSheet('A1:K', $sheetName, $spreadsheetId);
        
        $updated = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;

        if (empty($rows)) {
            return ['updated' => 0, 'created' => 0, 'failed' => 0];
        }

        foreach ($rows as $index => $row) {
            // 1. Kiểm tra định dạng: Bỏ qua hàng tiêu đề nếu có
            $emailRaw = trim($row[1] ?? '');
            if ($index === 0 && (str_contains(strtolower($emailRaw), 'email') || str_contains(strtolower($emailRaw), 'địa chỉ'))) {
                continue;
            }

            // Nếu cột Email trống thì bỏ qua
            if (empty($emailRaw) || !str_contains($emailRaw, '@')) {
                $skipped++;
                continue;
            }

            try {
                // 2. Tìm hoặc Tạo mới dựa trên Email (không dùng ID nữa)
                $emailRecord = Email::where('email', $emailRaw)->first();
                $isNew = false;

                if (!$emailRecord) {
                    $emailRecord = new Email();
                    $emailRecord->email = $emailRaw;
                    $isNew = true;
                }

                // 3. Mapping dữ liệu theo cấu trúc người dùng cung cấp:
                // 0: Status, 1: Email, 2: Password, 3: Recovery Email, 4: 2FA Code, 5: Date Create, 6: Note

                // Status mapping
                $statusRaw = strtolower(trim($row[0] ?? 'active'));
                $status = ($statusRaw === 'live' || $statusRaw === 'active') ? 'active' : $statusRaw;
                if (in_array($status, ['active', 'disabled', 'locked'])) {
                    $emailRecord->status = $status;
                }

                // Password
                if (!empty($row[2])) $emailRecord->email_password = trim($row[2]);

                // Recovery Email
                if (isset($row[3])) $emailRecord->recovery_email = trim($row[3]) ?: null;

                // 2FA Code
                if (isset($row[4])) $emailRecord->two_factor_code = trim($row[4]) ?: null;

                // Date Create
                if (!empty($row[5])) {
                    $emailRecord->email_created_at = $this->parseDate($row[5]);
                }

                // Note
                if (isset($row[6])) $emailRecord->note = trim($row[6]);

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
            now('Asia/Ho_Chi_Minh')->format('d/m/Y H:i'),
        ];
    }

    public function syncPayoutMethods($records = null): array
    {
        if ($records === null) {
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
        if ($records === null) {
            $records = PayoutLog::with(['account.email', 'payoutMethod'])->orderBy('created_at', 'desc')->get();
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
        if ($records === null) {
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
    // FORMATTING HELPERS
    // =========================================================================

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
}
