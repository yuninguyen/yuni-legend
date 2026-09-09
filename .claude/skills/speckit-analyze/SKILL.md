---
name: "speckit-analyze"
description: "Analyze consistency across spec, plan, tasks, project rules, and current code."
user-invocable: true
disable-model-invocation: false
---

# Spec-Kit Analyze

Cross-check active `spec.md`, `plan.md`, `tasks.md`, `AGENTS.md`, `BUILD.md`, and relevant code/tests.

Report:
- contradictions;
- missing acceptance criteria;
- requirements with no task coverage;
- tasks with no requirement justification;
- unstated migration, RBAC, financial, concurrency, or sync risk;
- stale assumptions versus current code.

Classify findings as BLOCKER / HIGH / MEDIUM / LOW. Analysis does not authorize implementation. Resolve blocking issues before plan review or implementation.
