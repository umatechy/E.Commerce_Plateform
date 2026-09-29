# Phase B21 — Step 1: Inspection + Runtime Verification + Gap Analysis

## What Changed About the Environment

Every checkpoint from B0 to B20 recorded its tests as `NOT EXECUTED —
ENVIRONMENT LIMITATION`. B21 ran in a container that has PHP 8.4, Composer,
Node 22, and — installed for this phase — MySQL 8.0 and Redis 7. So B21
started by doing what the previous twenty checkpoints each deferred:
installing the application, migrating a real database and running the
suite. Module 24 was built only after that baseline was green.

## Part 1 — First Real Execution

### The codebase could not start

| Gap | Effect | Fix |
|---|---|---|
| No `artisan`, `public/index.php`, `storage/`, `bootstrap/cache/`, `phpunit.xml` | Nothing could run; CI's `php artisan key:generate` fails immediately | Standard Laravel 12 skeleton files added |
| `larastan/larastan ^2.9` + `phpstan ^1` | Not installable next to Laravel 12 (`composer install` fails) | Larastan 3 / PHPStan 2 |
| No `composer.lock`; resolved on PHP 8.4 pulled Symfony 8 (needs PHP ≥ 8.4.1) | CI (PHP 8.3) could not install | `config.platform.php = 8.3.0`, lock committed |
| No `phpstan.neon` | `composer stan` fails ("at least one path") | Level 5 config, clean |
| No `package-lock.json`, no ESLint 9 config, no Vitest test, no Vite entry | `npm ci`, `npm run lint`, `npm run test`, `npm run build` all fail | Lock, flat config, component tests, `laravel-vite-plugin` |

### Migrations (first `php artisan migrate` on MySQL 8)

1. `store_user` referenced `roles` before `roles` existed (FK error 1824) —
   the two files swapped order. Safe to rename: no database anywhere could
   ever have run these migrations.
2. A 68-character index name (MySQL limit is 64, error 1059).
3. `usage_counters.period_*` were `TIMESTAMP`, but the persistent-period
   sentinels are 1970-01-01 00:00:00 and 2070-01-01 — outside TIMESTAMP's
   range (error 1292). Now `DATETIME`.
4. `backups.initiated_by` was `VARCHAR(16)`; `pre_restore_safety` is 18
   characters — every restore authorization would have failed.
5. Sanctum 4 no longer loads its own migration; `personal_access_tokens`
   was never published, so no token could be issued.
6. Laravel's `failed_jobs` table (relied on by ADR-004's dead-letter
   design) did not exist.

`migrate`, `migrate:reset` and `db:seed` now all run cleanly.

### Tests (first run: 684 tests, 567 errors)

Grouped by root cause; each fix is in commit `7d495ad` or `3f3141f`.

**Platform-wide defects**

- ADR-003 `public_id` ULIDs were never generated (only three services set
  one by hand) — every insert into 23 tables failed. New `HasPublicId` trait.
- Model factories could not resolve for `App\Domain\*` models.
- Middleware order: Laravel's priority list ran `SubstituteBindings` before
  `ResolveTenantContext` and route-level `auth:*` after it, so every bound
  tenant model threw `TenantContextMissingException` and customer tokens
  resolved before the tenant existed. Fixed with explicit priority entries.
- Sanctum loaded a Customer tokenable under the tenant global scope before
  any tenant could be resolved (custom `PersonalAccessToken` model).
- `$request->string()` returns a `Stringable`; 39 call sites passed it to
  `string` parameters under `strict_types` (TypeError → 500).
- `Log::channel('audit')` was used in 27 places and never defined.
- ADR-004: about twenty services called `RecordsOutboxEvents::recordEvent()`
  outside a transaction. The guard throws in production, but RefreshDatabase's
  own test transaction hid it. The guard now subtracts the test's ambient
  transaction level, and every caller was wrapped in `DB::transaction()`
  (jobs dispatched after commit). Platform-scope events (no store) had no
  valid `store_id`; `recordEventFor()` + a nullable column now cover them.
  Timestamp-based idempotency keys collided within one second.
- The developer API route group had no `SubstituteBindings`: `show()`
  received an empty model instead of the store's row or a 404.

**Module-level defects**

- Redirects: the external-URL guard ran after normalization, so
  `https://…`, `//…` and `/\…` destinations were accepted (open redirect).
- Payments: every second partial refund failed (self-transition), the
  refundable balance was checked outside the row lock, and manual
  confirmation notes were never stored.
- Categories: soft delete never fires the FK `nullOnDelete`.
- Promotions: zero-benefit automatic promotions were applied to orders.
- Analytics: Carbon 3's signed diffs pushed the comparison period into
  the future.
- Themes: the auto-published default had no publication row (no rollback
  target); draft authorization ran after validation.
- Settings: platform keys were disclosed through the store endpoint.
- Inventory: a second low-stock adjustment within an hour hit the unique
  outbox key and rolled back the stock change.
- Super Admin: nested `{store}/{domain}` routes passed the store id as the
  domain; paginated lists dropped their totals.
- Assorted: missing enum defaults and `created_at` on fresh models, wrong
  201/200, entitlement refusals surfacing as 500.

**Test-side fixes** kept each test's intent: reuse StoreObserver-seeded
roles/warehouse/zone, real actor user ids, entitlement setup where a test
forgot it, fresh request-scoped state per simulated HTTP request (a test's
own tenant context used to leak into the request — this hid a broken
Host-header test), and a `Queue::fake()` where the sync queue ran the real
`mysqldump` job first. The one test marked incomplete since B0 is now
implemented.

**Result:** 719 backend tests (1218 assertions) and 5 frontend tests pass;
PHPStan level 5 reports no errors; `npm run lint` and `npm run build` pass.

## Part 2 — Module 24 Gap Analysis

### A. Existing and reusable

- **Module 04** `EntitlementService` — limits and current usage (the same
  numbers enforcement uses).
- **Module 19** `DomainResolverService::primaryDomainFor()`, `Domain` statuses.
- **Module 08** `Inventory` availability formula (`on_hand - reserved`).
- **ADR-004** `outbox_events` (status, age) and the new `failed_jobs`.
- **Module 21** `NotificationMessage` statuses; **Module 12**
  `PaymentWebhookEvent`; **Module 31** `WebhookDeliveryAttempt`,
  `ApiRequestLog`; **Module 23** `Backup` (`verified_at`, platform scope).
- **B20** `InfrastructureHealthService` — application health, left as is.
- **B16** Super Admin route groups (platform + audited impersonation).

### B. Existing but incomplete

- B16 folded "store health" into a low-stock count on the store detail
  view and explicitly deferred a dedicated domain to Module 24.
- ADR-004 §17 and ADR-005 §17 each name Module 24 as the place their
  operational signals (outbox backlog, API version usage) become visible.

### C. Missing and required — implemented

`App\Domain\Monitoring`: per-store health checks, snapshots, store and
Super Admin endpoints, platform outbox/API-usage monitoring. See
`docs/architecture/b21-store-health.md`.

### D. Deliberately not built

- Host metrics (CPU, disk, network) — needs instrumentation the
  application does not own (same boundary B20 drew).
- Alert delivery (email/Slack on a critical status) — no alerting channel
  has been specified; the snapshot history is the integration point.
- Per-store storage/bandwidth quotas — no module records either yet.

### E. Dependency-blocked

- None.
