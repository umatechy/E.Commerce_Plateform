# Source Specifications — Umar Techy E-Commerce Platform

This folder holds the **authoritative project specifications** exactly as the
project owner supplied them. They sit at the top of the document hierarchy
(see [`docs/adr/README.md`](../adr/README.md)):

```
Project Bible  >  SRS / URS  >  Technical Architecture  >  Module Blueprints (01–35)
               >  Approved ADRs / RFCs  >  Existing Implementation
```

Before phases B0–B26 these documents were only pasted into chat sessions and
never stored in the repository. That is how Modules 01, 26 and 28 came to be
reported as "unknown" in a later session. Keeping them here lets every future
session read the real specification instead of relying on second-hand
references in checkpoints.

## Rules

1. **Do not edit these files.** Store them byte-for-byte as supplied.
   `.gitattributes` marks `docs/source/**/*.txt` as `-text`, so Git never
   rewrites their line endings, and [`SHA256SUMS`](SHA256SUMS) proves they are
   unchanged (`cd docs/source && sha256sum -c SHA256SUMS`).
2. A newer version is **added as a new file** (e.g. `..._v1_1.txt`) and listed
   below. The old version is kept, never overwritten.
3. If code or an ADR conflicts with these documents, **report the conflict**.
   Do not silently resolve it. The higher document wins until it is reviewed.
4. Read the relevant module blueprint **before** starting any phase, as the
   Master Development Prompt requires.

## Inventory

First batch received on 2026-09-30 from the project owner. The files were taken from the
owner's local folder `E.Commerce Platform Blue prints` and are identical to
`umartechy-ecommerce-blueprints.zip`, which also has 39 files with matching
SHA-256 checksums. The 20 files also pasted into chat that day match them too.

| Document | Path | Status |
|---|---|---|
| Project Bible v1.0 | [`project-bible/`](project-bible/) | ✅ Received |
| SRS v1.0 | [`srs/`](srs/) | ✅ Received |
| Master Project Blueprint — Master Index | [`master-index/`](master-index/) | ✅ Received |
| Claude Master Development Prompt v1.0 | [`master-development-prompt/`](master-development-prompt/) | ✅ Received |
| Module Blueprints 01–35 (all 35) | [`module-blueprints/`](module-blueprints/) | ✅ Received |
| URS v1.0 (Approved Planning Baseline) | [`urs/`](urs/) | ✅ Received 2026-09-30 (owner's `Downloads`, `..._FINAL.txt`) |
| Technical Architecture v1.0 | [`technical-architecture/`](technical-architecture/) | ✅ Received 2026-09-30 (owner's `Downloads`, `..._FINAL.txt`) |
| Project Decisions — Clarification Baseline | [`decisions/2026-09-30-project-decisions.txt`](decisions/2026-09-30-project-decisions.txt) | ✅ Owner's answers to the gap-matrix questions (tax, providers, alerts, retention/RPO/RTO, costing, AI). The owner also approved the gap-matrix priorities the same day |

All documents referenced by the Master Development Prompt §1 are now present.

## Module map (from the Master Index)

| # | Module | # | Module |
|---|---|---|---|
| 01 | Complete Platform Structure | 19 | Domain Management |
| 02 | Authentication, Users, Roles & Permissions | 20 | Hosting & Infrastructure Management |
| 03 | Multi-Tenant & Store Management | 21 | Notifications & Communication |
| 04 | Subscription, Packages & Feature Entitlements | 22 | Reports, Analytics & Dashboard |
| 05 | Storefront / Customer-Facing Website | 23 | Backup, Restore & Data Protection |
| 06 | Product & Catalog Management | 24 | Store Health, Monitoring & Resource Usage |
| 07 | Category, Brand & Attribute Management | 25 | PWA / Mobile Experience |
| 08 | Inventory & Stock Management | 26 | AI Features |
| 09 | Order Management | 27 | App / Plugin Marketplace & Integrations |
| 10 | Customer Management | 28 | Affiliate & Reseller Program |
| 11 | Cart, Wishlist & Checkout | 29 | Billing, Invoices & Renewals |
| 12 | Payment Management & Gateways | 30 | Umar Techy Super Admin |
| 13 | Shipping & Delivery Management | 31 | API & Developer Platform |
| 14 | Discounts, Coupons & Promotions | 32 | Security, Audit & Compliance |
| 15 | Marketing & Customer Engagement | 33 | System Settings & Configuration |
| 16 | SEO & Content Management | 34 | Help, Support & Documentation |
| 17 | Theme, Branding & Design System | 35 | Future Expansion Framework |
| 18 | Animation & Interaction System | | |

Module blueprint files are named
`module-blueprints/Umar_Techy_Ecommerce_Master_Project_Blueprint_Module_NN_EN.txt`.

## Next step this enables

The implementation has not yet been reconciled against these documents.
Phases B0–B26 were built from the chat copies, and some modules only in
part. A reconciliation pass should compare each module's blueprint with its
implementation and checkpoint before new modules start.
