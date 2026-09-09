---
name: "speckit-tasks"
description: "Break an approved implementation plan into ordered, verifiable tasks."
user-invocable: true
disable-model-invocation: false
---

# Spec-Kit Tasks

Read the active `spec.md` and `plan.md`. Create `tasks.md` from `.specify/templates/tasks-template.md`.

Each task must:
- have a concrete outcome;
- identify files/symbols or discovery step;
- include verification;
- respect dependency order;
- keep migrations/data backfills separate from UI work;
- include focused RED/GREEN tasks for behavior changes;
- identify financial/RBAC/Google-Sheets regression checks when relevant.

Do not implement. After tasks are generated, run `speckit-analyze` as appropriate and send the plan/tasks through `PLAN-REVIEW.md` before `speckit-implement`.
