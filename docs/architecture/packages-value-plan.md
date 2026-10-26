# Packages: honest contents, services, and the features still to build

Owner decisions of 2026-10-26: a store is never sold a feature that is not
built; services go into the packages; AI (recommendations, chatbot) comes
later; loyalty points and advanced analytics are to be built; everything that
can be built is built before the first real customer.

## What the audit found

Feature keys in `PackageSeeder` that no code enforces or provides:

| Key | Package | Status |
|---|---|---|
| `ai.recommendations`, `ai.chatbot` | Premium | Not built — later (owner) |
| `loyalty.points` | Premium | Not built — phase P2 |
| `advanced_analytics.enabled` | Premium | Not built — phase P3 |
| `reports.advanced` | Business | Reports exist but are open to every package — phase P3 gates them |
| `inventory.advanced`, `marketing.advanced`, `integrations.advanced` | Business | Labels only — phase P4 |

The public home page already listed built features only (`PublicPlans::SHOWN_FEATURES`).

## P1 — done

- `App\Domain\Packages\Support\FeatureAvailability::COMING_SOON` is the one
  list of features not built yet. The API marks them (`coming_soon` on each
  entitlement); Super Admin → Packages, the store's Package & billing page and
  the Contents dialog show them apart as "Coming soon", never as included. A
  feature leaves the list in the change that builds it.
- `packages.services` (JSON list, up to 12 lines of 160 characters), edited in
  Super Admin → Packages; first wording in `PackageServices::DEFAULTS` (seeder)
  and in migration `2030_04_06_000001_package_services` (existing packages).
  Shown on the home page pricing, on Super Admin → Packages and on the store's
  Package & billing page.
- Super Admin → Packages lists only included features; a higher tier says
  "Everything in <tier below>, plus:" when it really includes all of it.

## P2 — Loyalty points (Premium)

Blueprints: Module 10 §45, Module 15 §45 (points, tiers, rewards, birthday and
repeat-purchase rewards; an auditable, tenant-scoped ledger), Module 14 §76
(loyalty separate from promotion rules; redeemed through a controlled
adjustment — here the existing store-credit ledger).

## P3 — Advanced analytics (Premium) and advanced reports (Business)

Blueprint: Module 22 §19 customer analytics (new vs returning, repeat purchase
rate, lifetime value, retention), §21 cart and checkout analytics, §22
conversion definitions with their denominators, §13 comparative periods;
Rule 24: package entitlements control advanced analytics.

## P4 — Business: advanced inventory, marketing and integrations

Stock forecast and reorder suggestions; automated marketing flows (abandoned
cart, win-back, birthday); webhooks and apps — each defined against its module
blueprint before it is built.
