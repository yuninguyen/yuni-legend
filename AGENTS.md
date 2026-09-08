# RebateOps — Agent Execution Rules

RebateOps is an internal financial operations system. Correctness, auditability, concurrency safety, access control, and data preservation take priority over convenience or cosmetic refactoring.

## 1. Project Identity & Stack

Observed current stack:

```text
Laravel 12
PHP 8.3+ / Composer platform 8.4.3
Filament 3.2
Eloquent + database queues
Google Sheets API integration
Spatie Activity Log
Vite 7 + Tailwind CSS 4
```

Database environments represented in the repository:

```text
Local default: SQLite
Production example: MySQL / MariaDB on Hostinger
Repository history/code also contains PostgreSQL compatibility work
```

Avoid unnecessary database-dialect coupling.

## 2. Authority Resolution

There is currently no separate canonical `ARCHITECTURE.md` or master execution blueprint in `docs/`.

Use this precedence:

```text
1. Explicit current user instruction
2. Financial/security invariants in AGENTS.md
3. README.md for current product/workflow intent
4. Current schema + migrations + policies + models + observers + services
5. Focused automated tests for encoded behavior
6. docs/PLAN-*.md only when explicitly identified as active
7. Historical comments/roadmap entries for context only
```

Conflict rule:

- Current code/schema/migrations describe what is implemented now.
- README + explicit approved decisions describe intended product/workflow behavior.
- Existing code does not silently authorize weakening a financial/security invariant.
- Historical plan files do not override current implementation unless the task explicitly reactivates them.

If documentation and current code materially disagree on a financial rule, report the drift before changing money-moving behavior.

## 3. Core Role Boundary

Canonical roles currently represented:

```text
admin
finance
staff / operator
partner
```

Preserve least-privilege behavior:

- Admin: global oversight and system configuration.
- Finance: financial reconciliation/control; do not infer access to unrelated operational resources.
- Staff/Operator: own operational/account/payout data only where policy allows.
- Partner: own partner-withdrawal workflow only; never general internal-resource visibility.

Do not bypass model policies/resource authorization just because a Filament action is hidden in UI. Authorization must hold server-side.

## 4. Financial Integrity — HARD INVARIANTS

Any change touching `PayoutLog`, `PayoutMethod`, `UserPayment`, `PartnerWithdrawal`, exchange/liquidation, settlement, balances, payout rates, or profit calculations is **financial-critical**.

### 4.1 Atomicity and Locks

Money-moving or balance-changing operations must follow:

```text
validate
→ enter DB transaction
→ acquire required lockForUpdate() locks
→ recompute authoritative values inside the lock
→ write atomically
→ commit
```

Never perform read-compute-write financial logic outside the required transaction/lock scope.

For group settlement/exchange, lock the full relevant economic group before computing totals.

### 4.2 Wallet Balance

`PayoutMethod.current_balance` is derived from completed transaction data.

Current accounting meaning:

```text
completed withdrawal → + net_amount_usd
completed liquidation → - amount_usd
```

Prefer deterministic recomputation from authoritative rows over manual increment/decrement that can accumulate race-condition drift.

Do not change this accounting meaning as part of an unrelated UI/sync task.

### 4.3 Parent / Liquidation Group

Preserve parent-child economic grouping.

For Gift Card liquidation:

```text
sum(liquidation amounts) <= original face value
```

Do not allow duplicate liquidation of already fully liquidated or settled value.

A settled/locked group must not regain destructive/edit actions through another resource/action path.

### 4.4 Settlement Linking — No Double Counting

For one economic group:

```text
user_payment_id is stamped on exactly ONE side:
parent
OR
liquidation child/children
NEVER BOTH
```

Queries that compute linked USD must respect this invariant.

Do not “fix” missing rows by linking both sides.

### 4.5 Immutable Settlement Provenance

`UserPayment.settlement_group_id` and `batch_id` have different semantics:

```text
settlement_group_id
= original settle-run provenance
= immutable for that generated settlement relationship

batch_id
= user-editable grouping/bulk-pay workflow
= may be reassigned
```

Never replace `settlement_group_id` with `batch_id` in per-row Account/Email/USD provenance lookup.

Never mutate `settlement_group_id` merely because batches are regrouped.

### 4.6 Split Calculations

Partner/Staff payout and Leader/Handler margin splits are different layers.

Leader/Handler income is based on exchange-rate margin:

```text
(own payout rate - partner/staff payout rate)
× USD
× percentage
```

Do not reuse the Partner/Staff USD payout as Leader/Handler income and do not double-count the same economic value.

### 4.7 Two “Total Paid” Metrics Are Intentionally Different

Do not conflate:

```text
Dashboard Total Paid (USD)
= completed platform-side withdrawal/hold money

Payroll/System Profit personal totals
= paid UserPayment/disbursement money
```

A difference between them is not automatically a bug.

## 5. Record Locking, Delete Safety & Recovery

Preserve current data-integrity behavior:

- settled financial records must not become editable/deletable through another action path;
- `Account` force-delete is restricted when dependent payout logs or trackers exist;
- `PayoutMethod` force-delete is restricted when payout logs exist;
- `User` deletion is restricted when dependent `UserPayment` records exist;
- soft deletes and restore/force-delete policy must remain deliberate;
- soft-deleting a `UserPayment` must not strand linked `PayoutLog` rows as irrecoverably settled.

Do not replace FK/database restrictions with UI-only guards.

## 6. Sensitive Data & Audit Safety

RebateOps handles credentials and PII.

Rules:

- Preserve Laravel encrypted casts for passwords, 2FA, gift-card/security data, and other credential fields.
- Never log decrypted credentials, tokens, service-account contents, passwords, 2FA codes, or security answers.
- Never include real secrets/PII in fixtures, screenshots, examples, seeders, or exception messages.
- Do not expose sensitive fields through tables, exports, notifications, or activity logs unless the existing authorized workflow explicitly requires it.
- Keep production assumptions compatible with `APP_DEBUG=false`.
- Never commit `.env` or Google service-account credentials.

## 7. Google Sheets Sync Invariants

Google Sheets is an integration surface, not permission to bypass financial rules.

Preserve:

- independent import/export spreadsheet configuration;
- bidirectional mapping semantics;
- duplicate/conflict prevention;
- status normalization/mapping;
- `PayoutLog::$syncingFromSheet`-style loop prevention;
- queued outbound sync after database mutation where currently used;
- existing retry behavior unless the task explicitly changes it.

Do not add direct Sheet writes that bypass model/database invariants.

For tests, fake queues/external APIs. Do not hit live Google Sheets unless the user explicitly asks for an integration test against a designated test sheet.

## 8. Internal-App / Anti-SEO Safety

RebateOps is an internal administrative tool, not a public marketing site.

Preserve `noindex, nofollow` behavior on the Filament/admin surface.

Do not add public indexing, public financial endpoints, or unauthenticated data exposure as a side effect of UI or deployment work.

## 9. Database Compatibility & Migrations

- Production is documented for MySQL/MariaDB.
- Local default `.env.example` uses SQLite.
- Existing repository history includes PostgreSQL compatibility fixes.

Therefore:

- avoid database-specific SQL when Laravel/Eloquent can express the query;
- if raw SQL is necessary, identify supported-engine impact first;
- financial migrations must be additive/reversible where practical;
- never drop/transform production financial columns destructively without explicit approval, backup/migration strategy, and verification;
- test queries on the database engine relevant to the task.

## 10. Filament / UI Rules

UI changes must preserve server-side rules.

- Hidden/disabled buttons are not authorization.
- Do not make settled/locked actions reachable from alternative row, modal, bulk, or relation-manager paths.
- Preserve the high-density internal-tool workflow unless the user explicitly requests a redesign.
- Preserve EN/VI label consistency for user-facing resource strings touched by the task.
- Keep Finance navigation focused on financial resources and Partner access scoped to its workflow.

## 11. Activity Logging

RebateOps maintains an audit trail.

For financial/security mutations:

- do not silently remove existing activity logging;
- do not log secrets;
- when introducing a new financially material state transition, determine whether it needs an auditable event and test/verify accordingly.

## 12. Feature Eligibility

For a new feature or meaningful behavior change:

```text
request
↓
identify affected financial/security/workflow invariants
↓
GitNexus blast-radius analysis
↓
define acceptance tests
↓
implement minimum change
↓
verify financial + authorization regressions
```

If the request conflicts with a financial invariant, stop and report the conflict before implementation.

## 13. Verification Standard

For financial-critical changes, verification should normally include:

```text
focused feature/unit tests
relevant policy tests
relevant concurrency/accounting tests
php artisan test (when scope permits)
vendor/bin/pint --test
npm run build if frontend/assets changed
gitnexus_detect_changes()
diff review
```

Use existing tests such as:

```text
FinancialDeleteRestrictionTest
FinancialPolicyTest
PayoutLogExchangeTest
```

as regression anchors when relevant.

Do not claim a financial bug is fixed based only on manual UI behavior.

## 14. Small-Fix Rule

For typo/copy changes, tiny styling fixes, or obvious low-risk non-financial bugs:

- do not force a full feature-spec workflow;
- keep the diff surgical;
- still obey RBAC, secret handling, and GitNexus impact rules;
- run only verification appropriate to the changed surface.

<!-- karpathy:start -->
# Karpathy Coding Hygiene

These rules apply to all coding agents in this repository.

## Think Before Coding

- State material assumptions when correctness depends on them.
- Surface materially different interpretations instead of silently choosing.
- Prefer the simplest implementation that satisfies the requested behavior.
- If the financial meaning, settlement scope, authorization boundary, or data ownership is unclear, stop and resolve it before editing.
- Do not ask for redundant approval for routine, low-risk, already-scoped work.

## Simplicity First

- No speculative features.
- No generic abstraction for a single current use case.
- No configuration knobs unless required.
- No broad cleanup/refactor adjacent to the requested change.
- If a small request causes a large diff, reconsider the design.

## Surgical Changes

- Touch only files and lines required by the task and its verification.
- Match existing Laravel/Filament style.
- Do not rename unrelated symbols.
- Remove only code made unused by your own change.
- Every changed line should trace to the request, an invariant it must preserve, or a test.

## Goal-Driven Execution

For behavior changes:

```text
define/reproduce failure
→ focused test
→ meaningful RED
→ minimum implementation
→ GREEN
→ relevant regression checks
```

Process-launch failures, missing tooling, environment permission errors, or unrelated `ENOENT` are not semantic RED.

<!-- karpathy:end -->

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