---
name: "speckit-converge"
description: "Reconcile spec, plan, tasks, review findings, and current evidence into one consistent feature state."
user-invocable: true
disable-model-invocation: false
---

# Spec-Kit Converge

Use after review feedback or when artifacts drift.

- Treat current code/tests as implementation evidence, not automatic design authority.
- Reconcile `spec.md`, `plan.md`, `tasks.md`, checklists, and `PLAN-REVIEW.md` findings.
- Preserve explicit user decisions and `AGENTS.md` invariants.
- Remove stale assumptions and duplicate/conflicting tasks.
- Do not silently change financial semantics to make documents agree.
- Finish with a concise list of changed decisions and readiness for review/implementation.
