# PLAN: Third-party Partner Withdrawal Management (Outsourcing)

This plan outlines the implementation of a new system to manage withdrawal requests from external partners. Partners provide account credentials, and Finance/Admin users handle the login and withdrawal process.

## 🟢 1. Database Schema Updates

### [NEW] `partner_withdrawals` table
- `id`: Primary Key
- `partner_id`: Foreign Key (users.id, role='partner')
- `platform`: String (e.g., Rakuten, TopCashback)
- `email`: String
- `email_password`: Text (Encrypted)
- `recovery_email`: String (Nullable)
- `two_fa`: Text (Encrypted, Nullable)
- `platform_password`: Text (Encrypted)
- `amount_usd`: Decimal (12, 2)
- `status`: Enum (`pending`, `processing`, `completed`, `wrong_pass`, `banned`) - Default: `pending`
- `assigned_to`: Foreign Key (users.id) - Optional, to track which Finance handled it.
- `note`: Text (Nullable)
- `created_at`, `updated_at`: Timestamps

### [MODIFY] `users` table
- Add `partner` to role options (if not already handled via string).

### [MODIFY] `payout_logs` table
- Ensure it can be linked to a `partner_withdrawal_id` (optional, for traceability).

---

## 🔵 2. Model & Logic (Backend)

### `User` Model
- Add `isPartner()` helper method.

### `PartnerWithdrawal` Model
- Cast password fields to `encrypted` (Laravel native).
- Define relationships: `partner()`, `assignedTo()`.

---

## 🟡 3. Filament Resource: `PartnerWithdrawalResource`

### Form
- **Partner Selection**: Filter users by role `partner`.
- **Credential Section**:
    - `email`, `email_password` (password type), `recovery_email`, `two_fa`.
    - `platform`, `platform_password` (password type).
- **Financial Section**:
    - `amount_usd`.
- **Status Section**:
    - `status` (Select).

### Table
- Columns: `partner.name`, `platform`, `amount_usd`, `status`, `created_at`.
- **Quick Status Edit**: Use `SelectColumn` or a custom action for fast status switching (`pending` -> `processing` -> `completed`).
- **Access Control**:
    - `canViewAny`: Admin, Finance.
    - `canCreate`: Admin, Finance, Partner (Partners can only create/view their own).

---

## 🟣 4. Financial Workflow (Settlement)

### Withdrawal Generation
- When status changes to `completed`, trigger a modal/action to "Generate Payout Log".
- This creates a `PayoutLog` record:
    - `user_id`: The Partner.
    - `amount_usd`: The amount withdrawn.
    - `transaction_type`: `withdrawal`.
    - `status`: `pending` (to be liquidated/settled later).

### Profit Calculation Logic
- The system will use:
    - **Market Exchange Rate**: For internal accounting.
    - **Partner Deal Rate**: For calculating how much VND to pay the partner.
- Profit = `(Market Rate - Partner Deal Rate) * Amount USD`.

---

## 🔴 5. Reporting & Dashboards

- **AdminUserEarningsTable**: Update to ensure Partner data is excluded from internal staff payroll reports.
- **New Partner Report (Optional)**: A separate widget or page to track partner profitability.

---

## ✅ Verification Plan

### Automated Tests
- Test encryption/decryption of partner passwords.
- Test access control (Verify Staff cannot see PartnerWithdrawalResource).
- Test PayoutLog generation from a completed PartnerWithdrawal.

### Manual Verification
- Create a Partner user.
- Log in as Partner and submit a withdrawal request.
- Log in as Finance, view credentials (decrypted), and change status to `processing`.
- Mark as `completed` and verify the `PayoutLog` is created with the correct Partner ID.
