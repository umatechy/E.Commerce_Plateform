# B57 security review — store affiliate program

Scope: `app/Domain/Affiliates`, the storefront referral, checkout attribution, the
affiliate's private page and Marketing → Affiliates.

| Threat | Control | Test |
| --- | --- | --- |
| Forged or edited referral (earn on others' orders) | Token is HMAC-SHA256 over store, affiliate and issue time with the app key, compared in constant time; store, window and affiliate status are checked again at checkout | `test_a_referral_link_brings_a_signed_token…` (tampered, other store, expired) |
| Token of one store used in another | Store id is inside the signed payload | same test |
| Click flooding to inflate numbers | Counted once per affiliate, day and network; the IP is only a cache key, never stored | same test (one click for two visits) |
| Self-referral | Same customer account, email or phone → `review` or `rejected` (owner's choice); owner sees why | `test_an_order_by_the_affiliate_themself…` |
| Commission on cancelled or refunded orders | Hourly follow-up rejects, reverses proportionally, and claws back after payment via adjustment; idempotent | lifecycle and payout tests |
| Double payout | One open payout per affiliate, row locks, idempotency keys on create and paid, `payout_not_pending` | payout test |
| Affiliate sees customer data (§30, §76) | Portal response has no names, emails, addresses or order numbers; test asserts the shopper email is absent | `test_applying_approval_and_the_private_portal…` |
| Portal link leak | Token only in `#fragment` and `X-Affiliate-Token` header; only SHA-256 stored; rotation voids the old; noindex page | portal test (old link 404 after rotation); browser check (no token in any request URL) |
| Payout account exposure | Encrypted at rest; masked everywhere; full account only for `affiliates.payouts` after step-up, each look audited | payout test (2 audited reveals) |
| Unauthorized staff | `affiliates.view/manage/payouts` via AffiliatePolicy; payouts, details and adjustments also step-up | `test_staff_see_only_what_their_role_allows…`; StepUpTest |
| Cross-tenant access | Tenant-scoped models and route bindings; another store's affiliate is 404 | isolation test |
| Application spam, address probing | Throttle 3/10 min per IP, honeypot, same 202 answer for known addresses | application test |
| Package bypass | Starting needs `affiliate.programs`; attribution checks the entitlement on every order | `test_a_store_without_the_package_feature…` |
| Rule changes rewriting history | Versions are immutable; commissions keep a snapshot | versioning test |
| Checkout broken by affiliate errors | Attribution runs after the order in try/catch with `report()` | design; checkout tests unchanged |
| Money rounding | Integer minor units, basis points, `intdiv`, cap ≤ base | versioning test |

Audit events: program created/rules_changed/activated/paused, affiliate added/applied/approved/
rejected/paused/resumed/updated/portal_link, payout details set and revealed, commission decided,
payout created/paid/cancelled, adjustment created.

Residual risks:

- An affiliate who owns many devices or networks can still visit their own link; the
  self-referral signals only catch matching identity data. The owner reviews and the hold
  period gives returns time.
- Payouts are recorded, not verified: the reference is what the owner types.
- Referral tokens in localStorage can be read by scripts on the storefront origin. They are
  bound to one store and affiliate and give no access, so this is accepted.
