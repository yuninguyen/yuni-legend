# RebateOps — Build Execution Playbook

This file defines the default implementation workflow for RebateOps.

## 1. Read Order

Before non-trivial implementation, read:

```text
AGENTS.md
→ CLAUDE.md (Claude only)
→ BUILD.md
→ relevant current code/tests/docs
```

For new features or meaningful behavior changes, also use the installed Spec-Kit skills under `.claude/skills/`.

## 2. Classify the Task

### Small fix

Examples:

- typo/copy fix;
- tiny Filament styling adjustment;
- one-line obvious bug;
- narrow non-financial documentation edit.

Workflow:

```text
scope
→ GitNexus impact when editing symbols
→ minimal change
→ focused verification
→ gitnexus_detect_changes
```

Do not force full Spec-Kit for these.

### Feature / meaningful behavior change

Workflow:

```text
financial/security eligibility check
→ speckit-specify
→ speckit-clarify when needed
→ speckit-plan
→ speckit-tasks
→ PLAN-REVIEW.md acceptance review
→ speckit-implement
→ verification
→ HANDOFF.md update when handing off or pausing
```

Do not run `speckit-implement` while the plan review has unresolved BLOCKER findings.

## 3. Financial-Critical Build Rule

Treat changes touching the following as financial-critical:

```text
PayoutLog
PayoutMethod
UserPayment
PartnerWithdrawal
exchange / liquidation
settlement
wallet balance
payout rates
profit / margin
record locking
financial RBAC
Google Sheets sync that can change financial state
```

Required shape:

```text
reproduce/define failure
→ focused regression test
→ meaningful RED
→ transaction/lock design review
→ minimum implementation
→ GREEN
→ related financial/policy regression tests
```

Never count environment/tool failures as semantic RED.

## 4. GitNexus Gate

Before modifying a function/class/method:

```text
gitnexus_impact(target, upstream)
```

For unfamiliar code:

```text
gitnexus_query(concept)
→ gitnexus_context(symbol)
```

HIGH/CRITICAL blast radius must be reported before editing.

Before commit/completion:

```text
gitnexus_detect_changes()
```

## 5. Database / Migration Gate

For schema/query changes:

- assess MySQL/MariaDB production impact;
- assess SQLite test/local impact;
- preserve PostgreSQL-compatible paths where current code supports them;
- prefer Eloquent/query builder over dialect-specific SQL;
- destructive financial migrations require explicit approval, backup/migration strategy, and verification.

Use `.claude/skills/rebateops-financial-migrations/SKILL.md`.

## 6. Google Sheets Gate

For Sheet sync changes:

- identify DB→Sheet, Sheet→DB, or bidirectional scope;
- preserve loop prevention;
- preserve duplicate/conflict rules;
- never bypass database financial invariants;
- fake external API/queues in normal automated tests.

Use `.claude/skills/rebateops-google-sync/SKILL.md`.

## 7. Authorization Gate

For Filament/resources/actions:

- UI visibility is not authorization;
- verify Policy/Gate/server-side ownership rules;
- bulk actions need the same financial integrity as single-record actions.

Use `.claude/skills/rebateops-filament-rbac/SKILL.md`.

## 8. Definition of Done

A task is complete only when the verification appropriate to its scope passes.

Financial-critical work normally requires:

```text
focused tests
relevant policy tests
relevant financial/concurrency/accounting tests
php artisan test when scope permits
vendor/bin/pint --test
npm run build if frontend/assets changed
gitnexus_detect_changes
diff review
```

Do not claim success from manual UI behavior alone.
