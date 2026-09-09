---
name: "gitnexus-guide"
description: "Quick reference for GitNexus MCP tools and RebateOps graph resources."
user-invocable: true
disable-model-invocation: false
---

# GitNexus Guide

Core tools:
- `gitnexus_query` — concept/process discovery
- `gitnexus_context` — one-symbol 360° context
- `gitnexus_impact` — upstream/downstream blast radius
- `gitnexus_detect_changes` — scope verification
- `gitnexus_rename` — graph-aware rename
- `gitnexus_cypher` — custom graph query

Resources:
- `gitnexus://repo/RebateOps/context`
- `gitnexus://repo/RebateOps/clusters`
- `gitnexus://repo/RebateOps/processes`
- `gitnexus://repo/RebateOps/process/{name}`

If the index is stale, run `npx gitnexus analyze`; preserve embeddings with `--embeddings` when applicable.
