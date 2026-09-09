---
name: "speckit-implement"
description: "Execute an approved Spec-Kit task list with RebateOps safety gates and verification."
user-invocable: true
disable-model-invocation: false
---

# Spec-Kit Implement

Prerequisites:
- active `spec.md`, `plan.md`, and `tasks.md` exist;
- `PLAN-REVIEW.md` verdict is `APPROVED FOR IMPLEMENTATION` for the intended scope;
- no unresolved BLOCKER prevents the task.

Execution:
1. Read `AGENTS.md`, `CLAUDE.md` when using Claude, `BUILD.md`, and the approved plan review.
2. Activate relevant RebateOps domain skills.
3. Before editing symbols, run GitNexus impact analysis.
4. For behavior changes, observe meaningful RED first.
5. Implement the smallest authorized task.
6. Run focused GREEN and relevant regressions.
7. Mark tasks complete only with evidence.
8. Run `gitnexus_detect_changes()` before commit/completion.
9. Update `HANDOFF.md` when pausing or transferring substantial work.

Never use implementation to expand scope beyond the approved plan.
