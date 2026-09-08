---
name: "rebateops-filament-rbac"
description: "Review and implement RebateOps Filament UI/actions while preserving server-side Admin/Finance/Staff/Operator/Partner authorization boundaries."
user-invocable: true
disable-model-invocation: false
---

# RebateOps Filament RBAC

Use for Filament Resources, Pages, Actions, BulkActions, RelationManagers, navigation visibility, ownership scoping, and policy changes.

## Roles

Current role families:

```text
admin
finance
staff / operator
partner
```

Preserve least privilege. Do not infer broader access merely because a role can enter the Filament panel.

## Core Rule

```text
UI visibility != authorization
```

A hidden/disabled button is not a security boundary.

For every sensitive action, verify the corresponding server-side Policy/Gate/query ownership behavior.

## Review All Entry Paths

When changing a financial action, check whether equivalent behavior is reachable through:

- row action;
- header action;
- bulk action;
- edit page;
- relation manager;
- custom page/modal;
- direct route/request.

Do not lock one path while leaving an alternate destructive path open.

## Staff / Partner Ownership

- Staff/Operator should only access own operational/financial records where policy allows.
- Partner access stays scoped to partner workflows and owned data.
- Finance may manage financial records according to current policy; do not infer unrelated operational access.
- Admin remains global unless an explicit rule says otherwise.

## Financial Records

For settled/completed records, preserve current immutability/delete restrictions. Bulk operations need the same accounting/locking guarantees as single-row actions.

When the action changes money/state, also apply `rebateops-financial-safety`.

## Test Strategy

Add/extend Policy/Gate feature tests for:

```text
owner allowed
other same-role user denied
privileged role behavior
settled/completed restrictions
alternate action path when relevant
```

Use `FinancialPolicyTest` as a regression anchor where applicable.

## Completion

Before finishing:

- verify server-side authorization;
- verify resource/query ownership scope;
- verify alternate UI paths;
- run relevant tests;
- run GitNexus impact/change detection.
