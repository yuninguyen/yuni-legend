# RebateOps — Claude Code Instructions

## 1. Mandatory Read Order

Before non-trivial work:

```text
AGENTS.md
→ CLAUDE.md
→ BUILD.md
→ relevant current code/tests/docs
```

For features, meaningful behavior changes, or financial-critical work, also read the active Spec-Kit artifacts and `PLAN-REVIEW.md` before implementation. Use `HANDOFF.md` when pausing or transferring substantial work.

`AGENTS.md` wins on project policy unless the user explicitly approves a newer rule.

## 2. Skill Families

This repository has separate workflow families. Do not confuse them.

### Spec-Kit — feature specification/execution

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

### RebateOps domain skills

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

### Superpowers / ECC

Superpowers commands are a separate plugin/workflow family and do not share Spec-Kit state. Do not confuse `/speckit-plan` with a generic `/plan` or `/executing-plans` workflow. `.superpowers/` may contain generated SDD artifacts, not the source of RebateOps financial authority.

## 3. Spec-Kit Auto-Activation

For a new feature or meaningful behavior change, automatically run:

```text
financial/security eligibility check
→ speckit-specify
→ speckit-clarify when needed
→ speckit-plan
→ speckit-tasks
→ speckit-analyze/checklist when relevant
→ PLAN-REVIEW.md acceptance
→ speckit-implement only after approval
→ verification
```

Do not wait for the user to manually invoke every step.

Do not force full Spec-Kit for typo/copy changes, tiny Filament styling, obvious one-line fixes, straightforward low-risk bugs, or narrow documentation-only edits.

## 4. PLAN-REVIEW Is a Gate

Before `speckit-implement` for feature/meaningful/financial-critical work:

- review `spec.md`, `plan.md`, and `tasks.md` using `PLAN-REVIEW.md`;
- resolve BLOCKER/HIGH issues that prevent safe implementation;
- record the exact implementation authorization scope.

Only `APPROVED FOR IMPLEMENTATION` authorizes execution of that reviewed scope. Approval does not waive `AGENTS.md`, GitNexus, tests, locks, RBAC, migration safety, or secret handling.

## 5. Domain Skill Auto-Selection

Use `rebateops-financial-safety` when touching payout, settlement, exchange/liquidation, balances, profit/margin, locks, financial delete/restore, or financial RBAC.

Use `rebateops-google-sync` when touching `GoogleSheetService`, `GoogleSyncService`, sync Jobs/observers, Sheet import/export, mapping, conflict handling, or sync retries.

Use `rebateops-filament-rbac` when touching Filament Resources/Actions/Policies/Gates/role visibility or ownership.

Use `rebateops-financial-migrations` for financial schema changes, raw SQL, cross-engine query behavior, backfills, or destructive migration risk.

Multiple skills may apply to one task.

## 6. TDD for Financial-Critical Work

```text
reproduce/define the financial failure
→ focused regression test
→ observe semantic RED
→ verify transaction/lock design
→ minimum implementation
→ GREEN
→ related financial/policy/concurrency/sync regressions
```

Do not count tool failures, permissions issues, missing commands, or unrelated ENOENT as RED.

## 7. GitNexus Workflow

Before editing every function/class/method:

```text
gitnexus_impact(target, upstream)
```

For unfamiliar flows:

```text
gitnexus_query(concept)
→ gitnexus_context(symbol)
```

For HIGH/CRITICAL impact, report the blast radius before editing. Before commit/completion run `gitnexus_detect_changes()`.

Use the installed GitNexus skills under `.claude/skills/gitnexus/` for exploration, impact analysis, debugging, refactoring, CLI, and reference guidance.

## 8. Financial Change Checklist

Before writing financial code, determine:

```text
economic group
authoritative rows
editable vs immutable fields
transaction boundary
lockForUpdate scope
values recomputed inside lock
double-counting risk
parent/child settlement linkage
settlement_group_id provenance
retry/idempotency behavior
role/ownership boundary
delete/restore impact
```

If any material answer is uncertain, inspect current code/tests before editing.

## 9. Google Sheets / Filament / Database

For Sheet changes, preserve loop prevention, duplicate/conflict rules, database validation, and fake external APIs in normal tests.

For Filament changes, UI visibility is not authorization; verify Policy/Gate/server-side ownership, including bulk/relation/modal paths.

For migrations, assess production MySQL/MariaDB, local/test SQLite, and existing PostgreSQL-compatible behavior. Destructive financial migrations require explicit user approval and a backup/migration/rollback plan.

## 10. Security Stop Conditions

Stop before implementation if a request would:

- expose decrypted passwords/2FA/tokens/PII in logs or public UI;
- weaken Finance/Staff/Partner ownership boundaries;
- replace DB-backed delete protections with UI-only guards;
- remove required transactions/locks from money-changing logic;
- permit duplicate settlement/liquidation or double-counting parent/child links;
- treat `batch_id` as immutable settlement provenance;
- index the internal admin app publicly;
- write to production Sheets/databases during tests without explicit authorization.

## 11. Handoff

When pausing/transferring substantial work, update `HANDOFF.md` with:

- status, branch/HEAD/worktree;
- active spec/plan/tasks/review verdict;
- skills used;
- GitNexus impact/change scope;
- tests actually run;
- missing evidence/blockers;
- next exact step and explicit DO NOT DO items.

Do not mark work COMPLETE when required verification is missing.

## 12. Small Tasks

For a clear low-risk task, do not ask for approval at every obvious step. Make the smallest change, verify the affected surface, and report the result. Ask only for material ambiguity, destructive operations, financial/security policy changes, or HIGH/CRITICAL impact.

Karpathy Coding Hygiene and mandatory GitNexus rules are inherited from `AGENTS.md`.

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **yuni-legend** (36602 symbols, 62149 relationships, 300 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/yuni-legend/context` | Codebase overview, check index freshness |
| `gitnexus://repo/yuni-legend/clusters` | All functional areas |
| `gitnexus://repo/yuni-legend/processes` | All execution flows |
| `gitnexus://repo/yuni-legend/process/{name}` | Step-by-step execution trace |

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
