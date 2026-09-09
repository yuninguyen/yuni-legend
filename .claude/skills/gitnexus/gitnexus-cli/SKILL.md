---
name: "gitnexus-cli"
description: "Operate and refresh the local GitNexus index for RebateOps."
user-invocable: true
disable-model-invocation: false
---

# GitNexus CLI

Common commands:

```bash
npx gitnexus analyze
npx gitnexus analyze --embeddings
```

Before refreshing, inspect `.gitnexus/meta.json`; if embeddings exist, preserve them with `--embeddings`. After commits/merges, refresh the index if the Claude hook did not do so automatically. Never treat a stale graph as authoritative impact evidence.
