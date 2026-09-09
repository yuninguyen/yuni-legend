---
name: "gitnexus-debugging"
description: "Trace RebateOps bugs through GitNexus execution flows and dependency context."
user-invocable: true
disable-model-invocation: false
---

# GitNexus Debugging

1. Query the symptom/error with `gitnexus_query`.
2. Inspect suspect symbols with `gitnexus_context`.
3. Read relevant process traces.
4. Compare branch changes with `gitnexus_detect_changes({scope: "compare", base_ref: "main"})` for regressions.
5. Reproduce the bug with a focused test before changing behavior.

For financial bugs, also use `rebateops-financial-safety`.
