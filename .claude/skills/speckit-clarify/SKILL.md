---
name: "speckit-clarify"
description: "Resolve material ambiguities in an active Spec-Kit feature before planning."
user-invocable: true
disable-model-invocation: false
---

# Spec-Kit Clarify

Read the active `spec.md` and identify only decisions that materially affect scope, permissions, security, financial semantics, data ownership, or user behavior.

- Ask at most three questions in one round.
- Offer concrete options and implications.
- Do not ask about details with a safe obvious default.
- Update the spec with resolved answers.
- Re-run requirement consistency checks.
- For RebateOps, never guess settlement, payout, balance, ownership, or deletion semantics when multiple interpretations would change money or access control.

Finish with remaining ambiguities and whether planning may proceed.
