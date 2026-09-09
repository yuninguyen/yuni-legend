---
name: "speckit-plan"
description: "Turn an approved feature specification into a technical implementation plan."
user-invocable: true
disable-model-invocation: false
---

# Spec-Kit Plan

Read `AGENTS.md`, `BUILD.md`, the active `spec.md`, `.specify/memory/constitution.md`, and relevant current code/tests.

Create `plan.md` using `.specify/templates/plan-template.md`.

The plan must include:
- current-state evidence and affected flows;
- architecture/data-model changes;
- migrations and compatibility impact;
- RBAC/security impact;
- financial invariants and lock/transaction design when applicable;
- Google Sheets direction/loop-prevention impact when applicable;
- testing/TDD strategy;
- rollout/backout and verification;
- files/symbols expected to change.

Run GitNexus impact analysis before finalizing edits to existing symbols. Do not implement yet. The next phase is `speckit-tasks`, then `PLAN-REVIEW.md` acceptance.
