# B57 — The store affiliate program (gap G20, part 1)

Sources: Module 28 (Affiliate & Reseller Program), SRS AFF-001–005, Module 04
(entitlements), Module 30 (step-up), Module 32 (audit). Phase B57 builds the
**program engine** and the **store affiliate program** end to end. The
**platform reseller program** (Umar Techy packages sold by resellers, §2.5,
§46, §56–60) is the proposed next phase (B58).

## 1. Scope

| Module 28 area | B57 |
| --- | --- |
| Program and rules (§3.2, §7, §65–66, §108) | ✅ one program per store; rules as immutable versions |
| Affiliates, applications, approval (§4, §43, §91) | ✅ added by staff or applied on the storefront; approve/decline/pause/resume |
| Referral links and codes (§8–12) | ✅ `?ref=CODE` on any storefront page; coupon codes linked to affiliates |
| Attribution (§12–16) | ✅ signed device token, window, coupon beats link, one commission per order |
| Self-referral and fraud signals (§40–41) | ✅ same customer account / email / phone → review or reject; click flooding counted once per network a day |
| Commission calculation (§17–20, §65) | ✅ integer minor units, basis points, cap, minimum order, new customers only, never more than the base |
| Qualification and holds (§21–24) | ✅ delivered, paid, hold period; hourly follow-up |
| Reversals and clawback (§25–29) | ✅ cancellation rejects; refunds reverse proportionally; after payment → negative adjustment |
| Payouts (§33–39) | ✅ manual: prepare, pay outside the platform, record with reference; threshold; one open payout |
| Adjustments (§38) | ✅ audited, with reason; settled with the next payout |
| Affiliate dashboard (§43, §76, §135) | ✅ private page on the storefront, no customer data |
| Store owner dashboard (§45) | ✅ Marketing → Affiliates |
| Notifications (§47) | ✅ welcome (private link), application received (applicant and owner) |
| Reseller program, reseller pricing, provisioning (§2.5, §46, §56–60, AFF-004) | ❌ B58 |
| Platform referral of packages, recurring commissions (§852) | ❌ B58 |
| Multi-level / tiered commissions, product-specific rates (§18–19) | ❌ later |
| Automated payouts through a provider (§36) | ❌ no provider; owner decision on adapters applies |
| Tax on commissions, affiliate tax documents (§39) | ❌ not built: no hardcoded rates (owner decision) |

## 2. Data

Migration `2030_04_01_000001_affiliates.php`. Every table has `store_id` and is
tenant-scoped (BelongsToTenant). All 7 tables are PURGE in StoreDataMap.

- `affiliate_programs`: name, status (draft/active/paused), accepts_applications, current_version_id.
- `affiliate_program_versions`: version, rules (json), terms, currency. **Never updated**. A program has
  no version until the owner first saves rules, so the first saved rules are version 1 and starting
  requires saved rules (`no_rules`).
- `affiliates`: code (unique per store), coupon_id, customer_id, encrypted payout_details,
  portal_token_hash (SHA-256 only), status, source (added/applied), terms_version.
- `affiliate_clicks`: one row per affiliate and day, a counter. No IP or user agent is stored.
- `affiliate_commissions`: one per order (unique order_id), attribution_source, status
  (pending/review/approved/paid/rejected/reversed), base, amount, reversed, snapshot of the rules,
  flags, hold_until, affiliate_payout_id.
- `affiliate_payouts`: pending/paid/cancelled, amount, method, reference, paid_at.
- `affiliate_adjustments`: signed amount, reason, created_by (null = automatic), affiliate_payout_id.

Package feature `affiliate.programs` (Business, Premium). Permissions `affiliates.view`,
`affiliates.manage` (administrator, manager, content-marketing) and `affiliates.payouts`
(administrator).

## 3. Attribution flow

1. A storefront page with `?ref=CODE` (any page): `AffiliateAttribution::fromLanding` checks
   the program (entitled and running) and an **active** affiliate with that code. It counts a
   click once per affiliate, day and network (`Cache::add` keyed by a hash of the IP; the IP
   is never stored). It returns a token `base64url(store.affiliate.issued_at).HMAC(app key)` and
   the window in days, as the `storefront.referral` prop.
2. The storefront (`Storefront/referral.ts`) keeps it in `localStorage['ut-ref-{slug}']` with
   its expiry. The latest link wins. Nothing is put in a cookie.
3. Checkout sends `referral_token`. After the order is created,
   `AffiliateAttribution::attribute($order, $token)` runs in a `try/catch` that only reports:
   **an affiliate problem never blocks an order**.
4. `attribute()`: a coupon linked to an affiliate on the order wins over the link. Otherwise
   the token's signature, store, age (window) and the affiliate's status are checked again.
   The minimum order and the new-customers-only rule apply. Self-referral signals set
   `review` or `rejected`. The commission is made with a snapshot of the version's rules.

Base = products after discounts (subtotal − discount), never shipping or tax.

## 4. Lifecycle (`affiliates:follow-up`, hourly)

- Cancelled order → `rejected` (or taken back by adjustment if it was already paid or in a payout).
- Refunds: succeeded `refund`/`partial_refund` payment transactions plus refunded store
  credit from returns, as a share of the grand total. That share of the commission is
  taken back: `reversed_minor` while unpaid, a negative automatic adjustment once paid or in
  a payout. The amount already taken counts earlier adjustments, so re-running changes nothing.
- Approval: delivered (timeline `to_status = delivered`), paid when required, and the hold
  period from delivery. `hold_until` is shown to the owner.

## 5. Payouts

`createPayout()` locks the affiliate's approved, unpaid commissions and unsettled adjustments.
It needs an active affiliate, payout details, no other open payout and the threshold.
`markPaid(reference)` marks the commissions `paid`. `cancelPayout()` releases them. The owner
pays outside the platform (bank, JazzCash, Easypaisa); **no money moves through Umar Techy**.

Where to pay is encrypted. The admin sees the last four digits only. The full account is
returned by `createPayout` and `POST /affiliates/payouts/{payout}/pay-to`. Both need
`affiliates.payouts` and step-up, and each is audited
(`affiliates.payout_details.revealed`).

## 6. The affiliate's private page

`/affiliate` on the store's storefront. The emailed link carries the token in the
`#fragment`. The page sends it as `X-Affiliate-Token`, so it never appears in a URL, a server
log or a Referer. Only the SHA-256 is stored, and a new link voids the old one. The page
shows link, code, coupon, clicks, orders, balances, commissions (date, sale, commission,
status, source), payouts, adjustments and masked payout details. **No customer name, email,
address or order number** is in the response. The affiliate can set payout details.

## 7. API

Admin (`/api/v1`, tenant + store permission): `GET affiliates/overview`, `PUT affiliates/program`,
`POST affiliates/program/{activate,pause}`, `GET/POST affiliates`, `PUT affiliates/{a}`,
`POST affiliates/{a}/{approve,reject,pause,resume,portal-link}`, `PUT affiliates/{a}/payout-details`
(step-up), `POST affiliates/{a}/payouts` (step-up, idempotent), `GET/POST affiliates/{a}/adjustments`
(step-up, idempotent), `GET affiliates/commissions`, `POST affiliates/commissions/{c}/decide`,
`GET affiliates/payouts`, `POST affiliates/payouts/{p}/paid` (step-up, idempotent),
`POST affiliates/payouts/{p}/pay-to` (step-up), `POST affiliates/payouts/{p}/cancel`.

Storefront: `POST storefront/affiliates/apply` (throttle 3/10 min, honeypot, 202 with the same
answer for known addresses), `GET storefront/affiliate`, `PUT storefront/affiliate/payout-details`
(X-Affiliate-Token).

## 8. UI

- Admin: Marketing → Affiliates (`Pages/Marketing/Affiliates.tsx`) with a program bar and the
  tabs Overview, Affiliates (drawer per affiliate), Commissions (review with the reason shown),
  Payouts and Program settings. The private link is shown once, with Copy.
- Storefront: footer link "Become an affiliate" while applications are open; `AffiliateJoin`
  (how it works, what you earn, terms, application); `AffiliatePortal`. Both are translated to
  Urdu (58 new strings). The rule summary lines come from the server in English (known gap).

## 9. Not built / known limits

- Reseller program and platform package referrals (B58).
- Per-product or per-category rates, tiers, multi-level.
- Automatic payouts, tax on commissions, affiliate tax documents.
- An email to a declined applicant (deliberately not sent).
- Cross-device attribution: the referral lives on the shopper's device; a coupon covers other devices.
- Rule summary lines are not translated.
