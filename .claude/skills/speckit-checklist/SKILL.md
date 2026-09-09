---
name: "speckit-checklist"
description: "Generate a feature-specific quality and acceptance checklist."
user-invocable: true
disable-model-invocation: false
---

# Spec-Kit Checklist

Create a checklist under the active feature's `checklists/` directory.

Select only relevant checks:
- requirement completeness;
- user-flow/edge-case coverage;
- RBAC/ownership;
- financial accounting and double-counting;
- transaction/locking/concurrency;
- migration/database compatibility;
- Google Sheets sync and loop prevention;
- secrets/PII/audit logging;
- tests/build/lint/change-scope verification.

Checklist items must be verifiable, not vague reminders.
