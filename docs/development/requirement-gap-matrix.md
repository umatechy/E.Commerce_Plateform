# Requirement Gap Matrix — Phases B0–B26 vs Source Specifications

Date: 2026-09-30 · Baseline: `main` at `dd5ad1a` (B26 merged) · Sources: [`docs/source/`](../source/README.md)

This is the Requirement Gap Matrix required by the Master Development Prompt §5.
It compares what phases B0–B26 built against the SRS v1.0 requirement IDs and
Module Blueprints 01–35. It is the first comparison made against the real
specification files. Earlier phases worked from chat copies, and until today no
repository document named Modules 01, 26 and 28.

## How to read this

**Status** is the state of the backend capability in the code:

| Mark | Meaning |
|---|---|
| ✅ | Implemented and covered by automated tests (per the phase checkpoint). Not yet verified in production. |
| 🟡 | Partly implemented. The gap column says what is missing. |
| ❌ | Not implemented. |
| ➖ | Not applicable yet (the capability it constrains does not exist). |
| ⚪ | Cannot be judged from the repository (needs a deployment, an audit or a business decision). |

**Priority** follows SRS §51 (P0 safety/security/core commerce, P1 production
commercial operation, P2 controlled rollout, P3 future). The priorities are
**proposed by this review**. The SRS does not assign priorities to individual
IDs, so the project owner should confirm them.

**Method and limits.** For this review I read the SRS in full, the section lists
of all 35 blueprints, every phase's "explicitly deferred" and "out of scope"
lists (`docs/development/bNN-inspection-findings.md`, `docs/checkpoints/`), and
probed the code (domains, models, 94 migrations, route files, schedules, UI
pages). It is a document-level and code-probe review. It is not a
section-by-section audit of every blueprint's 80–120 sections. That audit
belongs to the Step-1 inspection of each future phase. URS v1.0 and Technical
Architecture v1.0 arrived after this review was written and are now in
`docs/source/`. §5 records the owner's answers.

---

## 1. Headline findings

1. **6 of 35 modules have no implementation at all:** 18 Animation, 25 PWA, 26
   AI, 27 Marketplace, 28 Affiliate & Reseller, 35 Future Expansion Framework.
   Module 01 has no phase of its own. Its framework items are spread across
   other modules (see §2).
2. **Module 34 is only partly built.** Its name is *Help, Support &
   Documentation*. B26 built the ticket system (§16–23). The knowledge base,
   help center, contextual help, onboarding, release notes, changelog and
   troubleshooting framework (§6–15, §24–60; SRS SUP-001, SUP-005, SUP-006) do
   not exist. The B26 report calls the module "complete". That is true for
   tickets only.
3. **Most modules exist only as APIs. There is no admin UI for them.** Store
   admins get pages for Orders, Inventory, Billing, Store Health and Support
   only (`routes/web.php`). There are no pages for products, categories,
   customers, shipping, payments, promotions, marketing, SEO/content, theme,
   domains, settings, notifications, reports, backups, developer apps or staff.
   Super Admin has one page (support). Every blueprint lists "Admin
   Capabilities". A store owner cannot run a store without these screens.
4. **Store staff cannot be managed.** There is no API or UI to invite staff,
   assign roles or remove members (Module 02 §18, §29; SRS STORE-007). Stores
   get three seeded roles (owner, manager, staff). The blueprint lists seven.
   Custom roles (§17) are not built.
5. **There is no tax calculation.** `tax_total` is always 0 (SRS CHK-007). This
   needs a business decision about tax policy before it can be built.
6. **No real payment or courier provider is integrated.** Only COD, bank
   transfer and mock gateways exist (SRS PAY-002, PAY-005; SHIP-007).
   JazzCash, Easypaisa and a Pakistani courier need live credentials and a
   provider choice.
7. **Security controls the SRS requires are missing:** MFA (AUTH-006, AUTH-007),
   stronger Super Admin authentication and step-up (AUTH-012, SA-004), request
   and correlation IDs (API-011), dependency scanning and CI security gates
   (SEC-013, SEC-014), and an incident-response runbook (SEC-015).
8. **Localization is not built:** no multi-language support and no RTL (SRS
   LOC-001, LOC-002, LOC-006). Urdu/RTL is named repeatedly in the blueprints.
   The `store.timezone` setting exists but no date logic reads it
   (DATA-010, LOC-004).
9. **Customer Management (Module 10) has large gaps:** no customer groups, tags,
   notes, blocking, merge, import or activity timeline (§24–33, §47–57), and no
   guest-to-account conversion (§9). B25 built the storefront account only.
10. **Operational gaps:** backups are created manually only, with no schedule
    (BKP-001). No restore rehearsal (BKP-007). Health checks raise no alerts
    (HEALTH-005). No end-to-end tests run in CI; Playwright is installed but has
    no tests (TEST-009). No OpenAPI documentation (API-012). No sandbox
    environment (API-017).

---

## 2. Module coverage (Blueprints 01–35)

| # | Module | Phase | Status | Built | Main blueprint gaps |
|---|---|---|---|---|---|
| 01 | Complete Platform Structure | B0 (foundation) | 🟡 | Modular monolith, ADR-001–005, one codebase, entitlement tiers | Foundation map; its items live in other modules. Self-service onboarding flow §16 is only partly covered by B24's launch checklist |
| 02 | Authentication, Users, Roles & Permissions | B1 | 🟡 | Staff login/register, Sanctum, roles and permissions, policies, super-admin impersonation with reason | Staff invitation (§18), member management (§19, §29), custom roles (§17), 7 predefined roles (§6), MFA (§13), session/device management (§12), email verification for staff (§10), ownership transfer (§21) |
| 03 | Multi-Tenant & Store Management | B1 | 🟡 | Tenant resolution, `BelongsToTenant`, store roles seeding, domain/slug routing | Lifecycle has 5 states; the blueprint has 9, missing PROVISIONING, ONBOARDING, TRIAL, GRACE_PERIOD, DELETING (§7–8). No setup checklist entity (§24), store deletion/retention workflow (§35–37), cloning (§52), import/export (§40–41) |
| 04 | Subscription, Packages & Entitlements | B2 | 🟡 | Packages, entitlements, usage counters, trial dates, subscription lifecycle | Add-ons (§23–24), promotional entitlements (§25), contract overrides (§27), package versioning (§19), downgrade over-limit detection and preview (§22, §47), threshold alerts (§42); Business/Premium numeric limits unset by design |
| 05 | Storefront | B24 | 🟡 | `/shop/{slug}` and custom domains, catalog, search, product pages, cart, guest checkout, SEO, launch flow | Collections (§16), reviews (§27), RTL/multi-language (§40–41), maintenance/coming-soon modes (§57–58), preview/publish of storefront config (§86–88), image renditions (§50), recommendations (§66), page CSP |
| 06 | Product & Catalog | B3 | 🟡 | Products, variants, statuses, visibility, images, slugs, SKUs | Bulk operations, import/export (§46–51), variant generation (§10), collections (§34), tags (§35), relationships (§38), versioning (§59), duplication (§60), search index (§54–55), catalog events (§57) |
| 07 | Category, Brand & Attribute | B3 | 🟡 | Category hierarchy, brands, attributes, values | Attribute sets/groups (§28, §44), category-attribute and filter configuration (§18–19, §45–47), merge (§59–61), taxonomy redirects (§63–64), import/export, localization |
| 08 | Inventory & Stock | B4 (+B8) | 🟡 | Warehouses, on-hand/reserved/available, reservations with expiry, movements ledger, fulfilment commit | Transfers (§37–40), stock counts (§35–36), purchase receiving (§41), costing (open decision, §43), returns/damaged stock (§46–47), adjustment approval (§34), import/export, backorder/preorder |
| 09 | Order Management | B5 | 🟡 | Order engine, 17-state machine, snapshots, idempotency, timeline, cancellation | Returns workflow (§45–48), exchanges/replacements (§53–54), order edits/adjustments (§38–40), notes/tags/priority (§31–33), export (§66), outbound order webhooks exist via B18 |
| 10 | Customer Management | B5/B6/B25 | 🟡 | Store-scoped customers, registration/login, profile, address book, order history, password reset, erasure/export (B22) | Groups, tags, segments at Module 10 (§24–27), notes (§32), activity timeline (§33), blocking (§30–31), merge and duplicate detection (§56–57), import (§47–49), guest-to-account conversion (§9), email verification, email/phone change verification (§67) |
| 11 | Cart, Wishlist & Checkout | B6 | 🟡 | Cart, wishlist, one-shot checkout, server repricing, reservation, idempotency | Multi-step checkout session state machine (§42–45), tax (§28), multiple wishlists (§65), cart merge rules for guest login (§22) to confirm |
| 12 | Payment Management & Gateways | B7 | 🟡 | Provider abstraction, COD, bank transfer, mock redirect, webhooks (HMAC + event dedupe), refunds | Real JazzCash/Easypaisa/card adapters (§20–22), reconciliation jobs (§53–55), partial payments across methods (§40), fees/limits (§37–38), timestamp replay window (§29) |
| 13 | Shipping & Delivery | B8 | 🟡 | Zones, methods, rates, pickup locations, shipments, tracking, mock courier webhooks | Real courier adapter, labels (§55), shipping classes (§23), split-shipment allocation (§66), return shipping (§70), cutoff/holiday calendars (§36–37), configuration versioning (§80), reconciliation |
| 14 | Discounts, Coupons & Promotions | B9 | 🟡 | Percentage/fixed/free-shipping promotions, coupons, targets, usage limits, order snapshots | Buy X Get Y (§13–14), tiered quantity (§12), customer group/segment/first-order (§17–20), payment-method discounts (§16), collections (§9), bulk codes (§49–51), preview/versioning (§59, §65–67) |
| 15 | Marketing & Customer Engagement | B10 | 🟡 | Campaigns, whitelisted segments, email opt-in, abandoned-cart detection | SMS/WhatsApp/push channels (§22–26), other triggers (welcome, post-purchase, win-back…, §29–44), send windows/frequency caps (§17, §50), approval/versioning (§68–69), attribution (§60–63), A/B testing (§64) |
| 16 | SEO & Content | B13 | 🟡 | SEO settings, metadata, canonicals, redirects, sitemap, robots, content pages, sanitization | Blog/articles (§22), landing pages and content blocks (§20–21), revisions/preview (§24–25), multilingual/hreflang (§27), custom head/scripts (§28), verification (§29), SEO diagnostics (§33) |
| 17 | Theme, Branding & Design System | B15 | 🟡 | One system theme, store theme config, tokens, sections, publish/rollback | Multiple themes and theme versions (§15–17), dark mode (§32), RTL (§9), logo/favicon upload with SVG sanitization (§7), customizer UI (§38), custom CSS (§36), scheduling |
| 18 | Animation & Interaction | — | ❌ | Nothing | Whole module |
| 19 | Domain Management | B14 | 🟡 | Platform subdomains, custom domains, DNS TXT verification, primary domain, SSL status field | Real SSL provisioning (§17–18), HTTP verification (§16), registrar/managed domains (§19), automated re-verification |
| 20 | Hosting & Infrastructure | B20 | ✅ | Health endpoint, Dockerfile/compose, Nginx/PHP-FPM/Supervisor templates, responsibility matrix | Actual provisioning is out of scope by design |
| 21 | Notifications & Communication | B11 | 🟡 | Templates, messages, delivery attempts, suppressions, email channel, preferences/unsubscribe | Real SMS/WhatsApp/push providers (§18–20), delivery webhooks (§35–36), localization (§15), push device tokens |
| 22 | Reports, Analytics & Dashboard | B12 | 🟡 | Store dashboard, reports, async CSV exports | Funnel/conversion analytics (§21–22), scheduled/saved reports (§41–42), attribution/sessions (§51–52), analytics for modules not yet built |
| 23 | Backup, Restore & Data Protection | B19 | 🟡 | Backups, verification, expiry, protected restore jobs | Scheduled automatic backups, media/file backup, off-site/S3 adapter, restore rehearsal, RPO/RTO (business decision) |
| 24 | Store Health, Monitoring & Resource Usage | B21 | 🟡 | Per-store health snapshots, platform outbox/API monitoring | Alert delivery (HEALTH-005), storage/bandwidth usage, host metrics (out of scope) |
| 25 | PWA / Mobile Experience | — | ❌ | Responsive storefront only | Manifest, service worker, installability, offline-safe caching, push |
| 26 | AI Features | — | ❌ | `ai.recommendations`/`ai.chatbot` entitlement flags only (unused) | Whole module |
| 27 | App / Plugin Marketplace & Integrations | — | ❌ | B18 developer apps/API keys/webhooks are a base | Extension registry, installation lifecycle, permissions, billing, review pipeline |
| 28 | Affiliate & Reseller Program | — | ❌ | Nothing | Whole module (3,500-line blueprint) |
| 29 | Billing, Invoices & Renewals | B23 | 🟡 | Package prices, invoices, hourly renewal/dunning, manual payments, revenue summary | Card gateway, proration (§47), refunds/credit notes (§42–43), add-ons (§50), usage/overage billing (§52–54), PDF invoices (§75), tax, reseller/domain/marketplace billing, financial approval (§92) |
| 30 | Umar Techy Super Admin | B16 | 🟡 | Dashboard, stores, users, packages, impersonation with reason, backups/health oversight (API) | Step-up auth (§6), emergency lock/kill switch (§14, §61), feature flags (§22), platform announcements (§71), incident management (§87–89), KYC (§93), platform terms (§94–95), Super Admin UI |
| 31 | API & Developer Platform | B18 | 🟡 | `/api/dev/v1`, applications, hashed API keys, 5 read-only scopes, rate limits, request log, signed webhooks | Write scopes, OAuth (deferred by ADR-002), OpenAPI/docs (§51), sandbox (§49), developer portal (§48), idempotency on dev API, request/correlation IDs |
| 32 | Security, Audit & Compliance | B22 | 🟡 | Hash-chained audit log, customer export/erasure, security headers, password policy | MFA (§8), session security (§9), page CSP, WORM storage for audit heads, incident response (§60–64), dependency security (§48), security dashboard (§85) |
| 33 | System Settings & Configuration | B17 | 🟡 | Platform/store settings registry, validation, encrypted secrets, revisions/rollback | Feature flags (§16–17), most per-domain settings sections (§21–47), localization/multi-currency (§48–49), timezone consumption (§50), business hours, maintenance mode (§51–52) |
| 34 | Help, Support & Documentation | B26 | 🟡 | Tickets (store, guest, customer, merchant→platform), SLA, internal notes | Knowledge base (§6–11), contextual help and onboarding (§12–15), troubleshooting/error-code docs (§24–26), release notes/changelog (§31–34), help center (§43), attachments (§49), status page (§51) |
| 35 | Future Expansion Framework | — | ❌ | Outbox and provider adapters partly serve its extension points | Extension-point registry (§5), feature flags (§10), custom fields (§17), governance (§50–53). Mostly architecture guidance, not a feature |

---

## 3. SRS requirement matrix

"API only" means the capability works through the API but has no admin screen (see finding 3).

### 3.1 Multi-tenant (MT)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| MT-001 | ✅ | `Tenancy\Store`, ADR-001 | P0 |
| MT-002 | ✅ | One store = one tenant; stable `stores.id` (ADR-001/003) | P0 |
| MT-003 | ✅ | As above | P0 |
| MT-004 | ✅ | Owner via `store_user` + owner role | P0 |
| MT-005 | ✅ | `BelongsToTenant` global scope | P0 |
| MT-006 | ✅ | `ResolveTenantContext` on every request | P0 |
| MT-007 | ✅ | Outbox/jobs carry `store_id` | P0 |
| MT-008 | ✅ | Tenant-prefixed cache keys (ADR-001) | P0 |
| MT-009 | ➖ | No search index; queries are tenant-scoped | P0 |
| MT-010 | 🟡 | Product images only; no general tenant media/file policy | P0 |
| MT-011 | ✅ | Report and customer exports scoped | P0 |
| MT-012 | ✅ | Reports scoped + permission-checked | P0 |
| MT-013 | ✅ | Webhooks resolve store by per-store secret | P0 |
| MT-014 | ✅ | Super Admin impersonation with reason + audit | P0 |
| MT-015 | ✅ | Package change keeps store and data | P0 |
| MT-016 | ✅ | Suspension is non-destructive | P0 |
| MT-017 | 🟡 | `cancelled`/`archived` states exist; no deletion/retention workflow | P1 |
| MT-018 | ✅ | `docs/testing/tenant-isolation-tests.md`, per-domain isolation tests | P0 |

### 3.2 Identity & authentication (AUTH)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| AUTH-001 | ✅ | Staff and customer registration | P0 |
| AUTH-002 | 🟡 | No customer email verification flow (B25 out of scope) | P1 |
| AUTH-003 | ✅ | Laravel hashing | P0 |
| AUTH-004 | ✅ | Staff and customer reset tokens | P0 |
| AUTH-005 | 🟡 | Logout only; no session list / revoke-all | P1 |
| AUTH-006 | ✅ | TOTP MFA for staff accounts; mandatory for platform staff and Store Owners, optional for store staff (B29, gap G2) | P1 |
| AUTH-007 | ✅ | Single-use recovery codes, stored hashed; server-side emergency reset, audited (B29) | P1 |
| AUTH-008 | ✅ | Login throttling | P0 |
| AUTH-009 | ✅ | `RecordAuthenticationEvents` → audit log | P0 |
| AUTH-010 | ✅ | Secrets not logged (B22 review) | P0 |
| AUTH-011 | 🟡 | API keys only; OAuth deferred by ADR-002 | P2 |
| AUTH-012 | ✅ | MFA mandatory for platform staff on every Super Admin route; step-up for sensitive actions (B29) | P0 |

### 3.3 Roles & authorization (RBAC)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| RBAC-001 | ✅ | `roles`, `permission_role` | P0 |
| RBAC-002 | 🟡 | 59 permissions; no complete permission catalog/matrix (Module 02 §16, §49) | P1 |
| RBAC-003 | ✅ | Policies on every controller | P0 |
| RBAC-004 | ✅ | `BaseTenantPolicy` object checks | P0 |
| RBAC-005 | 🟡 | Ad hoc (e.g. cost hidden from storefront); no general mechanism | P2 |
| RBAC-006 | ✅ | Membership required per store | P0 |
| RBAC-007 | ✅ | `X-Store-Slug` validated against membership | P0 |
| RBAC-008 | ✅ | Entitlement and permission checked separately | P0 |
| RBAC-009 | ➖ | No feature flags exist | P0 |
| RBAC-010 | ✅ | Audit log (B22) | P0 |

### 3.4 Store management (STORE)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| STORE-001 | ✅ | Registration creates a store | P0 |
| STORE-002 | ✅ | `StoreObserver` seeds roles/defaults | P0 |
| STORE-003 | 🟡 | 5 states vs 9 in Module 03 §7 | P1 |
| STORE-004 | 🟡 | Name/slug only; business identity fields minimal | P1 |
| STORE-005 | ✅ | `store_settings` | P0 |
| STORE-006 | ✅ | Storefront/theme settings (API only) | P1 |
| STORE-007 | ✅ | Invitations, role changes, suspend/reactivate/remove (B27, gap G1) | P0 |
| STORE-008 | ✅ | Explicit store header + membership | P0 |
| STORE-009 | 🟡 | No controlled closure workflow | P1 |

### 3.5 Packages & entitlements (PKG)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| PKG-001 | ✅ | `PackageSeeder` | P0 |
| PKG-002 | ✅ | Central `packages` table | P0 |
| PKG-003 | ✅ | `package_entitlements` | P0 |
| PKG-004 | ✅ | `UsageCounter`, enforcement columns | P0 |
| PKG-005 | ✅ | `EntitlementService` | P0 |
| PKG-006 | ✅ | Store/data unchanged on change | P0 |
| PKG-007 | 🟡 | New usage blocked; no over-limit detection/preview on downgrade | P1 |
| PKG-008 | ✅ | `trial_ends_at`, `trialing` status | P1 |
| PKG-009 | ✅ | Lifecycle + dunning (B23) | P1 |
| PKG-010 | 🟡 | `feature_not_entitled` error only; no decision trace | P2 |

### 3.6 Storefront (SF)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| SF-001–012 | ✅ | B24/B25 storefront, search, account, SEO, branding | P0/P1 |
| SF-013 | 🟡 | One theme only | P1 |
| SF-014 | ⚪ | No accessibility audit/tests | P1 |
| SF-015 | ⚪ | Not specifically tested | P1 |

### 3.7 Catalog (CAT) and taxonomy (TAX)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| CAT-001 | ✅ | API only | P0 |
| CAT-002–012 | ✅ | SKUs, pricing, images, variants, categories, brands, attributes, status, slugs, snapshots | P0 |
| TAX-001–007 | ✅ | Hierarchy, slugs, brands, attributes (API only) | P0 |

### 3.8 Inventory (INV)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| INV-001–009 | ✅ | B4/B8 engine, reservations, ledger, low-stock threshold, concurrency tests | P0 |

### 3.9 Orders (ORD) and customers (CUS)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| ORD-001–007 | ✅ | B5 engine, state machine, snapshots, outbox | P0 |
| ORD-008 | 🟡 | Payment refunds authorized; **no return workflow** | P1 |
| ORD-009–010 | ✅ | Idempotency; coordinated checkout | P0 |
| CUS-001–007 | ✅ | Store-scoped customers, account, export/erasure | P0 |

### 3.10 Cart & checkout (CHK)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| CHK-001–006 | ✅ | Server validation, repricing, promotions, shipping | P0 |
| CHK-007 | ❌ | **No tax engine; `tax_total = 0`** (needs a tax policy decision) | P0 |
| CHK-008–012 | ✅ | Server totals, webhook-verified payment, idempotency | P0 |

### 3.11 Payments (PAY), shipping (SHIP), promotions (PROMO), marketing (MKT)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| PAY-001 | ✅ | `PaymentGatewayContract` | P0 |
| PAY-002 | 🟡 | COD, bank transfer, mock only | P1 |
| PAY-003–004 | ✅ | COD; payment attempts | P0 |
| PAY-005 | 🟡 | Verified only against the mock gateway | P0 |
| PAY-006–007 | ✅ | HMAC + event-ID dedupe | P0 |
| PAY-008 | 🟡 | Dedupe by event ID; no timestamp window | P0 |
| PAY-009–011 | ✅ | No card data; no false paid; refund policy | P0 |
| SHIP-001–006, 008 | ✅ | Zones, rates, free shipping, tracking history | P0 |
| SHIP-007 | 🟡 | Adapter exists; mock courier only | P1 |
| PROMO-001–007 | ✅ | B9 (API only) | P0 |
| MKT-001–005 | ✅ | Email only (see Module 15 gaps) | P1 |

### 3.12 SEO (SEO), theme (THEME), animation (ANIM), domains (DOM), hosting (HOST)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| SEO-001–007 | ✅ | B13 + `HtmlSanitizer` | P1 |
| THEME-001 | 🟡 | One theme | P1 |
| THEME-002–005 | ✅ | B15 | P1 |
| THEME-006 | ⚪ | Not audited | P1 |
| ANIM-001–004 | ❌ | Module 18 not built | P2 |
| DOM-001–004, 006 | ✅ | B14 | P1 |
| DOM-005 | 🟡 | SSL status field only | P1 |
| HOST-001–005 | ✅ | B20 templates, env config | P1 |

### 3.13 Notifications (NOTIF), analytics (AN), backup (BKP), health (HEALTH)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| NOTIF-001–002, 005–008 | ✅ | B11; email (live mail delivery not yet verified) | P1 |
| NOTIF-003 | ❌ | Push is a stub | P2 |
| NOTIF-004 | 🟡 | SMS/WhatsApp stubs, no provider | P1 |
| AN-001–007 | ✅ | B12 + B16 platform dashboard | P1 |
| BKP-001 | ✅ | Daily and monthly scheduled platform backups (B30, gap G4) | P0 |
| BKP-002–006 | ✅ | Expiry, verification, restricted, protected restore, docs | P0 |
| BKP-007 | ✅ | Weekly restore rehearsal into a throw-away database (B30). A production restore has not been executed | P1 |
| HEALTH-001–002, 004, 006 | ✅ | B20/B21 | P1 |
| HEALTH-003 | 🟡 | No storage/bandwidth metering | P2 |
| HEALTH-005 | 🟡 | Critical email alerts for backup and restore conditions (B30). Store-health and security conditions do not alert yet | P1 |

### 3.14 PWA, AI, marketplace (APP), affiliate (AFF)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| PWA-001, 003 | ❌ | No manifest/service worker | P2 |
| PWA-002 | ✅ | Responsive storefront | P1 |
| PWA-004–005 | ➖ | No offline mode yet | P0 when built |
| AI-001–002, 007–008 | ❌ | Module 26 not built | P2 |
| AI-003–006 | ➖ | Constraints; must hold once AI exists | P0 when built |
| APP-001–004, 006–007 | ✅ | B18 developer applications, scopes, revocation, request log | P2 |
| APP-005 | ❌ | No marketplace installation | P2 |
| AFF-001–005 | ❌ | Module 28 not built | P2 |

### 3.15 Billing (BILL), Super Admin (SA), API (API)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| BILL-001–004, 007–008 | ✅ | B23 | P0 |
| BILL-005 | ➖ | No billing gateway, so no billing webhooks | P1 |
| BILL-006 | 🟡 | Voids authorized; refunds/credit notes not built | P1 |
| SA-001–003 | ✅ | B16 (API only) | P0 |
| SA-004 | ✅ | Reason required; step-up re-authentication on impersonation and 13 other actions (B29) | P0 |
| SA-005 | 🟡 | No mass-action safeguards (no bulk actions yet) | P1 |
| SA-006 | ➖ | No break-glass feature | P2 |
| API-001–005, 008–009, 013–014, 016 | ✅ | Versioned routes, auth, tenant, rate limits, pagination, allowlists, signed webhooks with retries, key rotation | P0 |
| API-006 | 🟡 | Per-key rate limits; no package API quotas | P1 |
| API-007 | 🟡 | Checkout/order idempotency; not general | P1 |
| API-010 | 🟡 | JSON errors, but not the Master Prompt §15 shape `{code,message,details,requestId}` everywhere | P1 |
| API-011 | ✅ | `X-Request-Id` on every response, in logs and audit entries (B29) | P1 |
| API-012 | ❌ | No OpenAPI | P1 |
| API-015 | 🟡 | Attempt ledger; dead-letter semantics to confirm | P1 |
| API-017 | ❌ | No sandbox | P2 |

### 3.16 Security (SEC), configuration (CFG), support (SUP)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| SEC-001–003, 006–012 | ✅ | B22, encrypted settings, validated uploads, rate limits, audit | P0 |
| SEC-004 | ⚪ | HTTPS is a deployment concern (Nginx template redirects) | P0 |
| SEC-005 | 🟡 | No data-classification register | P1 |
| SEC-013 | ✅ | `composer audit` and `npm audit` in CI (B29). Build tooling advisories open | P0 |
| SEC-014 | 🟡 | Dependency gate added (B29). No secret scanning or static security analysis | P0 |
| SEC-015 | ✅ | `docs/security/incident-response-runbook.md` (B29). Contacts to be filled by the owner | P1 |
| CFG-001–004, 006–009 | ✅ | B17 | P1 |
| CFG-005 | ❌ | No feature flags | P2 |
| SUP-001 | ❌ | **No documentation/help architecture** | P1 |
| SUP-002–004 | ✅ | B26 | P1 |
| SUP-005–006 | ❌ | No knowledge base, no release notes | P2 |
| SUP-007 | ⚪ | Depends on SUP-001 | P2 |

### 3.17 Data, NFR, performance, reliability, localization, accessibility, audit

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| DATA-001–006, 008–009 | ✅ | ADR-003, FKs, snapshots, retention for erasure | P0 |
| DATA-007 | ➖ | No import workflows exist | P1 |
| DATA-010 | ✅ | Money in minor units; store timezone consumed through `StoreClock` (B28, gap G5) | P0 |
| NFR-001–003, 005–006, 008, 010–012 | ✅ | Modular monolith, tests, outbox | P1 |
| NFR-004, 009 | ⚪ | Deployment-dependent | P1 |
| NFR-007 | 🟡 | No image renditions, no page CSP | P1 |
| PERF-001–003 | ⚪ | No systematic query review recorded | P1 |
| PERF-004 | 🟡 | Lazy loading; no renditions | P1 |
| PERF-005–006 | ✅ | Tenant cache, queued jobs | P1 |
| PERF-007 | 🟡 | Usage counters; no storage/bandwidth | P2 |
| PERF-008 | ❌ | No APM/latency monitoring | P1 |
| REL-001–007 | ✅ | Transactions, idempotency, outbox dead-letter, health checks, docs | P0 |
| LOC-001–002, 006 | ❌ | **No i18n / RTL** | P1 |
| LOC-003 | ➖ | — | — |
| LOC-004 | 🟡 | Timezone respected (B28). Formats follow the browser locale, not the store locale (G11) | P1 |
| LOC-005 | ✅ | Store currency | P1 |
| A11Y-001–004, 006 | ⚪ | No accessibility audit | P1 |
| A11Y-005 | ➖ | No animations yet | P1 |
| AUD-001–006 | ✅ | Hash-chained audit log (WORM storage pending) | P0 |

### 3.18 Development, deployment, testing (DEV, DEP, TEST)

| ID | Status | Evidence / gap | Pri |
|---|---|---|---|
| DEV-001 | 🟡 | Specs were not in the repo until today (fixed in `docs/source/`) | P0 |
| DEV-002–010 | ✅ | Per-phase inspection, checkpoints, reviews | P0 |
| DEP-001 | 🟡 | Documented; no staging environment exists | P1 |
| DEP-002 | ✅ | `.env`, secrets out of Git | P0 |
| DEP-003–008 | ⚪ | No production deployment yet | P1 |
| TEST-001–008, 012 | ✅ | 876 backend + 32 frontend tests, CI green on PR #2 | P0 |
| TEST-009 | ❌ | **No E2E tests in CI** (Playwright installed, no tests) | P1 |
| TEST-010 | ⚪ | No pre-release security test run | P0 |
| TEST-011 | ✅ | Restore rehearsal: scheduled, on demand, and run against real MySQL in CI (B30) | P1 |

---

## 4. Gap register (actionable items)

Items are grouped so each group can become one phase. Tests follow the
existing pattern: feature tests plus tenant-isolation tests for every endpoint.

| # | Gap | Sources | Risk if left | Proposed action | Pri | Depends on |
|---|---|---|---|---|---|---|
| G1 | ✅ **Closed in B27** (checkpoint-b27). Store staff management (invite, roles, remove, 7 predefined roles, custom roles) | STORE-007, RBAC-002, M02 §6, §17–19, §29 | A store cannot add its team | Build invitations, membership API + UI, role catalog | P0 | — |
| G2 | ✅ **Closed in B29** (checkpoint-b29; open findings in docs/security/b29-security-baseline.md). Security baseline: MFA, Super Admin step-up, request IDs, dependency scanning in CI, incident runbook | AUTH-006/007/012, SA-004, API-011, SEC-013/014/015, M32 §8, M30 §6 | Account takeover of privileged users; blind operations | MFA (TOTP) + recovery codes, re-auth for sensitive actions, correlation-ID middleware, `composer audit`/`npm audit` CI step, runbook | P0 | — |
| G3 | Tax calculation | CHK-007, M11 §28 | Incorrect totals if tax applies | **Business decision first**: tax rules (inclusive/exclusive, rates, regions) | P0 | Owner decision |
| G4 | ✅ **Closed in B30** (checkpoint-b30; limitations listed there). Scheduled backups + restore rehearsal + alerting | BKP-001/007, HEALTH-005, TEST-011 | Data loss; failures go unnoticed | Schedule backups, rehearsal command, alert channel for critical health | P0 | Alert channel choice |
| G5 | ✅ **Closed in B28** (checkpoint-b28). Store timezone consumption | DATA-010, LOC-004, M33 §50 | Wrong dates for scheduled promos/campaigns/SLA | Wire `store.timezone` into B10/B12/B13/B26 date logic | P0 | — |
| G6 | Admin UI for API-only modules | Admin Capabilities in M06–M17, M19, M21–M23, M29–M31, M33 | Owners cannot operate the store | UI phases, highest value first: catalog, customers, shipping/payments settings, promotions, SEO/content, theme, domains, settings | P1 | G1 |
| G7 | Module 10 completion (groups, tags, notes, blocking, merge, import, guest conversion, email verification) | M10 §9, §24–33, §47–57, AUTH-002 | Blocks targeted promotions (M14 §17–20) and marketing | Complete Module 10 | P1 | — |
| G8 | Returns, exchanges, refunds flow | ORD-008, M09 §45–54, M13 §70 | Manual handling only | Return request → inspection → refund/restock | P1 | — |
| G9 | Real payment and courier providers | PAY-002/005, SHIP-007, M12 §20–21 | Online payment not possible | **Owner decision**: providers and merchant credentials; then adapters | P1 | Owner decision |
| G10 | Real SMS/WhatsApp/push providers | NOTIF-003/004, M21 §18–20 | WhatsApp is central for the Pakistan market (M01 §14) | **Owner decision**: provider; then adapters | P1 | Owner decision |
| G11 | Localization + RTL (Urdu) | LOC-001/002/006, M05 §40–41, M17 §9 | Cannot serve Urdu storefronts | i18n framework, RTL-aware components | P1 | — |
| G12 | Module 34 documentation side | SUP-001/005/006, M34 §6–15, §31–34, §43 | No self-help for merchants | Knowledge base, help center, release notes | P1 | — |
| G13 | API contract quality: error shape, OpenAPI, E2E tests | API-010/012, TEST-009, Master Prompt §15 | Integrators break; regressions reach users | Standard error envelope, OpenAPI generation, Playwright journeys in CI | P1 | G2 (request IDs) |
| G14 | Store lifecycle states + closure/retention workflow | STORE-003/009, MT-017, M03 §7–8, §35–37 | Undefined behavior on cancellation/deletion | Add missing states and a retention-safe deletion workflow | P1 | Retention period decision |
| G15 | Catalog/taxonomy depth: collections, import/export, bulk ops, tags, attribute sets, filters | M05 §16, M06 §34–51, M07 §18–19, §44–47 | Onboarding existing merchants is slow | Collections first (also unblocks M14 §9), then import/export | P1 | — |
| G16 | Module 25 PWA | PWA-001/003, M25 | Mobile install/offline missing | Build per blueprint (after G11 and G6 theme) | P2 | G11 |
| G17 | Module 18 Animation | ANIM-001–004, M18 | Visual polish only | Build per blueprint | P2 | M17 |
| G18 | Module 26 AI | AI-001–008, M26 | Premium feature missing (M04 §8) | Provider choice, then build with M26 safety layers | P2 | Owner decision on provider |
| G19 | Module 27 Marketplace | APP-005, M27 | No extension ecosystem | Build on B18 | P2 | G13 |
| G20 | Module 28 Affiliate & Reseller | AFF-001–005, M28 | No partner growth channel | Build per blueprint | P2 | G7, M29 extensions |
| G21 | Billing depth: proration, refunds/credit notes, add-ons, PDF, card gateway | BILL-006, M29 §42–54, §75 | Manual finance work | Extend B23 | P2 | G9 |
| G22 | Feature flags + Module 35 extension points | CFG-005, M33 §16–17, M35 §5, §10 | Risky rollouts | Feature-flag service distinct from entitlements | P2 | — |

## 5. Owner decisions (answered 2026-09-30)

The owner answered every question the same day. The full text is in
[`docs/source/decisions/2026-09-30-project-decisions.txt`](../source/decisions/2026-09-30-project-decisions.txt).

| # | Question | Answer | Effect |
|---|---|---|---|
| 1 | URS and Technical Architecture | Supplied; stored in `docs/source/urs/` and `docs/source/technical-architecture/` | Traceability chain complete |
| 2 | Tax policy (G3) | Configurable per store and jurisdiction: inclusive/exclusive pricing, tax classes, rates, regions. **No hardcoded rate.** Pakistan rates are configured later, once confirmed legally | G3 builds the engine with **no seeded rates** |
| 3 | Payment and courier (G9) | JazzCash, Easypaisa, and a card provider, all behind adapters. No fake credentials; sandbox credentials come from the providers and stay outside Git. Courier chosen during implementation from real API/sandbox availability | G9 waits for sandbox credentials |
| 4 | SMS/WhatsApp (G10) | WhatsApp: Meta WhatsApp Business Cloud API. SMS: abstraction; Twilio or a Pakistan-local provider after verification | G10 can build the Meta adapter shape; no live calls without credentials |
| 5 | Alert channel (G4) | Email mandatory; WhatsApp configurable; Slack future-ready | G4 builds email alerts + channel abstraction |
| 6 | Retention, RPO/RTO (G4, G14) | Closed/suspended stores: 90 days. Backups: daily 30 days, monthly 12 months, plus pre-change backups. RPO 24 h, RTO 4 h are **targets** until tested | G4 retention policy; G14 closure workflow |
| 7 | Inventory costing | **None** in the initial system; FIFO/WAC only via a future approved requirement | B4's open decision closed; nothing to build |
| 8 | AI provider (G18) | OpenAI behind a provider abstraction; Module 26 must first define features, models, privacy, entitlements, limits, costs, audit, fallback | G18 starts with that definition |
| 9 | Priorities | **Approved** as proposed | — |

## 6. Recommended order

This follows the Master Prompt §40 dependency order, adjusted for what exists:

1. **G1 + G2 + G5 + G4.** P0 gaps that need no business decision: staff
   management, security baseline, timezone wiring, scheduled backups and alerts.
2. **G3 and G9–G10** as soon as the owner's decisions arrive.
3. **G6 admin UI**, together with **G7** (Module 10), **G8** (returns) and
   **G15** (catalog depth). This is the minimum a merchant needs to run a store
   without the API.
4. **G11 localization**, then **G12, G13, G14**.
5. **New modules in blueprint order:** G17 (Module 18), G16 (Module 25),
   G18 (Module 26), G19 (Module 27), G20 (Module 28), then G21 and G22.

Before each phase, read that module's full blueprint (`docs/source/module-blueprints/`)
and do a section-by-section inspection. This matrix is the starting point, not a
substitute for that inspection.
