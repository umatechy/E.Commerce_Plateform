# B43 security review — badges and ranking

| Area | Risk | Control | Test |
|---|---|---|---|
| Tenant isolation | Another store's badge on a product; badges of another store shown | `Badge` tenant-scoped; `badge_ids`, bulk and CSV look badges up through the scope; ProductBadges reads pivot rows for listed products and loads badges through the scope | `test_badges_and_priority_in_bulk_csv…` (foreign badge 422) |
| Permissions | Viewers define badges; editors change merchandising settings | `BadgePolicy` (collections.manage to define, products.view to see); product `update` to assign; `settings.manage` for settings | same test |
| Input | Unbounded labels / priorities / sorts | Label ≤ 40 (unique per store, case-insensitive), tone from a fixed list, priority 1–200, sort priority −1000…1000, sorts and default sorts from fixed lists (request rules and setting allowed values) | own-badges and ranking tests |
| Ordering injection | A sort parameter naming a column | `sort` validated against `StorefrontCatalog::SORTS`; ordering built from fixed columns only | ranking test |
| Information exposure | Stock levels revealed | "Only a few left" off by default; shows no number | automatic badges test |
| Rendering | Script in a badge label | Text only; React escapes; tone mapped to fixed classes | badges Vitest |
| Determinism (§37) | Order changing between requests (and cache entries) | Every order ends with the id | ranking test (same order twice) |
| Audit | Untraceable badge changes | `badge.created/updated/deleted`; setting changes keep their history (Module 33) | — |

No new secrets, providers or outgoing calls; authentication, MFA and step-up
unchanged.
