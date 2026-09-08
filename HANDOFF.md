# RebateOps — Handoff Protocol

Use this file when pausing substantial work, transferring between agents/sessions, or handing implementation/review to another model.

Do not overwrite business history with guesses. Record only verified current state.

## Required Handoff Fields

```text
TASK / FEATURE:
STATUS:
BRANCH:
HEAD / COMMIT:
WORKTREE:
LAST VERIFIED TREE OR DIFF:

REQUESTED SCOPE:

AUTHORITY USED:
- AGENTS.md
- CLAUDE.md (Claude only)
- BUILD.md
- PLAN-REVIEW.md
- relevant README/docs/code/tests

SPEC-KIT STATE:
- feature directory:
- spec:
- plan:
- tasks:
- plan-review verdict:

FILES CHANGED:

FINANCIAL INVARIANTS TO PRESERVE:

GITNEXUS:
- impact targets checked:
- highest risk:
- detect_changes result:

TESTS / VERIFICATION RUN:
- command:
- result:

KNOWN FAILURES / MISSING EVIDENCE:

BLOCKED WORK:

NEXT EXACT STEP:

DO NOT DO:
```

## Status Vocabulary

Prefer one of:

```text
PLANNING
PLAN REVIEW
APPROVED FOR IMPLEMENTATION
IMPLEMENTING
VERIFYING
BLOCKED
READY FOR REVIEW
COMPLETE
```

Do not write `COMPLETE` if required verification is missing.

## Financial-Critical Handoff

For payout/settlement/exchange/balance/profit/RBAC work, always include:

```text
economic group
authoritative records
transaction boundary
lockForUpdate scope
immutable provenance fields
settlement parent/child linkage rule
idempotency/retry notes
role/ownership constraints
delete/restore impact
```

Explicitly state whether these invariants remain verified:

```text
user_payment_id stamped on parent OR liquidation children, never both
settlement_group_id remains immutable provenance
batch_id remains editable grouping only
no duplicate/full liquidation
wallet balance derived from completed authoritative rows
```

## Handoff Rules

- Never claim tests passed if they were not run.
- Distinguish test failure from environment/tool failure.
- Include exact error/command for missing evidence.
- Never authorize destructive financial migration implicitly.
- Never expose secrets/PII in handoff text.
- Preserve unresolved PLAN-REVIEW blockers verbatim enough for the next agent to act on them.
- If GitNexus reports HIGH/CRITICAL risk, include the blast radius and whether the user approved continuation.
- If context is being compacted, update this file before losing implementation-specific state when practical.

## Completion Handoff

At completion, record:

```text
what changed
why it changed
which tests prove it
what was intentionally not changed
remaining risks / follow-ups
final gitnexus_detect_changes scope
```

A handoff is context transfer, not permission to broaden scope.
