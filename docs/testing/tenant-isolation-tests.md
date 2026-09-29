# Tenant Isolation Test Suite — Status

**EXECUTED (Phase B21) — PASSING** against a real MySQL 8.0 database
(PHP 8.4, Laravel 12). Before B21 no PHP/MySQL runtime was available and
none of these tests had ever run; see
`docs/development/b21-inspection-findings.md` for what the first real run
found.

## How to Run

```bash
composer install
cp .env.example .env && php artisan key:generate
# create the MySQL databases named by DB_DATABASE in .env and in phpunit.xml
php artisan migrate
php artisan test                     # whole suite
php artisan test tests/Feature/Tenancy
```

Tests run against MySQL, not SQLite: the code uses MySQL-only SQL
(`ON DUPLICATE KEY UPDATE`, `LAST_INSERT_ID()`, `REGEXP_SUBSTR`).

## Coverage

- `tests/Feature/Tenancy/TenantIsolationTest.php`
  - Tenant A cannot **read**, **update** or **delete** Tenant B's resource
    via a guessed id → 404, with no mutation.
  - A spoofed `store_id` in the payload is ignored; the server-resolved
    context wins.
  - Relationship-level protection for cross-tenant child rows.
  - Cache keys are tenant-prefixed.
  - A queued job re-applies the tenant from its own stored `store_id`, not
    from ambient state (implemented in B21; previously incomplete).
- `tests/Feature/Tenancy/SuperAdminCrossTenantAccessTest.php` — impersonation
  is platform-staff only and audit-logged.
- Every module has its own `*TenantIsolationTest` (catalog, inventory,
  orders, payments, shipping, promotions, backups, …) and B21 adds store
  health isolation (`tests/Feature/Monitoring`).

## Test-Harness Guarantees (tests/TestCase.php)

- Each simulated HTTP request starts with fresh request-scoped state
  (`TenantContext`, `ApiKeyContext`), as in production. Previously a test's
  own resolved tenant leaked into the request, so a test could pass under
  the test's tenant instead of the one the middleware resolved.
- `outbox.ambient_transaction_level` is set to RefreshDatabase's level, so
  a service that writes an outbox event outside its own transaction fails
  in tests exactly as it would in production.
