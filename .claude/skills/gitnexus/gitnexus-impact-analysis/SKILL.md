---
name: "gitnexus-impact-analysis"
description: "Assess blast radius before modifying RebateOps symbols."
user-invocable: true
disable-model-invocation: false
---

# GitNexus Impact Analysis

Before editing any function/class/method run:

`gitnexus_impact({target: "symbolName", direction: "upstream"})`

Report direct callers, affected processes, and risk. Treat d=1 callers as must-update, d=2 as likely regression scope, d=3 as transitive test scope. Warn before HIGH/CRITICAL edits. Re-run change detection after implementation.
