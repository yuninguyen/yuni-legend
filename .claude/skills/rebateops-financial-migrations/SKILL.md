---
name: "rebateops-financial-migrations"
description: "Plan and implement RebateOps schema/query migrations safely across production MySQL/MariaDB, local SQLite, and existing PostgreSQL-compatible code paths."
user-invocable: true
disable-model-invocation: false
---

# RebateOps Financial Migrations

Use whenever changing tables, columns, indexes, constraints, casts, financial query semantics, or data backfills.

## Supported Environment Reality

Repository evidence currently represents:

```text
Production example: MySQL / MariaDB
Local/test default: SQLite
Existing code/history: PostgreSQL compatibility work
```

Do not introduce a database-specific dependency casually.

## Before Editing

Identify:

1. production engine impact;
2. SQLite test/local impact;
3. PostgreSQL-compatible path impact if the touched query currently supports it;
4. existing data volume/shape assumptions;
5. null/default/backfill behavior;
6. rollback/recovery path;
7. index/constraint deployment risk;
8. whether financial rows are modified in-place.

## Preferred Rules

- Prefer Laravel schema builder/Eloquent/query builder when adequate.
- Additive/reversible migrations are preferred.
- Keep schema constraints aligned with application financial invariants.
- If raw SQL is required, document engine assumptions and test the relevant engines.
- Do not silently change money precision/scale or cast behavior.

## Destructive Financial Migration Stop Rule

For dropping/renaming/retyping populated financial columns, rewriting settlement provenance, or destructive data backfills:

```text
STOP
→ explicit user approval
→ backup/export strategy
→ migration/backfill plan
→ verification/rollback plan
```

Do not infer approval from a broad feature request.

## Data Backfills

Backfills must be deterministic and idempotent where practical. Never fabricate settlement provenance or infer financial linkage without a reliable source rule.

`settlement_group_id` is immutable settlement provenance; `batch_id` is not a replacement source for it.

## Tests

When relevant:

- migrate from a realistic pre-change state;
- test constraints and relationship preservation;
- test financial calculations after migration;
- test delete restrictions;
- test on the engine relevant to deployment.

If concurrency/accounting semantics are affected, also use `rebateops-financial-safety`.

## Completion

Before finishing:

- migration runs cleanly;
- rollback/recovery behavior is understood;
- financial tests pass;
- query compatibility is checked;
- GitNexus change detection matches the intended scope.
