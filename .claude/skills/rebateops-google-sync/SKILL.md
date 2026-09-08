---
name: "rebateops-google-sync"
description: "Safely modify RebateOps Google Sheets import/export and synchronization flows without bypassing database or financial invariants."
user-invocable: true
disable-model-invocation: false
---

# RebateOps Google Sheets Sync

Use this skill for changes to `GoogleSheetService`, `GoogleSyncService`, sync jobs, observers, import/export actions, mapping, conflict resolution, or Sheet-triggered financial updates.

## First Determine Direction

State whether the change affects:

```text
DB → Sheet
Sheet → DB
both directions
```

Do not treat Google Sheets as the authoritative source for financial rules merely because it is an integration surface.

## Preserve

- independent import/export spreadsheet configuration;
- field and status mapping semantics;
- duplicate/conflict prevention;
- queued outbound sync after DB mutation where currently used;
- `PayoutLog::$syncingFromSheet`-style loop prevention;
- retry behavior unless explicitly changed;
- server-side validation, policies, and financial invariants.

## Never

- write directly to Sheets as a substitute for committing authoritative DB state;
- let Sheet imports bypass settlement, balance, liquidation, or ownership checks;
- create an observer/job loop;
- use production Google Sheets in ordinary automated tests;
- log service-account credentials, tokens, or sensitive row content unnecessarily.

## Test Strategy

Normally:

```text
Queue::fake / HTTP or API fake
→ create/update authoritative DB state
→ assert expected job/sync behavior
→ verify loop-prevention path
→ verify duplicate/conflict path
```

For Sheet→DB changes, include malformed/duplicate/existing-record scenarios where relevant.

For financial rows, also apply `.claude/skills/rebateops-financial-safety/SKILL.md`.

## Completion

Before finishing:

- confirm sync direction;
- confirm no infinite loop;
- confirm DB remains authoritative;
- run relevant tests;
- run GitNexus impact/change detection.
