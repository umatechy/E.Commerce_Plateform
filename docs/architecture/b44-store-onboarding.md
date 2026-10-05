# B44 — Store onboarding: both creation models

Gap **G23**, owner decision 13 (2026-10-04): stores are created **both** by
customers themselves and by the Umar Techy team, through **one** creation
service; platform settings decide whether public sign-up is open and whether a
store may go live only after its first payment.

Specs read before building: Module 03 §5–8 (creation models, workflow,
lifecycle, statuses), §14 (identity), §23–26 (defaults, checklist, readiness,
owner creation), §27 (multi-store), §38 (Super Admin store management), §55–58
(creation service, failure handling, onboarding UX, package-aware onboarding),
§63–64; Module 04 §14–17 (trial, subscription states); Module 02 §18
(invitations).

## 1. Audit first — what was reused

| Need | Existing code | Decision |
|---|---|---|
| Create a store | inline in `AuthController::register`, and again in `demo:store` | one `StoreProvisioningService`; register **and** the demo command now use it (duplication removed) |
| Defaults (7 roles, warehouse, shipping zone + pickup, order numbering, secrets) | `StoreObserver` | unchanged — runs for every store, whatever creates it |
| Trial, past due, grace period | `Subscription` states + billing engine (B23) | **not duplicated** on the store (see §3) |
| Owner invitation | B27 `StoreInvitation` + `StoreTeamService::accept()` | new `inviteOwner()`; acceptance is the existing flow |
| Pay before live | `InvoiceLedger::issueNextPeriod()`, Super Admin `recordPayment` (step-up) | a launch check + "Get my first invoice" |
| Setup checklist | `StorefrontSetupService` (6 items) | extended in place (§4) |

## 2. Data

Migration `2029_02_01_000001_store_onboarding`, on `stores`:
`business_category` (fixed list, `BusinessCategories`), `created_via`
(`self_service` | `platform`), `created_by_user_id`, `activated_at` (set at
launch; existing live stores backfilled with their creation time).

Settings (Module 33 registry, with history and rollback):

| Key | Scope | Default | Meaning |
|---|---|---|---|
| `platform.self_signup_enabled` | platform | true | public sign-up open |
| `platform.launch_requires_payment` | platform | false | launch needs a paid invoice |
| `platform.trial_package` | platform | basic | must be an active package |
| `platform.trial_days` | platform | 14 | (env config stays the fallback) |
| `store.legal_name`, `store.contact_email`, `store.contact_phone`, `store.country` | store | —, —, —, PK | business information, validated |

## 3. Lifecycle without two sources of truth

Module 03 §7 suggests store states PROVISIONING, ONBOARDING, TRIAL, ACTIVE,
GRACE_PERIOD, SUSPENDED, CANCELLED, ARCHIVED, DELETING. TRIAL and
GRACE_PERIOD (and past due) are already **subscription** states, moved by the
billing engine; copying them onto the store would let the two drift. So:

- the store keeps its operational status (pending_setup = onboarding, active,
  suspended, cancelled, archived);
- `StoreLifecycle` derives the **stage** shown to people: awaiting owner,
  onboarding, trial, active, payment due, grace period, suspended, cancelled,
  archived;
- PROVISIONING needs no state: creation is one transaction (§56), so a
  half-made store never exists;
- DELETING belongs to the closure workflow (gap G14, phase B51).

This is a documented resolution of the blueprint's *suggested* states, not a
silent change.

## 4. Flows

**Self-service** (`POST /api/v1/auth/register`): refused with `signup_closed`
when sign-up is closed; otherwise account + store (with business category) +
owner membership + trial on the platform's trial package and length, atomic.

**Platform-assisted** (`POST /api/v1/super-admin/stores`, platform staff,
step-up, idempotent by `Idempotency-Key` for 24 h): store name, what it sells,
package (by the customer's budget), trial days 0–90 (0 = first invoice due at
once), owner email. The owner invitation (14 days, `owner_invitation_ttl_days`)
is written in the new store's context (`TenantContext::asStore()`), its email
says Umar Techy created the store; accepting creates the account (or uses the
customer's existing one — one person may own several stores, §27) and makes them
owner; owner MFA is then mandatory as for every owner. A new invitation from the
store page replaces the old link. The owner invitation takes no team seat.

**Setup checklist** (`GET /api/v1/storefront/setup`): three groups —
essentials (business info*, products*, subscription*, first payment* when
required, categories, logo), running the store (payment methods, shipping,
warehouse, policies, test order), growing it (SEO description, custom domain
only if the package has it — §58). `*` = required. Answers with progress
(done/total/percent), the blocking keys and whether payment is required.

**Live after payment**: with the setting on, "first payment" is required;
`POST /api/v1/billing/first-invoice` issues the first period's invoice now (once;
`no_price` when the package has no price); the team records the payment (step-up);
the store can launch.

## 5. Screens

- Sign-up: "What will you sell?"; when closed, a message to contact the team.
- Owner dashboard: setup progress bar, groups, blocking count, "Get my first
  invoice".
- Settings: business information.
- Super Admin → Stores: "Create store for a customer"; columns stage, package,
  sells, owner (invited), created by; filters by category and creator.
- Super Admin → store: stage, sells, created by, live since; owner and setup
  card with "Send again".
- Super Admin → Platform settings: the four new settings.

## 6. Not built here

- Business-category starter templates — B45.
- Online payment of the first invoice — B48 (providers); now bank transfer /
  cash recorded by the team.
- Trial abuse controls beyond email uniqueness (Module 04 §16, "finalized
  later").
- Ownership transfer (Module 03 §28) and store closure (G14, B51).
