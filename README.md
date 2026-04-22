# 📊 RebateOps — Enterprise Cashback Management System

RebateOps is a professional, high-performance internal tool built with **Laravel 12** and **Filament 3** for managing large-scale cashback operations across multiple platforms. It combines a premium SaaS aesthetic with rigorous financial integrity and real-time Google Sheets synchronization.

![Laravel](https://img.shields.io/badge/Laravel-12.0-FF2D20?style=for-the-badge&logo=laravel)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php)
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

### 🛡️ Financial Integrity & Security
- **Smart Record Locking**: Automated locking of Payout Logs once they are generated into a Disbursement. Parents are intelligently locked only when their child transactions are fully settled.
- **Data Integrity Safeguards**: Restricted bulk actions (Delete, Mark as Completed) for settled records to prevent accidental financial discrepancies.
- **Pessimistic Locking**: Prevents race conditions on wallet balances using `lockForUpdate()`. Exchange and Settlement actions lock the full economic group (parent + liquidation children) before computing totals — concurrent requests block on the lock rather than racing.
- **Atomic Transactions**: All balance changes follow a strict safety pattern within DB transactions.
- **Safe Soft-Delete Cascade**: Soft-deleting a `UserPayment` (Disbursement) automatically unlinks all associated `PayoutLog` rows (`user_payment_id → null`), preventing logs from being stranded as settled with no recovery path.
- **Advanced Data Recovery**: **SoftDeletes** implemented across all core models, with "Restore" and "Force Delete" capabilities for authorized Admins.
- **At-Rest Encryption**: Sensitive data (Gift Card codes, passwords) are encrypted using Laravel's native encryption.

### 🔄 Automation & UX
- **Composite Grouping**: Advanced table grouping in Payout Logs by **Account + Brand**, providing a clear separated view for multi-brand accounts.
- **Contextual UI**: "Exchange to VND" link intelligently disappears once a record is fully liquidated, preventing duplicate transactions.
- **Queue-Powered Sync**: Real-time bidirectional sync with Google Sheets (3x retry, 60s backoff).
- **Smart Formatting**: Automatic sheet tab creation, frozen headers, and status-based conditional coloring.
- **Language-Independent Nav**: Strict sidebar hierarchy (Dashboard → Resource → Work → Wallet → Settings → Logs) enforced regardless of active locale.
- **Activity Logging**: Full audit trail for Admin oversight on every data mutation.

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

---

## 🗂️ Core Architecture

```
REBATEOPS
├── RESOURCE HUB          # Central Assets (Admin & Finance Parity)
│   ├── Emails            # Linked email directory
│   └── Accounts          # Account management
├── WORKING SPACE         # Operations (Hidden from Finance)
│   ├── Rebate Trackers   # Order tracking
├── WALLET & PAYOUTS      # Financial Layer
│   ├── Payout Logs       # Withdrawals & Liquidations
│   ├── Payout Methods    # Virtual Wallets
│   └── Disbursement      # Payroll
├── LOGS                  # Audit Trail (Admin only)
│   └── Activity Logs     # System audit
└── SETTINGS              # System Core (Admin only)
    ├── Users             # User Management
    ├── Platforms         # Platform Configuration
    └── Brands            # Brand Management
```

---

## 🛠️ Technical Setup

### Requirements
- **PHP** 8.2+
- **Composer** & **Node.js**
- **SQLite** (Dev) or **MySQL** (Prod)
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
3. **Database**:
   ```bash
   touch database/database.sqlite
   php artisan migrate --force
   ```
4. **Google Auth**:
   Place your service account JSON at `storage/app/google-auth.json`.

---

## 👨‍💻 Roadmap
- [x] v5.0: Advanced Financial Locking & SoftDeletes
- [x] v5.1: Finance Role & Advanced RBAC Overhaul
- [x] v5.2: Smart Payout Locking & Multi-Brand Grouping Logic
- [x] v5.3: Core Localization (VI/EN) & UI Density Optimization
- [x] v5.4: Advanced Data Recovery (Restore / Force Delete)
- [x] v5.4.1: Security Patch — Policy registration, operator precedence, cascade forceDelete, email validation
- [x] v5.4.2: Concurrency Hardening — `lockForUpdate()` on Exchange & Settlement group scope; soft-delete cascade unlinks PayoutLogs
- [ ] v5.5: Automated Profit/Loss Analytics
- [ ] v5.6: Bulk Image Processing for Payment Proofs
- [ ] v5.7: REST API for External Automation
---

## 🔐 Security & Access Control

| Role | Emails | Accounts | Trackers | Payout Logs | Payout Methods | Disbursement |
|------|--------|----------|----------|-------------|----------------|--------------|
| **Admin** | Full | Full | Full | Full | Full | Full |
| **Finance** | View | View | Hidden | Full | Full | Full |
| **Staff** | Own only | Own only | Own only | Own only | Hidden | View own |

---
<p align="center">Built for Excellence. Optimized for Profit.</p>
<p align="center"><b>© 2026 RebateOps System</b></p>
