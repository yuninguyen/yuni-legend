---
name: "gitnexus-exploring"
description: "Explore RebateOps architecture and execution flows with GitNexus before editing unfamiliar code."
user-invocable: true
disable-model-invocation: false
---

# GitNexus Exploring

Use `gitnexus_query({query: "concept"})` to find relevant execution flows, then `gitnexus_context({name: "symbol"})` for callers/callees/process participation. Read `gitnexus://repo/RebateOps/process/{name}` for end-to-end traces.

Prefer graph-guided exploration over broad grep. Confirm current implementation behavior with code/tests before drawing conclusions.
