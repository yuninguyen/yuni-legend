# RebateOps — Agent Execution Rules

RebateOps is an internal financial operations system. Correctness, auditability, concurrency safety, access control, and data preservation take priority over convenience or cosmetic refactoring.

## 1. Mandatory Read Order

Before non-trivial work:

```text
AGENTS.md
→ BUILD.md
→ relevant current code/tests/docs
```

Claude additionally reads `CLAUDE.md` immediately after `AGENTS.md`.

For a new feature, meaningful behavior change, or financial-critical work:

```text
AGENTS.md
→ CLAUDE.md (Claude only)
→ BUILD.md
→ active Spec-Kit artifacts
→ PLAN-REVIEW.md before implementation
→ HANDOFF.md when pausing/transferring substantial work
```

`PLAN-REVIEW.md` is an implementation gate. `HANDOFF.md` transfers context; it never grants new scope.

## 2. Installed Skill System

Repository-local Claude skills live in `.claude/skills/`.

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

### RebateOps domain skills

```text
rebateops-financial-safety
rebateops-google-sync
rebateops-filament-rbac
rebateops-financial-migrations
```

### GitNexus skills

```text
gitnexus-exploring
gitnexus-impact-analysis
gitnexus-debugging
gitnexus-refactoring
gitnexus-guide
gitnexus-cli
```

Use the relevant skill when its domain applies. A skill may add procedure, but it cannot weaken this file, `BUILD.md`, an approved `PLAN-REVIEW.md` scope, or explicit current user decisions.

## 3. Authority Resolution

Use this precedence:

```text
1. Explicit current user instruction
2. AGENTS.md hard financial/security invariants
3. BUILD.md execution rules
4. README.md for current product/workflow intent
5. Current schema + migrations + policies + models + observers + services
6. Focused automated tests for encoded behavior
7. Active Spec-Kit feature artifacts after they pass review
8. Historical docs/PLAN-*.md only when explicitly reactivated
```

Current code/schema/migrations describe what is implemented now. They do not silently authorize weakening a financial/security invariant.

If documentation and current code materially disagree on a money-moving rule, report the drift before implementation.

## 4. Role Boundary

Canonical roles currently represented:

```text
admin
finance
staff / operator
partner
```

Preserve least privilege:

- Admin: global oversight/system configuration.
- Finance: financial reconciliation/control; do not infer unrelated operational access.
- Staff/Operator: own operational/account/payout data only where policy allows.
- Partner: own partner-withdrawal workflow only; never general internal-resource visibility.

UI visibility is never sufficient authorization. Policy/Gate/server-side ownership must hold.

## 5. Financial Integrity — Hard Invariants

Any change touching `PayoutLog`, `PayoutMethod`, `UserPayment`, `PartnerWithdrawal`, exchange/liquidation, settlement, balances, payout rates, profit/margin, financial delete/restore behavior, or financial RBAC is financial-critical.

Use `.claude/skills/rebateops-financial-safety/SKILL.md`.

### 5.1 Atomicity / Locking

Money-changing read-compute-write logic must follow:

```text
validate
→ DB transaction
→ acquire required lockForUpdate() rows/group
→ reload/recompute authoritative values inside lock
→ atomic write
→ commit
```

Do not calculate authoritative financial totals before the required lock and write them later.

### 5.2 Wallet Balance

Current accounting meaning:

```text
completed withdrawal → + net_amount_usd
completed liquidation → - amount_usd
```

Prefer deterministic recomputation from authoritative completed rows over manual increments/decrements.

### 5.3 Parent / Child Settlement

For one economic group:

```text
user_payment_id on parent
OR
user_payment_id on liquidation child/children
NEVER BOTH
```

Do not introduce double-counting shapes.

### 5.4 Settlement Provenance

```text
settlement_group_id = immutable settle-run provenance
batch_id = editable grouping / bulk-pay workflow
```

Never replace `settlement_group_id` with `batch_id` for settlement provenance lookup. Batch regrouping must not mutate settlement provenance.

### 5.5 Liquidation

```text
sum(liquidation amounts) <= original face value
```

Prevent duplicate/full liquidation and settled-value reuse.

### 5.6 Split Calculations

Partner/Staff payout and Leader/Handler margin income are separate layers.

Leader/Handler margin uses:

```text
(own payout rate - partner/staff payout rate) × USD × percentage
```

Do not reuse Partner/Staff payout as Leader/Handler income.

### 5.7 Different “Total Paid” Metrics

Do not conflate:

```text
Dashboard Total Paid (USD)
= completed platform-side withdrawal/hold money

Payroll/System Profit personal totals
= paid UserPayment/disbursement money
```

A difference between them is not automatically a bug.

## 6. Delete / Recovery Safety

Preserve database-backed restrictions and deliberate soft-delete behavior:

- Account force-delete is restricted when dependent payout logs/trackers exist.
- PayoutMethod force-delete is restricted when payout logs exist.
- User deletion is restricted when dependent UserPayment rows exist.
- Settled financial records must not regain destructive/edit actions through another resource/action path.
- Soft-deleting UserPayment must not strand linked payout records as irrecoverably settled.

Never replace DB/FK protections with UI-only guards.

## 7. Google Sheets

Use `.claude/skills/rebateops-google-sync/SKILL.md` for changes to Sheet services/jobs/observers/import/export.

Preserve:

- DB→Sheet / Sheet→DB direction semantics;
- duplicate/conflict handling;
- status normalization;
- loop-prevention state such as `PayoutLog::$syncingFromSheet`;
- queued outbound sync where currently used;
- database financial validation as authoritative.

Normal automated tests must fake queues/external APIs. Do not hit production Sheets unless explicitly authorized.

## 8. Filament / RBAC

Use `.claude/skills/rebateops-filament-rbac/SKILL.md` for Resource/Action/Policy changes.

- Hidden/disabled UI is not authorization.
- Bulk/relation/modal paths must preserve the same server-side protections.
- Settled/locked actions must not reappear through alternate UI paths.
- Keep Finance and Partner navigation/access within intended role boundaries.

## 9. Database / Migrations

Use `.claude/skills/rebateops-financial-migrations/SKILL.md` for schema/query changes.

Repository environments include SQLite locally/tests, MySQL/MariaDB in production documentation, and PostgreSQL-compatible code/history.

- Prefer Eloquent/query builder over dialect-specific SQL.
- Identify engine impact before raw SQL.
- Financial migrations should be additive/reversible where practical.
- Destructive financial migrations require explicit approval, backup/migration strategy, and verification.

## 10. Sensitive Data / Audit

- Preserve encrypted casts for credentials/security data.
- Never log decrypted passwords, 2FA, tokens, security answers, service-account contents, or unnecessary PII.
- Never commit `.env` or Google credentials.
- Do not expose sensitive fields through tables/exports/activity logs without an authorized requirement.
- Preserve materially useful audit logging for financial/security mutations.

## 11. Internal-App Safety

RebateOps is an internal administrative tool. Preserve `noindex, nofollow` behavior and do not introduce unauthenticated public financial endpoints or public indexing as a side effect.

## 12. Feature Workflow

For new features / meaningful behavior changes:

```text
eligibility + invariant check
→ speckit-specify
→ speckit-clarify if material ambiguity remains
→ speckit-plan
→ speckit-tasks
→ speckit-analyze/checklist as relevant
→ PLAN-REVIEW.md
→ APPROVED FOR IMPLEMENTATION?
   YES → speckit-implement
   NO  → revise/converge and review again
→ verification
→ HANDOFF.md if paused/transferred
```

Do not run `speckit-implement` with unresolved PLAN-REVIEW blockers.

For small, obvious, low-risk fixes, do not force full Spec-Kit.

## 13. Verification Standard

Financial-critical work normally requires:

```text
focused regression tests
relevant policy tests
relevant financial/concurrency/accounting tests
php artisan test when scope permits
vendor/bin/pint --test
npm run build if frontend/assets changed
gitnexus_detect_changes()
diff review
```

Useful regression anchors include:

```text
FinancialDeleteRestrictionTest
FinancialPolicyTest
PayoutLogExchangeTest
```

Manual UI success alone is not proof of financial correctness.

<!-- karpathy:start -->
# Karpathy Coding Hygiene

## Think Before Coding

- State material assumptions when correctness depends on them.
- Surface materially different interpretations instead of silently choosing.
- Prefer the simplest implementation that satisfies the request.
- If financial meaning, settlement scope, authorization, or data ownership is unclear, resolve it before editing.
- Do not ask for redundant approval for routine, low-risk, already-scoped work.

## Simplicity First

- No speculative features.
- No abstractions for a single known case without need.
- No unnecessary configurability.
- No broad adjacent refactor.
- If a small request produces a large diff, reconsider the design.

## Surgical Changes

- Touch only what the task, invariant preservation, and tests require.
- Match existing Laravel/Filament style.
- Do not clean unrelated code.
- Remove only code made unused by your own change.

## Goal-Driven Execution

```text
define/reproduce behavior
→ focused test
→ meaningful RED
→ minimum implementation
→ GREEN
→ relevant regressions
```

Tool failures, missing commands, permissions errors, or unrelated ENOENT are not semantic RED.
<!-- karpathy:end -->

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
