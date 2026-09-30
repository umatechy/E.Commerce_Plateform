# Umar Techy E-Commerce Platform — Architecture Decision Records (ADR) Index

## Purpose

This folder contains implementation-level Architecture Decision Records (ADRs) for the
Umar Techy E-Commerce Platform. ADRs exist to resolve decisions that were **intentionally
left at implementation level** by the Project Bible, SRS/URS, and Module Blueprints —
they do not invent new requirements and do not change commercial, functional, or
architectural principles already defined in those documents.

## Document Authority

```
Project Bible
    >
SRS / URS
    >
Technical Architecture
    >
Module Blueprints (01–35)
    >
Approved ADRs / RFCs   <-- this folder
    >
Existing Implementation
```

The higher documents are stored verbatim in [`docs/source/`](../source/README.md)
(Project Bible, SRS, Master Index, Master Development Prompt and Module Blueprints
01–35, URS, Technical Architecture and the owner decisions).

ADRs sit **below** the Project Bible, SRS, Technical Architecture, and Module Blueprints
in authority. An ADR may only:

- Choose a concrete implementation mechanism for a principle already stated above it
  (e.g. Module 03 requires "tenant isolation" — ADR-001 defines *how*).
- Fill a genuine implementation gap that the higher documents deliberately left open
  (e.g. exact physical column naming conventions).

An ADR may **never**:

- Remove, weaken, or reinterpret a functional or commercial requirement.
- Change the approved technology stack.
- Change tenant isolation, security, or entitlement rules.
- Silently resolve a real contradiction between higher-level documents.

If an ADR is ever found to conflict with the Project Bible, SRS, Technical Architecture,
or a Module Blueprint, that conflict **must be reported**, not silently resolved — the
higher document wins until the conflict is explicitly reviewed and the ADR is corrected.

## Status Convention

- `PROPOSED` — drafted, not yet approved for implementation.
- `ACCEPTED` — explicitly approved by the project owner; may be implemented.
- `SUPERSEDED` — replaced by a later ADR (the later ADR is referenced).
- `DEPRECATED` — no longer applicable; reason recorded.

All ADRs in this initial package are `PROPOSED`. **None may be treated as approved for
implementation until the project owner explicitly accepts them.**

**Review note (2026-09-18):** ADR-001 through ADR-004 were extended (not rewritten) to
close specific gaps identified in the "ADR Completion + Development Phase B" master
prompt (emergency/system-level tenant context, explicit anti-bypass statement, webhook
authentication boundary, quantities/percentages/nullable-field/migration/high-volume/
reporting-index/database-security/backup conventions, explicit anti-authorization-
backdoor statement for events, replay/eventual-consistency handling). ADR-005 was
reviewed against the same prompt and found already correct — it was **not** rewritten,
only annotated with a review date, per the instruction to preserve an already-correct
decision.

## Index

| ID | Title | Status | Resolves |
|---|---|---|---|
| [ADR-001](./ADR-001-tenant-resolution-and-isolation.md) | Tenant Resolution and Isolation Mechanism | PROPOSED | Exact mechanism for the shared-MySQL, server-authoritative tenant isolation required by Bible §6, §10, SRS §4, and Module 03 |
| [ADR-002](./ADR-002-authentication-boundary.md) | Authentication Boundary: Sanctum vs Developer API Tokens | PROPOSED | Clarifies the relationship between the approved Sanctum authentication and the JWT/OAuth wording in Module 31 |
| [ADR-003](./ADR-003-database-schema-and-id-conventions.md) | Physical Database Schema and ID Conventions | PROPOSED | Fills the intentionally-undefined physical schema layer beneath the conceptual data models in Bible §11 and Module 03/04 |
| [ADR-004](./ADR-004-event-outbox-pattern.md) | Event / Outbox Pattern | PROPOSED | Concrete Laravel-compatible mechanism for the event/idempotency/webhook principles required across Modules 09, 12, 21, 27, 31, 32 |
| [ADR-005](./ADR-005-api-versioning.md) | API Versioning Convention | PROPOSED | Concrete versioning format for the "API versions MUST be explicit and governed" rule in Module 31 §2.14 and related mentions in Modules 27, 30 |

## Relationship to Milestone 0

ADR-001 is a **prerequisite** for Milestone 0 — Step 0.5 (Tenant Foundation), because the
tenant middleware, global query scope, and cache/queue key strategy built in that step
must follow a single documented pattern rather than an ad-hoc one.

ADR-002 and ADR-003 are prerequisites for Milestone 0 — Step 0.4 (Database Foundation)
and Step 0.6 (Authentication/Authorization Foundation).

ADR-004 and ADR-005 are **not required for Milestone 0** (no commerce events or public
API endpoints are built in Milestone 0) but are recorded now so that Phase 1–2 work does
not each invent a different pattern.

## Review Status

See the **ADR Consistency Matrix** delivered alongside this package for the
cross-check of each ADR against the Project Bible, SRS, Technical Architecture, and the
relevant Module Blueprints.
