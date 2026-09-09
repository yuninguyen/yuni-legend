# RebateOps — Plan Review Gate

Use this file before implementing a new feature, meaningful behavior change, or financial-critical change.

## Verdict

Return exactly one:

```text
APPROVED FOR IMPLEMENTATION
REQUEST CHANGES
```

Approval authorizes only the reviewed scope. It does not waive `AGENTS.md`, `BUILD.md`, tests, GitNexus, financial locks, RBAC, migration safety, audit rules, or secret handling.

## 1. Scope / Authority

Verify:

- plan matches requested scope;
- no unrelated refactor/speculative feature;
- current README/code/tests are not contradicted without an explicit decision;
- historical `docs/PLAN-*.md` are not treated as current authority automatically;
- active spec/plan/tasks are mutually consistent.

## 2. Skill Selection

Confirm applicable installed skills are selected:

```text
Spec-Kit:
  speckit-specify / clarify / plan / tasks / analyze / checklist / implement
  speckit-converge when reconciling review feedback

RebateOps:
  rebateops-financial-safety
  rebateops-google-sync
  rebateops-filament-rbac
  rebateops-financial-migrations

GitNexus:
  exploring / impact-analysis / debugging / refactoring / guide / cli
```

Missing an applicable domain skill is a review finding when it leaves a material risk unaddressed.

## 3. Financial Model

For financial-critical plans, identify explicitly:

```text
economic group
authoritative rows
editable vs immutable fields
transaction scope
lockForUpdate scope
values recomputed inside lock
idempotency/retry behavior
delete/restore behavior
```

Reject plans that leave material accounting semantics implicit.

## 4. Double Counting / Settlement Safety

Verify:

- parent and liquidation children are not linked in a double-counting shape;
- `settlement_group_id` remains immutable provenance;
- `batch_id` remains editable grouping only;
- duplicate/full liquidation is prevented;
- settled records cannot regain destructive actions via another resource path;
- Partner/Staff payout is not reused as Leader/Handler margin income.

## 5. Concurrency

For balance/settlement/exchange changes, require:

```text
DB transaction
→ required lockForUpdate rows/group
→ authoritative recomputation inside lock
→ atomic writes
```

Reject critical read-compute-write logic outside required locking.

## 6. RBAC / Filament

Verify:

- Policy/Gate/server-side checks remain authoritative;
- Admin/Finance/Staff/Operator/Partner boundaries are preserved;
- UI hiding/disabling is not the only security control;
- bulk/relation/modal alternate paths are considered.

## 7. Google Sheets

If sync is affected, verify:

- direction is explicit: DB→Sheet, Sheet→DB, or both;
- loop prevention remains intact;
- duplicate/conflict behavior is explicit;
- Sheet operations cannot bypass DB financial validation;
- automated tests fake APIs/queues unless a designated integration environment is explicitly requested.

## 8. Database / Migration

Verify:

- MySQL/MariaDB production impact;
- SQLite local/test impact;
- current PostgreSQL-compatible code is not accidentally broken;
- raw SQL is justified;
- destructive financial migrations have explicit approval + backup/migration/rollback strategy.

## 9. Sensitive Data / Audit

Verify the plan does not expose/log decrypted credentials, 2FA, tokens, service-account contents, unnecessary PII, or remove materially useful audit logging without justification.

## 10. Testing Strategy

Financial-critical plans should include a focused semantic RED plus relevant regression anchors such as:

```text
FinancialDeleteRestrictionTest
FinancialPolicyTest
PayoutLogExchangeTest
```

Tool/environment failures do not count as RED.

## Review Output

```text
VERDICT: APPROVED FOR IMPLEMENTATION | REQUEST CHANGES

BLOCKERS:
- ...

HIGH:
- ...

MEDIUM:
- ...

CONFIRMED INVARIANTS:
- ...

SKILLS REQUIRED:
- ...

IMPLEMENTATION AUTHORIZATION:
- exact allowed scope
```

If no blocker/high issue prevents safe implementation, approve without inventing new architecture requirements.
