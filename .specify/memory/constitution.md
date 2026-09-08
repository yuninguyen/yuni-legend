# RebateOps Constitution

## Core Principles

### I. Financial Correctness First

Financial correctness overrides convenience, UI shortcuts, and cosmetic simplification. Money-moving and balance-changing behavior must preserve authoritative accounting semantics, settlement linkage, and deterministic recomputation.

### II. Atomic State Transitions

Financial read-compute-write operations MUST execute inside the required database transaction and acquire the required `lockForUpdate()` scope before authoritative recomputation and writes. Concurrency-sensitive calculations performed outside the required lock are not acceptable.

### III. No Double Counting

For one economic group, `user_payment_id` is stamped on the parent OR on liquidation child/children, never both. Gift Card liquidation MUST NOT exceed the original economic value. Partner/Staff payout and Leader/Handler margin income are separate layers and MUST NOT reuse the same economic value incorrectly.

### IV. Immutable Settlement Provenance

`settlement_group_id` is immutable settlement-run provenance. `batch_id` is editable operational grouping. Regrouping batches MUST NOT corrupt Account/Email/USD provenance or historical settlement linkage.

### V. Least Privilege

Admin, Finance, Staff/Operator, and Partner boundaries MUST be enforced server-side through policy/gate/query ownership behavior. UI visibility alone is never authorization.

### VI. Database Is Authoritative

Google Sheets is an integration surface. Sheet import/export and sync MUST NOT bypass database financial rules, duplicate/conflict prevention, ownership rules, or auditability.

### VII. Sensitive Data Safety

Secrets, decrypted credentials, 2FA values, security answers, service-account credentials, and unnecessary PII MUST NOT be logged, committed, exposed in exports, or added to fixtures/screenshots.

### VIII. Auditable and Recoverable Changes

Financially material state transitions should remain auditable. Delete/restore behavior and destructive schema changes must preserve recoverability. Destructive financial migrations require explicit approval and a backup/migration/verification plan.

### IX. Testable Changes

Behavior changes require verifiable acceptance criteria. Financial-critical work should use focused regression tests and meaningful RED → GREEN where applicable. Environment/tool failures are not semantic RED.

### X. Surgical Implementation

Implement the smallest change that satisfies the approved requirement. Do not perform unrelated refactors, speculative abstractions, or architecture expansion during a focused financial fix.

## Workflow Governance

For new features or meaningful behavior changes:

```text
AGENTS.md / authority review
→ Spec-Kit specify
→ clarify when required
→ plan
→ tasks
→ PLAN-REVIEW.md acceptance
→ implementation
→ verification
→ HANDOFF.md when pausing/transferring
```

`PLAN-REVIEW.md` BLOCKER findings must be resolved before implementation.

Small, low-risk fixes do not require full Spec-Kit but still obey GitNexus impact rules and all hard financial/security invariants.

## Database Compatibility

RebateOps currently represents MySQL/MariaDB production, SQLite local/test, and existing PostgreSQL-compatible query work. Prefer framework-level query/schema APIs where adequate and identify engine-specific impact before introducing raw SQL.

## Governance

This constitution is subordinate to explicit current user decisions and the hard safety/integrity rules in `AGENTS.md`. Amendments must not silently weaken financial correctness, least privilege, provenance, or sensitive-data guarantees.

**Version**: 1.0.0  
**Ratified**: 2026-09-09  
**Last Amended**: 2026-09-09
