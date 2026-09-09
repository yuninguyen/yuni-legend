# RebateOps — Build Execution Playbook

This file defines the default implementation workflow for RebateOps.

## 1. Read Order

```text
AGENTS.md
→ CLAUDE.md (Claude only)
→ BUILD.md
→ relevant current code/tests/docs
```

For feature/meaningful/financial-critical work, use the installed `.claude/skills/` system and the active `.specify/` feature artifacts.

## 2. Installed Skills

### Spec-Kit

```text
speckit-specify
speckit-clarify
speckit-plan
speckit-tasks
speckit-analyze
speckit-checklist
speckit-implement
speckit-constitution
speckit-converge
speckit-taskstoissues
```

### RebateOps

```text
rebateops-financial-safety
rebateops-google-sync
rebateops-filament-rbac
rebateops-financial-migrations
```

### GitNexus

```text
gitnexus-exploring
gitnexus-impact-analysis
gitnexus-debugging
gitnexus-refactoring
gitnexus-guide
gitnexus-cli
```

## 3. Task Classification

### Small fix

Examples: typo/copy, tiny Filament styling, obvious one-line bug, narrow non-financial docs.

```text
scope
→ GitNexus impact when editing symbols
→ minimal change
→ focused verification
→ gitnexus_detect_changes
```

Do not force full Spec-Kit.

### New feature / meaningful behavior change

```text
eligibility + invariant check
→ speckit-specify
→ speckit-clarify if needed
→ speckit-plan
→ speckit-tasks
→ speckit-analyze/checklist as relevant
→ PLAN-REVIEW.md
→ APPROVED FOR IMPLEMENTATION
→ speckit-implement
→ verification
→ HANDOFF.md if paused/transferred
```

Do not run `speckit-implement` while plan review has unresolved blocking findings.

## 4. Financial-Critical Build Rule

Treat payout/settlement/exchange/balance/profit/financial-RBAC/financial-migration/financial-sync changes as financial-critical. Use `rebateops-financial-safety` plus any other applicable domain skill.

Required shape:

```text
reproduce/define failure
→ focused regression test
→ meaningful RED
→ transaction/lock design review
→ minimum implementation
→ GREEN
→ related financial/policy/concurrency/sync regressions
```

Never count environment/tool failure as semantic RED.

## 5. GitNexus Gate

Before modifying a function/class/method:

```text
gitnexus_impact(target, upstream)
```

For unfamiliar code:

```text
gitnexus_query(concept)
→ gitnexus_context(symbol)
```

HIGH/CRITICAL blast radius must be reported before editing. Before commit/completion run `gitnexus_detect_changes()`.

## 6. Database / Migration Gate

Use `rebateops-financial-migrations`.

- assess MySQL/MariaDB production impact;
- assess SQLite local/test impact;
- preserve current PostgreSQL-compatible paths;
- prefer Eloquent/query builder over dialect-specific SQL;
- destructive financial migrations require explicit approval + backup/migration/rollback strategy.

## 7. Google Sheets Gate

Use `rebateops-google-sync`.

- identify DB→Sheet, Sheet→DB, or bidirectional scope;
- preserve loop prevention and duplicate/conflict rules;
- never bypass DB financial invariants;
- fake external API/queues in normal automated tests.

## 8. Authorization Gate

Use `rebateops-filament-rbac`.

- UI visibility is not authorization;
- verify Policy/Gate/server-side ownership;
- bulk/relation/modal actions need the same protections as single-record actions.

## 9. Definition of Done

Financial-critical work normally requires:

```text
focused tests
relevant policy tests
relevant financial/concurrency/accounting/sync tests
php artisan test when scope permits
vendor/bin/pint --test
npm run build if frontend/assets changed
gitnexus_detect_changes
diff review
```

Do not claim success from manual UI behavior alone.
