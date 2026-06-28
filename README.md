# 📊 RebateOps — Enterprise Cashback Management System

RebateOps is a professional, high-performance internal tool built with **Laravel 12** and **Filament 3** for managing large-scale cashback operations across multiple platforms. It combines a premium SaaS aesthetic with rigorous financial integrity and real-time Google Sheets synchronization.

![Laravel](https://img.shields.io/badge/Laravel-12.0-FF2D20?style=for-the-badge&logo=laravel)
![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?style=for-the-badge&logo=php)
![Filament](https://img.shields.io/badge/Filament-3.2-FFAA00?style=for-the-badge&logo=filament)
![Design](https://img.shields.io/badge/Design-Premium_Mixed_Mode-blue?style=for-the-badge)

---

## 🌟 Key Pillars

### 💎 Premium Design System
- **Mixed Mode UI**: Dark elegant sidebar paired with clean, information-dense light content area.
- **Modern Typography**: Powered by **Plus Jakarta Sans** for that premium "Google Sans" feel.
- **High-Density UX**: Optimized 90% scaling for tables, robust text-wrapping, and zero horizontal scroll on data-heavy resources.
- **Native Multi-Language**: Seamless toggle between **English** and **Vietnamese** with consistent label mapping.
- **UX Excellence**: Integrated "Back to Top" functionality, optimized mobile navigation, and blur-filtered overlays.
- **Infolist Standardization**: High-density, tab-based UI with grid layouts for complex resources (Account, Tracker, Investor Expense, Payout Methods, Payout Logs), improving visual consistency and providing quick-copy utilities for sensitive data.
- **Modal-First Interaction**: Transitioned core resources (Investor Expense, Payout Methods) to modal-based View, Edit, and Create actions, reducing page transitions and maintaining context during high-speed data entry.

### 🛡️ Financial Integrity & Security
- **Smart Record Locking**: Automated locking of Payout Logs once they are generated into a Disbursement. Parents are intelligently locked only when their child transactions are fully settled.
- **Data Integrity Safeguards**: Restricted bulk actions (Delete, Mark as Completed) for settled records to prevent accidental financial discrepancies.
- **Pessimistic Locking**: Prevents race conditions on wallet balances using `lockForUpdate()`. Exchange and Settlement actions lock the full economic group (parent + liquidation children) before computing totals — concurrent requests block on the lock rather than racing.
- **Atomic Transactions**: All balance changes follow a strict safety pattern within DB transactions.
- **Safe Soft-Delete Cascade**: Soft-deleting a `UserPayment` (Disbursement) automatically unlinks all associated `PayoutLog` rows (`user_payment_id → null`), preventing logs from being stranded as settled with no recovery path.
- **Advanced Data Recovery**: **SoftDeletes** implemented across all core models, with "Restore" and "Force Delete" capabilities for authorized Admins.
- **At-Rest Encryption**: Sensitive data (Gift Card codes, passwords) are encrypted using Laravel's native encryption.

### 🔄 Automation & UX
- **Dynamic Bidirectional Sync**: Advanced Google Sheets integration supporting independent Spreadsheet IDs for Import and Export operations.
- **Intelligent Onboarding**: Bulk import for both **Emails** (7-column spec) and **Accounts** from platform-specific tabs with automated record linking and duplicate prevention.
- **Contextual Tooltips**: Real-time platform discovery—hovering over Usage metrics instantly reveals linked platforms for every email record.
- **Composite Grouping**: Advanced table grouping in Payout Logs by **Account + Brand**, providing a clear separated view for multi-brand accounts.
- **Contextual UI**: "Exchange to VND" link intelligently disappears once a record is fully liquidated, preventing duplicate transactions.
- **Queue-Powered Sync**: Real-time synchronization with Google Sheets (3x retry, 60s backoff).
- **Full-Spec Wallet Sync**: Direct import/export for Payout Methods supporting 23 data points including authentication, security questions, and personal identification (PII) info.
- **Administrative Sync Tools**: Integrated UI actions for bulk importing Users and Platforms from Google Sheets with automated role mapping and badge color persistence.
- **Brand Sync Intelligence**: Flawless bidirectional synchronization of "No Limit" textual parameters to nullable database fields to ensure absolute parity between Sheets and Web UI.
- **Smart Formatting**: Automatic sheet tab creation, frozen headers, and status-based conditional coloring.
- **Language-Independent Nav**: Strict sidebar hierarchy (Dashboard → Resource → Work → Wallet → Settings → Logs) enforced regardless of active locale.
- **Activity Logging**: Full audit trail for Admin oversight on every data mutation.
- **Advanced Rebate Tracking**: Robust parsing of status timelines from Google Sheets with automated mapping to localized system keys and semantic color coding (Green/Orange/Blue) across both Table and Infolist views.
- **Intelligent Tracker Sync**: Bidirectional synchronization for order trackers across 6 major platforms with automated status mapping and Payout Date resolution.
- **Leader Split Customization**: Advanced transaction labeling for multi-tier profit sharing (`[Asset] - [Wallet Name] [[Staff Name]]`) with automated dynamic account retrieval.
- **Optimized Disbursement UI**: High-efficiency payroll management featuring copyable financial data, disabled row-click redirects, and toggleable status columns for maximum information density. "Total Deductions" (VND/USD/USDT) columns are hidden by default (`->toggleable(isToggledHiddenByDefault: true)`) and can be re-enabled from the column-visibility menu. The row description shows the linked Account/email, platform, and USD amount (handler/Leader rows only — hidden for Partner rows to avoid showing the same amount twice), falling back through `PartnerWithdrawal` when no real `Account` exists. USD amount is summed across all `PayoutLog` rows linked to the batch **without** filtering by `parent_id`, since a settlement group only ever stamps `user_payment_id` on one of (parent) or (liquidation children), never both.
- **Multi-Currency Expense Management**: Integrated tracking for **VND, USD, and USDT (Crypto)** expenses. USDT expenses are intelligently deducted from Gross USD before revenue splitting, ensuring accurate profit margin calculations.
- **Automated Deduction Auditing**: Dynamic generation of multi-currency deduction notes in disbursement records for transparent financial reconciliation.

---

## 👥 Role-Based Workflow

RebateOps is designed for team collaboration with strictly scoped access.

### 🛠️ Admin (The Architect)
- **Global Oversight**: Access to all accounts, emails, and trackers across all team members.
- **Wallet Control**: Manage global `PayoutMethods` and monitor real-time wallet balances.
- **Settlement Module**: Finalize payments to staff members, upload transfer proofs, and track profit margins.
- **System Integrity**: Access to Activity Logs, User Management, and global configuration.

### 💹 Finance (The Auditor)
- **Financial Oversight**: Specialized Dashboard showing only the system profit and payroll analysis (`AdminUserEarningsTable`).
- **Financial Control**: Full access to Payout Logs, Payout Methods, and Disbursement — can create, edit, delete, and restore records to ensure financial reconciliation accuracy.
- **Zero Friction**: Navigation is streamlined to hide operational trackers, keeping focus on financial reconciliation.

### 👤 Staff (The Operator)
- **Account Management**: Claim and manage their assigned accounts and linked emails.
- **Operations**:
    - **Rebate Tracker**: Log and track order cashback from `Pending` to `Confirmed`.
    - **Payouts**: Execute withdrawals. Redeem Gift Cards directly in the app or provide notes for Admin-led PayPal processing.
- **Visibility**: View only their own data to ensure focused productivity and data privacy.

### 🤝 Partner (The Outsourcing Agent)
- **Withdrawal Requests**: Submit withdrawal requests by providing platform credentials (email, password, 2FA) and the target amount.
- **Credential Security**: All sensitive credentials are AES-encrypted at rest using Laravel's native encryption.
- **Self-Service**: Partners can only view and edit their own `pending` records — no access to any other system resource.
- **Status Visibility**: Real-time status tracking (`Pending → Processing → Completed / Wrong Password / Banned`) updated by Finance or Admin.

---

## 💰 Financial Operations & Workflows
 
### 1. Withdrawal (Inbound Funds)
- **Scenario**: Triggered when staff pull funds from platforms (e.g., Rakuten, TopCashback) to intermediary wallets or receive Gift Cards.
- **Initial Status**: Defaults to `Pending`.
- **Logic**: Automatically identifies the asset type (PayPal balance or Gift Card) to apply the appropriate liquidation workflow.
 
### 2. Hold (Keep Code)
- **Purpose**: Designed for records that should not be sold immediately (especially Gift Cards) or need to be kept for later processing.
- **Operation**: Switch status to `Hold`. The system still tracks inventory, but the amount won't be included in payroll calculations until it is liquidated (Exchanged).
 
### 3. Exchange to VND (Liquidation)
- **Process**: Converting USD balances from wallets/cards into Vietnamese Dong (VND) based on market exchange rates.
- **Partial Liquidation**: 
    - Allows for **multiple partial liquidations** on the same original record (Parent).
    - Useful when liquidating only a portion of a PayPal wallet balance or selling small amounts of a large Gift Card.
- **Risk Control**:
    - **Gift Card**: The total sum of all liquidations cannot exceed the original face value of the card.
    - **PayPal**: Allows flexible withdrawals based on the actual wallet balance.
    - **Liquidation Locking**: The "Exchange to VND" action automatically disappears once the record is 100% liquidated or has been settled in a payment (`Settled`).

### 4. Settle & Generate Payment (Disbursement)
- **Trigger**: Bulk action on selected Payout Logs (parent withdrawal/hold, or its liquidation child if already Exchanged to VND). Modal is split into 3 columns: **Partner/Staff Split**, **Leader Split**, **Handler Split (Accounting)**.
- **Linking rule**: For each economic group, `user_payment_id` is stamped onto exactly **one** of (parent record) OR (liquidation children) — never both — so summing `net_amount_usd` across the linked logs never double-counts.
- **`settlement_group_id`**: A separate, immutable column on `UserPayment` (distinct from the user-editable `batch_id`) that records the original Settle run a row was created in. The Disbursement description's Account/Email/USD lookup keys off `settlement_group_id`, never `batch_id` — this prevents the "Batch Selected" bulk-grouping action (which intentionally reassigns `batch_id` to merge unrelated payments for bulk-pay workflows) from corrupting the per-row Account/Email/USD display.
- **Partner Split vs Staff Split**: The settlement modal auto-detects if the selection belongs to a `partner` role user (`PayoutLogResource::isPartnerSelection()`) and relabels the section "Partner Split" with a 100%/market-rate default. A toggle ("Split by margin percentage") lets Partner/Staff also receive the full (100%) amount with the Percentage field hidden, instead of typing 100 manually.
- **Leader Split / Handler Split**: Optionally select a "Leader" (manager) and/or a "Handler" (the accounting person who actually processed the transaction) — independent of each other, each with their own Payout Rate (3-decimal precision) and Percentage. Both compute their VND income from the **exchange-rate margin**, not from re-using the Partner/Staff's USD: `VND = (Own Payout Rate − Partner/Staff Payout Rate) × USD × Percentage%`. Toggling off "Split by margin percentage" gives them the **entire** margin (100%) instead of a partial cut. This guarantees Leader/Handler income is carved out of the margin only and never double-counts the Partner/Staff's own USD-based payout.
- **`transaction_type` labeling**: Built from the actual wallet's `asset_type`/`payout_method` (`Gift Card` / `PayPal US` / `PayPal VN`) — never hardcoded — appended with `[Leader Name]` / `[Handler Name]` when a split exists.

### ⚠️ Two Different "Total Paid" Metrics — Do Not Conflate
These two numbers are **expected to differ** and measure different stages of the same pipeline:
- **Dashboard "Total Paid (USD)"** (`PayoutStats` widget): `SUM(net_amount_usd)` from `PayoutLog` where `transaction_type IN (withdrawal, hold)` and `status = completed`. This is money **confirmed withdrawn from the platform**, regardless of whether it has been Exchanged to VND or Settled into payroll yet.
- **Payroll Settlement "System Profit" / personal totals** (`AdminUserEarningsTable` widget): `SUM(total_usd)` from `UserPayment` where `status = paid`. This is money that has actually been **disbursed (paid out)** to staff/leader/partner.
- The gap between them = withdrawal/hold records that are `completed` on the platform side but haven't gone through "Exchange to VND" + "Settle & Generate Payment" yet.

---

## 🗂️ Core Architecture

```
REBATEOPS
├── RESOURCE HUB          # Central Assets (Admin & Finance Parity)
│   ├── Emails            # Linked email directory
│   └── Accounts          # Account management
├── WORKING SPACE         # Operations (Hidden from Finance)
│   ├── Rebate Trackers       # Rakuten, Join Honey, TopCashback, RetailMeNot, Price, ActiveJunky
├── WALLET & PAYOUTS      # Financial Layer
│   ├── Investor Expenses # Multi-currency Expense Management
│   ├── Payout Methods    # Virtual Wallets
│   ├── Payout Logs       # Withdrawals & Liquidations
│   ├── Partner Withdrawals # Outsourced withdrawal requests from Partners
│   └── Disbursement      # Disbursement (User Payments) Payroll
└── SETTINGS              # System Core (Admin only)
    ├── Users             # User Management (now includes Partner role)
    ├── Platforms         # Platform Configuration
    ├── Brands            # Brand Management
    └── Activity Logs     # System audit
```

---

## 🛠️ Technical Setup

### Requirements
- **PHP** 8.3+ (Production: 8.3.x / Dev: 8.4+)
- **Composer** & **Node.js**
- **MySQL / MariaDB** (Production: Hostinger MariaDB)
- **Google Cloud Console** access for Sheets API

### Installation
1. **Clone & Install**:
   ```bash
   git clone ...
   composer install && npm install
   ```
2. **Environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
3. **Google Configuration**:
   ```bash
   GOOGLE_SPREADSHEET_ID=primary_export_id
   GOOGLE_IMPORT_SPREADSHEET_ID=primary_import_id
   GOOGLE_SERVICE_ACCOUNT_PATH=storage/app/google-auth.json
   ```
4. **Database**:
   ```bash
   php artisan migrate --force
   ```

---

## 👨‍💻 Roadmap
- [x] v5.0: Advanced Financial Locking & SoftDeletes
- [x] v5.1: Finance Role & Advanced RBAC Overhaul
- [x] v5.2: Smart Payout Locking & Multi-Brand Grouping Logic
- [x] v5.3: Core Localization (VI/EN) & UI Density Optimization
- [x] v5.4: Advanced Data Recovery (Restore / Force Delete)
- [x] v5.4.1: Security Patch — Policy registration, operator precedence, cascade forceDelete, email validation
- [x] v5.4.2: Concurrency Hardening — `lockForUpdate()` on Exchange & Settlement group scope; soft-delete cascade unlinks PayoutLogs
- [x] v5.4.3: Hostinger Production Stability — MySQL/MariaDB compatibility refactor (CONCAT/YEAR), forced HTTPS, anti-SEO hardening, and disbursement slug update.
- [x] v5.5: Dynamic Google Sheets Integration — Independent Import/Export IDs, Intelligent Email Onboarding (7-column spec), and Contextual Hover Tooltips.
- [x] v5.5.1: Bidirectional Account Sync — Cross-platform import, and unified UI-driven sync actions.
- [x] v5.5.2: Bidirectional Rebate Tracker Sync — Multi-tab order import across platforms, high-resiliency status sanitization, and automated account-email linking.
- [x] v5.5.3: Bidirectional Payout Method Sync — Full-spec wallet import (23-column), automated Personal Security Info (PII) mapping, and unique identity-based conflict resolution.
- [x] v5.6.0: Multi-tier Profit Sharing — Advanced settlement logic to split revenue between Leaders and Staff members, dynamically generating separate Disbursement records based on percentage allocations.
- [x] v5.6.1: Core UI/UX Stabilization — Global systematic debugging of Filament 3 Floating UI; resolving modal z-index overlap issues by safely injecting state-driven CSS across all Admin panels.
- [x] v5.6.2: Disbursement Refinement & Google Sync — Streamlined UserPayment UI with removal of file uploads, copyable data columns, dynamic PayPal region detection,and bulk Google Sheets sync for Users/Platforms/Logs.
- [x] v5.6.3: Advanced Tracker Grouping — Transitioned Rebate Tracker to a batch-oriented grouping structure (Account + Batch ID) with manual bulk assignment actions for granular workflow management.
- [x] v5.7.0: Multi-Currency Expense Tracking — Full integration of VND, USD, and USDT (Crypto) investor expenses into the payout settlement workflow. Refined UI/UX with modal-based actions and standardized Tabbed Infolists across the expense management layer.
- [x] v5.8.0: Partner Withdrawal Management — Introduced a dedicated outsourcing workflow for external partners. New `partner` role with scoped panel access; `PartnerWithdrawal` resource with AES-encrypted credential storage (email password, platform password, 2FA), Finance-controlled status pipeline, and one-click `Generate Payout Log` action for Admin settlement. Full VI/EN localization (27 keys) across all labels, sections, status options, and action notifications.
- [x] v5.8.1: Partner UI & Workflow Optimization — Refined Partner withdrawal form with automated ID binding and read-only identity protection. Implemented role-based status control: Partners see status as non-editable badges (Pending → Processing → Completed), while Admin/Finance retain quick-edit capabilities. Standardized tabular alignment (Center) across the resource for enhanced information density.
- [x] v5.8.2: Partner Withdrawal → Disbursement Pipeline — Linked `PartnerWithdrawal` requests directly into the Payout Log lifecycle via a new `partner_withdrawal_id` column (nullable `account_id` to support partners without a real platform `Account`). Create Payout Log now shows a Partner's pending requests instead of Source Account when a Partner is selected; Exchange-to-VND child records correctly inherit the link so groupings, totals, and the Account column resolve via the linked request instead of `N/A`. Fixed a Filament TextColumn quirk where `formatStateUsing` was silently skipped when the underlying raw state was `null`. Added an editable transaction date to the Currency Exchange modal, 3-decimal precision for Staff/Leader Payout Rate, and N/A placeholders for blank Partner Withdrawal credential fields.
- [x] v5.8.3: Partner/Leader Settlement Split & Disbursement Polish — "Settle & Generate Payment" now auto-labels "Partner Split" vs "Staff Split" based on the selected records' user role (worked around a Filament limitation where nested Form components don't receive `Collection $records` — fixed via `mountUsing()` + `Hidden` field + `Get`). Added a "Leader Split" section with an `leader_gets_profit` toggle that auto-transfers the exchange-rate margin ("Profit") to the Leader's income instead of requiring a manual rate/percentage, while zeroing the Staff/Partner side's `total_usd` to prevent double-counting in Disbursement totals. Fixed `transaction_type` on Leader payments being hardcoded to "PayPal VN" — now derived from the actual wallet's asset type. Fixed the Disbursement description's USD amount incorrectly showing `$0.00` for Leader rows due to an unnecessary `whereNull('parent_id')` filter. Added Partner role to `AdminUserEarningsTable` (Payroll Settlement) so Partners' personal totals are visible; removed the column `->summarize()` (both per-group "Total: X" and the bottom "Summary" row) because Filament computes the grand total by re-summing the displayed group totals rather than re-querying — there was no way to exclude the "System Profit" row (a company-wide aggregate, not personal income) from the grand total without removing summaries entirely.
- [x] v5.8.4: Handler Split, Margin-Based Splitting & Partner Data-Integrity Fixes — Replaced the `leader_gets_profit` toggle with a generalized, fully localized (EN/VI) 3-way split: Partner/Staff Split, Leader Split, and a new **Handler Split (Accounting)** for the person who actually processed the transaction (independent from Leader, since they're often different people). Leader/Handler income is now always computed from the exchange-rate margin — `(Own Payout Rate − Partner/Staff Rate) × USD × Percentage%` — with a per-section toggle to take a partial % or the full margin, never re-using the Partner/Staff's own USD. Added a `settlement_group_id` column on `UserPayment` (migration `2026_06_19_000000_add_settlement_group_id_to_user_payments`) — fixes the "Batch Selected" bulk-grouping action silently corrupting the Disbursement description's Account/Email/USD lookup for unrelated payments that happened to share a reassigned `batch_id`. Fixed Partner Withdrawal's Gift Card "Brand" dropdown returning empty (Brand lookup only checked `account_id`, which Partners don't have — now falls back to the linked `PartnerWithdrawal`'s platform, converting its free-text platform name to the matching `Platform` slug used by `Brand.platform`). Fixed Partner Withdrawal's auto-filled "Order Value" and dropdown label not deducting amounts already redeemed against the same request, and a double-counting bug where Exchange-to-VND liquidation children were summed alongside their parent. Made Rebate Tracker's "Rebate Amount ($)" an editable `TextInput` (was a read-only `Placeholder`) that still auto-calculates from Order Value × Cashback% — switched `order_value`/`cashback_percent` from `reactive()` to `live(onBlur: true)` to stop the input cursor jumping mid-type. Fixed the Account Status Tracking display on Rebate Tracker forms showing the wrong platform's status history when the same email is shared across Accounts on multiple platforms.
---

## 🔐 Security & Access Control

| Role | Emails | Accounts | Trackers | Payout Logs | Payout Methods | Expenses | Disbursement | Partner Withdrawals |
|------|--------|----------|----------|-------------|----------------|----------|--------------|---------------------|
| **Admin** | Full | Full | Full | Full | Full | Full | Full | Full + Generate Payout |
| **Finance** | View | View | Hidden | Full | Full | Full | Full | Full (status control) |
| **Staff** | Own only | Own only | Own only | Own only | Hidden | Hidden | View own | Hidden |
| **Partner** | Hidden | Hidden | Hidden | Hidden | Hidden | Hidden | Hidden | Own only (pending edit) |

---
<p align="center">Built for Excellence. Optimized for Profit.</p>
<p align="center"><b>© 2026 RebateOps System</b></p>
