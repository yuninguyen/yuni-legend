# RebateOps — Claude Code Instructions

## 1. Read AGENTS.md First

Before any non-trivial task:

1. read `AGENTS.md`;
2. treat its financial, security, authorization, sync, and data-integrity rules as mandatory;
3. then apply this file's Claude-specific workflow.

If `CLAUDE.md` conflicts with `AGENTS.md` on project policy, `AGENTS.md` wins unless the user explicitly approves a newer rule.

## 2. Authority

Do not invent a roadmap gate or architecture document that does not exist.

Current authority model:

```text
explicit current user decision
→ AGENTS.md hard invariants
→ README.md workflow/product intent
→ current schema/migrations/policies/models/services/tests
→ historical docs plans only when explicitly reactivated
```

For financial behavior, inspect current code and tests before planning a change.

## 3. Spec-Kit Auto-Activation

For a **new feature** or meaningful behavior change, use the Spec-Kit flow automatically **when Spec-Kit commands/skills are actually available in the current workspace**:

```text
financial/security eligibility check
↓
speckit-specify
↓
speckit-plan
↓
speckit-tasks
↓
speckit-implement
↓
verification
```

Do not wait for the user to manually invoke each step.

If Spec-Kit is not installed/available:

- do not pretend it ran;
- perform the equivalent Specify → Plan → Tasks → Implement workflow directly;
- keep generated planning artifacts minimal and inside repository conventions approved by the user.

### Before Spec-Kit or equivalent planning

Resolve:

1. which economic records/roles are affected;
2. whether settlement/balance/concurrency semantics change;
3. whether external Google Sheets behavior changes;
4. whether the task weakens any invariant from `AGENTS.md`.

If a conflict exists, stop before implementation and report it.

### Do not force full Spec-Kit for

- typo/copy fixes;
- tiny Filament styling;
- one-line fixes;
- straightforward low-risk bugs with obvious scope;
- documentation-only edits.

## 4. TDD for Financial-Critical Work

Financial-critical changes include anything affecting:

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
financial RBAC
```

Use:

```text
reproduce the financial failure
↓
write focused regression test
↓
observe semantic RED
↓
minimum implementation
↓
GREEN
↓
related regression suite
```

Do not accept environment/tool failures as RED.

When concurrency is involved, verify the lock/transaction scope rather than only the happy-path result.

## 5. GitNexus Workflow

The GitNexus rules in `AGENTS.md` are mandatory.

Before editing every function/class/method:

```text
gitnexus_impact(target, upstream)
```

For unfamiliar flows:

```text
gitnexus_query(concept)
→ gitnexus_context(symbol)
```

For HIGH/CRITICAL impact:

- report the blast radius before editing;
- do not silently proceed.

Before commit:

```text
gitnexus_detect_changes()
```

After commit, refresh the index while preserving embeddings according to the managed GitNexus instructions.

## 6. Financial Change Checklist

Before writing code, answer internally:

```text
What is the economic group?
What row(s) are authoritative?
What can be edited vs immutable?
What must be locked?
What is recomputed inside the lock?
Could this double-count?
Could this settle/link both parent and children?
Could batch regrouping corrupt provenance?
Could a retry/sync duplicate money/state?
Which role is allowed to do this?
What happens on delete/restore?
```

If any answer is uncertain, inspect the relevant flow before editing.

## 7. Google Sheets Changes

Before changing `GoogleSheetService`, `GoogleSyncService`, sync Jobs, observers, or import/export actions:

- identify direction: DB→Sheet, Sheet→DB, or both;
- preserve loop-prevention state;
- preserve unique/conflict rules;
- preserve financial validation;
- fake API/queue in automated tests;
- do not test against a live production sheet unless explicitly requested.

## 8. Filament Changes

For Filament Resources/Actions:

- do not infer security from visibility;
- verify Policy/Gate/server-side checks;
- financial bulk actions require the same locking/integrity guarantees as single-record actions;
- modal refactors must not change business semantics accidentally;
- maintain EN/VI labels for touched UI.

## 9. Database Changes

For migrations or query changes:

- inspect production MySQL/MariaDB impact;
- check whether SQLite tests or existing PostgreSQL-compatible paths are affected;
- prefer Eloquent/query builder over dialect-specific expressions;
- for destructive financial migrations, stop for explicit user approval and migration/backup plan.

## 10. Security Stop Conditions

Stop before implementation if a request would:

- expose decrypted passwords/2FA/PII in logs or public UI;
- weaken Finance/Staff/Partner ownership boundaries;
- remove DB-backed financial delete restrictions in favor of UI-only guards;
- remove required transaction/locking from money-moving actions;
- permit double settlement/liquidation;
- link settlement parent and liquidation children in a way that double-counts;
- treat `batch_id` as immutable settlement provenance;
- index the internal admin app publicly;
- write to production Sheets/databases during tests without explicit authorization.

## 11. Small Tasks

For a clear, low-risk, authorized task:

- do not ask for approval at every obvious step;
- make the smallest change;
- verify the affected surface;
- report the exact result.

Ask only for material ambiguity, destructive operations, security/financial policy changes, or HIGH/CRITICAL impact.

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **RebateOps** (6157 symbols, 18765 relationships, 300 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## When Debugging

1. `gitnexus_query({query: "<error or symptom>"})` — find execution flows related to the issue
2. `gitnexus_context({name: "<suspect function>"})` — see all callers, callees, and process participation
3. `READ gitnexus://repo/RebateOps/process/{processName}` — trace the full execution flow step by step
4. For regressions: `gitnexus_detect_changes({scope: "compare", base_ref: "main"})` — see what your branch changed

## When Refactoring

- **Renaming**: MUST use `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` first. Review the preview — graph edits are safe, text_search edits need manual review. Then run with `dry_run: false`.
- **Extracting/Splitting**: MUST run `gitnexus_context({name: "target"})` to see all incoming/outgoing refs, then `gitnexus_impact({target: "target", direction: "upstream"})` to find all external callers before moving code.
- After any refactor: run `gitnexus_detect_changes({scope: "all"})` to verify only expected files changed.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Tools Quick Reference

| Tool | When to use | Command |
|------|-------------|---------|
| `query` | Find code by concept | `gitnexus_query({query: "auth validation"})` |
| `context` | 360-degree view of one symbol | `gitnexus_context({name: "validateUser"})` |
| `impact` | Blast radius before editing | `gitnexus_impact({target: "X", direction: "upstream"})` |
| `detect_changes` | Pre-commit scope check | `gitnexus_detect_changes({scope: "staged"})` |
| `rename` | Safe multi-file rename | `gitnexus_rename({symbol_name: "old", new_name: "new", dry_run: true})` |
| `cypher` | Custom graph queries | `gitnexus_cypher({query: "MATCH ..."})` |

## Impact Risk Levels

| Depth | Meaning | Action |
|-------|---------|--------|
| d=1 | WILL BREAK — direct callers/importers | MUST update these |
| d=2 | LIKELY AFFECTED — indirect deps | Should test |
| d=3 | MAY NEED TESTING — transitive | Test if critical path |

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/RebateOps/context` | Codebase overview, check index freshness |
| `gitnexus://repo/RebateOps/clusters` | All functional areas |
| `gitnexus://repo/RebateOps/processes` | All execution flows |
| `gitnexus://repo/RebateOps/process/{name}` | Step-by-step execution trace |

## Self-Check Before Finishing

Before completing any code modification task, verify:
1. `gitnexus_impact` was run for all modified symbols
2. No HIGH/CRITICAL risk warnings were ignored
3. `gitnexus_detect_changes()` confirms changes match expected scope
4. All d=1 (WILL BREAK) dependents were updated

## Keeping the Index Fresh

After committing code changes, the GitNexus index becomes stale. Re-run analyze to update it:

```bash
npx gitnexus analyze
```

If the index previously included embeddings, preserve them by adding `--embeddings`:

```bash
npx gitnexus analyze --embeddings
```

To check whether embeddings exist, inspect `.gitnexus/meta.json` — the `stats.embeddings` field shows the count (0 means no embeddings). **Running analyze without `--embeddings` will delete any previously generated embeddings.**

> Claude Code users: A PostToolUse hook handles this automatically after `git commit` and `git merge`.

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