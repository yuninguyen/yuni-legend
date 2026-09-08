# RebateOps — Plan Review Gate

Use this file to review any implementation plan before execution when the task is a new feature, a meaningful behavior change, or financial-critical work.

## Verdicts

Return exactly one implementation verdict:

```text
APPROVED FOR IMPLEMENTATION
REQUEST CHANGES
```

Approval means the plan may proceed to implementation. It does not waive `AGENTS.md`, tests, GitNexus impact analysis, financial locks, RBAC, or migration safety.

## 1. Scope & Authority

Check:

- plan matches the user's requested scope;
- no unrelated refactor or speculative feature;
- active README/current code/tests are not contradicted without an explicit decision;
- historical `docs/PLAN-*.md` files are not treated as current authority automatically;
- small fixes are not over-engineered into a broad rewrite.

## 2. Financial Model

For financial-critical plans, identify explicitly:

```text
economic group
authoritative rows
editable vs immutable fields
lock scope
transaction scope
recomputed values
idempotency / retry behavior
delete / restore behavior
```

Reject plans that leave material accounting semantics implicit.

## 3. Double-Counting / Settlement Safety

Verify:

- settlement does not link both parent and liquidation children in a double-counting shape;
- `settlement_group_id` remains immutable provenance;
- `batch_id` remains editable grouping only;
- duplicate/full liquidation is prevented;
- settled records cannot regain destructive actions through another resource path;
- Partner/Staff payout is not reused as Leader/Handler margin income.

## 4. Concurrency

For balance/settlement/exchange changes, verify the plan describes:

```text
DB transaction
→ required lockForUpdate() rows/group
→ authoritative recomputation inside lock
→ atomic writes
```

Reject read-compute-write plans that perform the critical calculation outside the lock.

## 5. RBAC / Filament

Verify:

- server-side Policy/Gate checks remain authoritative;
- Admin/Finance/Staff/Operator/Partner boundaries are preserved;
- hiding/disabling a UI action is not used as the only security control;
- bulk/relation/modal action paths are considered when relevant.

## 6. Google Sheets

If sync is affected, verify:

- direction is identified: DB→Sheet, Sheet→DB, or both;
- loop prevention remains intact;
- conflict/duplicate behavior is explicit;
- external sync cannot bypass database financial validation;
- tests fake external APIs/queues unless a designated integration environment is explicitly requested.

## 7. Database / Migration Safety

Verify:

- MySQL/MariaDB production behavior is considered;
- SQLite local/test behavior is considered;
- current PostgreSQL-compatible code is not accidentally broken;
- raw SQL is justified when used;
- destructive financial migrations have explicit approval + backup/migration/rollback strategy.

## 8. Sensitive Data / Audit

Verify the plan does not:

- log decrypted passwords, 2FA, security answers, tokens, service-account data, or PII unnecessarily;
- expose sensitive fields in tables/exports/activity logs;
- remove materially useful audit logging without justification.

## 9. Testing Strategy

Financial-critical plans should include focused tests covering the failure/behavior, plus relevant existing regression anchors such as:

```text
FinancialDeleteRestrictionTest
FinancialPolicyTest
PayoutLogExchangeTest
```

The plan must define a meaningful RED where TDD is used. Tool/environment failures do not count as RED.

## 10. Skill Selection

Confirm the plan uses relevant installed skills when applicable:

```text
speckit-specify / clarify / plan / tasks / implement
speckit-analyze
speckit-checklist
rebateops-financial-safety
rebateops-google-sync
rebateops-filament-rbac
rebateops-financial-migrations
gitnexus impact/debug/refactor skills
```

## Review Output

Use this structure:

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

IMPLEMENTATION AUTHORIZATION:
- exact allowed scope
```

If there are no blockers/high issues that prevent safe implementation, approve without inventing new architecture requirements.
