# Phase B21 — Store Health, Monitoring & Resource Usage (Module 24)

## Scope

Three kinds of health exist in this platform; B21 builds the third:

| Kind | Owner | Question it answers |
|---|---|---|
| Infrastructure | Host tooling (not in this codebase) | Is the server healthy? |
| Application | B20 `InfrastructureHealthService` | Can the app reach its DB, cache and storage? |
| **Tenant store** | **B21 `StoreHealthService`** | **Is this store operating correctly, and is it about to hit a limit?** |

## Domain Layout

```
app/Domain/Monitoring/
  Models/HealthStatus.php            ok | warning | critical, worstOf()
  Models/StoreHealthSnapshot.php     append-only history (BelongsToTenant)
  Services/StoreHealthService.php    the ten checks, snapshot(), pruneSnapshots()
  Services/StoreHealthReport.php     one evaluation: checks + overall status
  Services/HealthCheckResult.php     one check: key, status, message, metrics
  Services/PlatformMonitoringService.php  Super Admin overview, outbox, API usage
  Console/SnapshotStoreHealthCommand.php  store-health:snapshot (hourly)
  Policies/StoreHealthPolicy.php     store_health.view or Owner
  Http/Controllers/StoreHealthController.php
  Http/Resources/StoreHealthSnapshotResource.php
app/Domain/SuperAdmin/Http/Controllers/SuperAdminMonitoringController.php
config/monitoring.php
```

## The Checks

Every check reads the owning module's authoritative record; nothing is
copied or recalculated differently. The report's overall status is the
worst individual status.

| Key | Source | Warning | Critical |
|---|---|---|---|
| `setup` | `Store.status` | `pending_setup` | suspended / cancelled / archived |
| `subscription` | Module 04 `Subscription` | past_due, grace_period, trial ending within `trial_warning_days` | none, or a status that grants no access |
| `resource_usage` | `EntitlementService` limits + usage | ≥ `usage_warning_percent` of a limit | limit fully used (further use is blocked) |
| `domains` | Module 19 | custom domain with expired verification, or active without SSL | no active primary domain (storefront unreachable) |
| `inventory` | Module 08 (`on_hand - reserved`) | any out-of-stock or at/below-reorder-point item | — |
| `event_delivery` | ADR-004 `outbox_events` | oldest pending ≥ `outbox_stale_minutes` | any failed event in the failure window |
| `notifications` | Module 21 | failed/bounced/rejected in the window | — |
| `payment_webhooks` | Module 12 | failed provider webhook in the window | — |
| `developer_webhooks` | Module 31 | a delivery that failed and never later succeeded | — |
| `backups` | Module 23 (own or platform-wide, `verified_at`) | none, or older than `backup_max_age_hours` | — |

Unlimited entitlements are not treated as limits. Thresholds live in
`config/monitoring.php` (environment-overridable) and are defaults to be
tuned with real traffic, not measured values.

## Tenant Isolation (ADR-001)

`StoreHealthService::evaluate(Store $store)` refuses to run unless the
resolved tenant context is exactly that store — every tenant-owned read
relies on the `BelongsToTenant` scope. Tables without that scope
(`backups`, `payment_webhook_events`) carry explicit `store_id` filters.
The snapshot command resolves each store's own context before evaluating
it, exactly like one of that store's requests.

## Snapshots

`store-health:snapshot` runs hourly (`routes/console.php`,
`withoutOverlapping`). It evaluates every store that is not cancelled or
archived, appends a `store_health_snapshots` row, isolates per-store
failures (logged, command exits non-zero) and prunes rows older than
`snapshot_retention_days`. The Super Admin overview reads the latest row
per store instead of recomputing every store per request.

## Endpoints

| Route | Group | Purpose |
|---|---|---|
| `GET /api/v1/store/health` | staff (`store_health.view` or Owner) | live report for the caller's store |
| `GET /api/v1/store/health/history` | staff | that store's snapshots, newest first |
| `GET /api/v1/super-admin/store-health?status=` | Super Admin platform | latest snapshot per store, most severe first |
| `GET /api/v1/super-admin/stores/{store}/health` | Super Admin impersonation (audited) | live report for one store |
| `GET /api/v1/super-admin/monitoring/outbox` | Super Admin platform | ADR-004 §17: outbox counts by status, oldest pending, recent failures, `failed_jobs` size |
| `GET /api/v1/super-admin/monitoring/api-usage?days=` | Super Admin platform | ADR-005 §17: Developer API requests per version (stores, keys, 5xx, last seen) |

The storefront admin UI has a matching Inertia page at `/store-health`
(`Pages/StoreHealth/Index.tsx`), which only displays server-computed
statuses.

## Permissions

`store_health.view` is a new catalog permission (PermissionSeeder),
granted to the seeded Manager role; the Owner always has access (same
rule as every other `BaseTenantPolicy`).
