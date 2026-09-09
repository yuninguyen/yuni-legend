---
name: "speckit-specify"
description: "Create or update a feature specification from a natural-language request."
user-invocable: true
disable-model-invocation: false
---

# Spec-Kit Specify

Use for new features or meaningful behavior changes.

1. Read `AGENTS.md`, `CLAUDE.md` when using Claude, and `BUILD.md`.
2. Read `.specify/memory/constitution.md` if present.
3. Create exactly one feature directory under `specs/NNN-short-name/`.
4. Copy `.specify/templates/spec-template.md` to `spec.md`.
5. Describe WHAT/WHY, actors, flows, requirements, edge cases, assumptions, and measurable success criteria.
6. Avoid implementation details unless the user explicitly asks for a technical spec.
7. For RebateOps, identify affected roles and financial/economic entities without inventing accounting semantics.
8. Mark only material ambiguities as clarification items; maximum three at a time.
9. Validate the spec before declaring it ready for planning.

Output the feature directory, spec path, unresolved clarifications, and readiness for `speckit-clarify` or `speckit-plan`.
