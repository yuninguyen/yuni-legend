---
name: "gitnexus-refactoring"
description: "Refactor RebateOps safely using graph-aware impact and rename operations."
user-invocable: true
disable-model-invocation: false
---

# GitNexus Refactoring

- Run context + upstream impact before moving/splitting code.
- For renames, use `gitnexus_rename(..., dry_run: true)` first; never blind find/replace.
- Review every text-search edit manually.
- Preserve behavior with tests before/after.
- Run `gitnexus_detect_changes({scope: "all"})` after refactoring.
- Do not bundle unrelated cleanup into a requested change.
