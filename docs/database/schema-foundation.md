# Database Foundation — Milestone 0 / Phase B0

Implements ADR-003 conventions exactly. Tables created (in dependency order):

1. `stores` — the tenant. No `store_id` column (a Store isn't owned by a Store).
2. `users` — platform-level identity. No `store_id` column (see model docblock).
3. `store_user` — the only table linking users to stores (tenant membership).
4. `roles` — tenant-owned, `store_id` first in unique/composite index.
5. `permissions` — platform-level catalog, shared across all tenants.
6. `permission_role` — pivot; tenant isolation inherited via `roles.store_id`.
7. `packages` — platform-level commercial catalog (Basic/Business/Premium).
8. `package_entitlements` — feature flags / usage limits per package.
9. `subscriptions` — tenant-owned; a store's link to its current package + lifecycle.
10. `outbox_events` — tenant-owned; ADR-004 transactional outbox.

Every tenant-owned table follows ADR-003 §8 exactly: `BIGINT UNSIGNED` internal `id`,
`ulid` `public_id` where externally referenced, `store_id` foreign key **first** in
every composite index, real FK constraints (not app-only relationships), soft deletes
only where audit/business integrity requires it (`stores`, `users` — not on pivot or
outbox tables, which are either structurally protected by FKs or intentionally
append-only).

**Deliberately deferred to later phases (not created in Phase B0):** products,
categories, inventory, orders, carts, payments, shipping, coupons, domains, invoices,
and every other commerce/module-specific table — per the Milestone-0 instruction
"create ONLY the minimum foundational database structure."

**NOT EXECUTED — CLAUDE APP ENVIRONMENT LIMITATION:** these migrations have not been
run against a real MySQL instance; no schema has been verified to actually apply
cleanly. This must be the first action taken in the VS Code phase (`php artisan
migrate`), and any error surfaced there should be fixed before further development
proceeds.
