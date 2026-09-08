# RebateOps Superpowers SDD Workspace

This directory stores project-local design/plan artifacts produced by Superpowers/ecc workflows.

Spec-Kit and Superpowers are separate workflow families:

```text
Spec-Kit:
speckit-specify → speckit-plan → speckit-tasks → speckit-implement

Superpowers/ecc:
/plan, /executing-plans, review/debugging workflows, etc.
```

Do not assume they share state automatically.

For RebateOps, `AGENTS.md`, `BUILD.md`, `PLAN-REVIEW.md`, and financial invariants remain authoritative regardless of which workflow creates the plan.

Do not copy DeskHolt-specific SDD artifacts into this directory.
