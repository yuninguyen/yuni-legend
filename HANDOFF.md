# RebateOps — Handoff Protocol

Use when pausing substantial work, transferring agents/sessions, or handing implementation/review to another model. Record verified state only.

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
- checklist/analyze state:
- plan-review verdict:

SKILLS USED:
- Spec-Kit:
- RebateOps domain:
- GitNexus:
- Superpowers/ECC if used:

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

Prefer:

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

Do not write COMPLETE when required verification is missing.

## Financial-Critical Handoff

Always record, when applicable:

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

Explicitly state whether these remain verified:

```text
user_payment_id on parent OR liquidation children, never both
settlement_group_id remains immutable provenance
batch_id remains editable grouping only
no duplicate/full liquidation
wallet balance derived from completed authoritative rows
```

## Rules

- Never claim tests passed if they were not run.
- Distinguish test failure from environment/tool failure.
- Preserve unresolved PLAN-REVIEW blockers.
- Record HIGH/CRITICAL GitNexus blast radius and whether continuation was approved.
- Never authorize destructive financial migration implicitly.
- Never expose secrets/PII.
- A handoff transfers context; it does not broaden scope.

## Completion Handoff

Record:

```text
what changed
why it changed
skills used
which tests prove it
what was intentionally not changed
remaining risks/follow-ups
final gitnexus_detect_changes scope
```
