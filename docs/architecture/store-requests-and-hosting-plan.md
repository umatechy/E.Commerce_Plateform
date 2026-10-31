# Stores built by the team, the customer's domain, and the choice of hosting

Owner decisions of 2026-10-30 and 2026-10-31:

- The platform is **closed to the public**: businesses contact Umar Techy, and
  the team creates every store on the platform.
- A store opens **only on the customer's own domain** — no public platform
  subdomain.
- **Hosting is the customer's choice:** Umar Techy hosting (charged at the
  package's yearly renewal price), or the customer's own server — paid first,
  no free trial, and still connected to Umar Techy for its licence and updates
  (model B1, Module 04 §51 "Customer-hosted stores").
- After a store moves to the customer's server the team can still change it
  through its admin (a support account the customer can switch on or off);
  new features reach it as updates.
- Shop traffic gets its own IPv4 address on the Hetzner server so customers'
  domains never touch School ERP's Nginx.

Blueprints: Module 03 §5A/§38 (stores created by staff), Module 19 (domains),
Module 20 §50 "Hosting plan integration" (hosting entitlements separate from
features; the store keeps its ID), §51 "Hosting migration", Module 04 §51.

## S1 — closed to the public, requests to the team (done)

- Sign-up stays behind the existing switch `platform.self_signup_enabled`
  (Super Admin → Security → Emergency controls). With it off, the home page
  asks instead: "Ask for your store", "How it works" describes the team
  building the store, every package card says "Ask for <package>".
- **Request form** on the home page (`POST /api/v1/public/store-requests`,
  3 per 10 minutes per visitor, honeypot): name, business, WhatsApp/phone,
  email, city, what they sell, package, their domain, and where the store
  should run (`umar_techy`, `own_server`, `not_sure`). A second request from
  the same email while the first is new updates it.
- The person gets a confirmation email; every active platform staff member
  with the `stores` capability gets the request by email.
- **Super Admin → Store requests** (`/super-admin/store-requests`; reading
  needs "view", changing needs `stores`): call or WhatsApp them, note what was
  agreed (contacted / declined), remove a request with step-up (its contact
  details go), and **Create store** — the existing create-store form, filled
  from the request (trial 0 for their own server), which links the request to
  the new store (`store_request_id`).
- WhatsApp button on the home page: platform setting
  `platform.contact_whatsapp` (digits with the country code). No number is
  written in the code.
- `store_requests` is a platform table; a request linked to a store is purged
  with the store (StoreDataMap).

## S2 — the customer's domain on Umar Techy hosting (next)

Separate IPv4 for the shop, its own TLS-terminating proxy that issues and
renews a certificate for each verified domain, a private preview for the team
and the owner while the store is being built, and no public platform
subdomains.

## S3 — hosting choice and charges

Each store records its hosting (Umar Techy / own server). Umar Techy hosting is
billed at the package's yearly renewal price; a store for its own server is
paid first, with no trial.

## S4 — a store on the customer's own server (B1)

Single-store edition (`PLATFORM_MODE=single`), a store bundle (data, files,
theme, settings, accounts) exported from the platform and imported there, an
installer, a signed licence file, an update channel, a support account the
customer controls, and the handover: the platform copy is closed and removed
after a retention period.

Still to decide: the yearly fee for B1 updates and support, and how long the
platform keeps a moved store's data (30 days proposed).
