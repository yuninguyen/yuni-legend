---
name: "rebateops-financial-safety"
description: "Review and implement RebateOps financial-critical changes without breaking settlement, balance, locking, provenance, or double-counting invariants."
user-invocable: true
disable-model-invocation: false
---

# RebateOps Financial Safety

Use this skill whenever a task touches:

```text
PayoutLog
PayoutMethod
UserPayment
PartnerWithdrawal
exchange/liquidation
settlement
wallet balance
payout rates
profit/margin
record locking
financial delete/restore behavior
```

## Required Analysis

Before editing, identify:

1. economic group;
2. authoritative rows;
3. editable vs immutable fields;
4. transaction boundary;
5. `lockForUpdate()` scope;
6. values recomputed inside the lock;
7. retry/idempotency behavior;
8. role/ownership boundary;
9. delete/restore consequences;
10. regression tests proving the behavior.

## Non-Negotiable Invariants

### Wallet balance

Current accounting meaning:

```text
completed withdrawal → + net_amount_usd
completed liquidation → - amount_usd
```

Prefer deterministic recomputation from authoritative completed rows.

### Parent / child settlement

For one economic group:

```text
user_payment_id on parent
OR
user_payment_id on liquidation child/children
NEVER BOTH
```

Do not create a shape that double-counts linked USD.

### Settlement provenance

```text
settlement_group_id = immutable settle-run provenance
batch_id = editable grouping/bulk-pay workflow
```

Never substitute `batch_id` for settlement provenance lookup.

### Liquidation

```text
sum(liquidation amounts) <= original face value
```

Prevent duplicate/full liquidation and settled-value reuse.

### Split layers

Leader/Handler margin income is not Partner/Staff payout income.

```text
(own payout rate - partner/staff payout rate) × USD × percentage
```

Do not reuse the same economic value across both layers.

## Implementation Shape

For money-changing read/compute/write logic:

```text
validate
→ DB transaction
→ acquire required row/group locks
→ reload/recompute authoritative values inside lock
→ atomic write
→ commit
```

Reject designs where the critical calculation is performed before the required lock and then written later.

## TDD

Use a focused regression test first. RED must be semantic, not a missing tool, permissions issue, unrelated `ENOENT`, or process launch failure.

When applicable, run/extend:

```text
FinancialDeleteRestrictionTest
FinancialPolicyTest
PayoutLogExchangeTest
```

## Completion

Before reporting success:

- run relevant financial tests;
- verify policy/RBAC impact;
- verify transaction/lock scope;
- run GitNexus change detection;
- inspect the diff for accidental accounting changes.
