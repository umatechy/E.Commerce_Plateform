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

## S2 — the customer's domain on Umar Techy hosting

### S2a — the application (done)

- **Steps to live** (`DomainConnectionService`): ownership (TXT record) →
  address (the domain's A record is exactly `domains.shop_ipv4`, env
  `SHOP_PUBLIC_IPV4`) → certificate (an HTTPS request with TLS verified
  completes) → the domain is Active, and the store's address if it has no live
  one. `domains:check-connections` runs every five minutes (domains on their way
  at most every ten minutes each, live ones daily); "Check now" asks at once.
- **Settings → Domains** shows those steps with the records to copy. Platform
  subdomains are no longer listed.
- **`GET /api/v1/public/tls-allowed?domain=`** answers the proxy's on-demand TLS
  question: 200 only for a customer's domain with proven ownership (and its
  `www.` form); 404 otherwise, so certificates cannot be requested for other names.
- **Public on its own domain only:** platform subdomains are records, never
  served or linked (`PLATFORM_SUBDOMAINS_PUBLIC`, default off). An open store's
  `/shop/<slug>` address forwards (301) to its live domain; `www.` forwards to
  the domain. Before launch the store is seen only by its team: that is the
  private preview.
- **Launch requires the domain** (`platform.custom_domain_required`, default on):
  live with its certificate. `demo:store`, which refuses to run in production,
  is exempt.
- **Two earlier defects fixed:** `DnsResolverContract` was never bound, so domain
  verification over HTTP failed since B14; and canonical URLs and email links
  pointed at platform subdomains that production never served.

### S2b — the server (needs the owner's decision)

Found on the server: School ERP's Nginx publishes ports 80 and 443 on **every**
address (`0.0.0.0`), so a second IP on the same server cannot reach a separate
proxy on those ports unless ERP's published ports are limited to its own IP —
which changes ERP's Compose file and recreates its Nginx container (seconds of
ERP downtime). The options are in the phase report; nothing on the server has
been changed for S2.

**Until S2b is live no new store can launch** with the setting on, because no
domain can reach its certificate: deploy S2a together with S2b.

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
