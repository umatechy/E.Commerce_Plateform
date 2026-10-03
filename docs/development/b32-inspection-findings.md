# B32 — Inspection findings (gap G7 and the B31 admin gaps)

Read before building: Module 10 (all sections), Module 04 §8 and §63,
Module 09 §70, Module 14 §17–20, Module 17 §19, Module 32, and the open
items of `b31-inspection-findings.md`.

## 1. Defects found and fixed

| # | Defect | Effect before | Fix |
|---|---|---|---|
| 1 | Store settings `history` and `rollback` accepted any key and any revision id | A store's staff could read the history of platform settings (alert recipient addresses) and roll a platform setting back | Store routes accept store keys only; `ConfigService::rollbackTo()` refuses a revision of another scope. Tested both ways |
| 2 | A staff order's `customer_id` was checked with `exists`, which has no tenant | An order could be attached to another store's customer | The customer is looked up under the tenant (public id or key, not erased, active) |
| 3 | Privacy erasure left the address book; the privacy export left it out | Personal data stayed after "erase"; the export was incomplete | Erasure removes addresses, notes, tags, verification rows, group and status reason; the export includes addresses |
| 4 | The staff order response did not load the customer | The new order answered `customer: null` | The response loads items and customer |
| 5 | A blocked customer could register again with the same address, or check out signed in to an older account with it | The block was easy to get round | Registration refuses a blocked address; checkout and staff orders check the address too |
| 6 | Found in the browser check: the new rate limits had no key prefix, so they shared one counter per user with every other unnamed limit | An export after a few list searches answered 429 | Every limit added in B32 has its own prefix; regression test |
| 7 | Erasing a customer's data did not ask for the password again | An irreversible action without step-up | `POST /customers/{id}/erase` has `step_up` |

## 2. Decisions taken in this phase (reported, within the specs)

1. **One group per customer.** Module 10 §25 describes groups as a
   classification; tags (§24) carry everything else.
2. **Staff cannot change a registered customer's email or phone**
   (§67: identity changes need the customer's verification). They can
   correct both for a customer without an account.
3. **Merge only on request.** Duplicates (§57) are a warning on the
   detail page (same email or same phone); import skips an existing
   email. The merge itself (§56) was built in the follow-up (§6).
4. **Guests are not separate customer records.** Guest orders join an
   account only after the address is proven (email link or password
   reset), never by name (§9).
5. **Blocked versus archived** (§29–31, §58): both stop sign-in and
   marketing; blocked also stops new orders, including as a guest and by
   staff. A blocked customer cannot be archived.
6. **Notes**: the audit records that a note was added or deleted, never
   its text.
7. **Staff order payment** (the open question of B31): staff choose cash
   on delivery or bank transfer when they create the order, as the
   package allows; the money is then recorded on the order with the
   existing manual confirmation. Without a method the order still cannot
   ship (unchanged B9 rule). No online payment is taken by staff.

## 3. Owner decision (asked in B32, answered 2026-10-03)

Question: should customer groups, tags, import or export be limited by
package? Module 04 §8 maps no customer feature; Module 10 §87 proposes
one.

Answer: the §87 mapping, and no "Maximum Customers" limit for now.

| Package | Customers |
|---|---|
| Basic | List, search, filters, detail, add a customer, notes, block/archive, activity, privacy export and erase, email verification, guest order linking |
| Business, Premium | All of Basic, plus groups, tags, CSV import, CSV export and merge (`customers.advanced`) |

What a store already has is never deleted (Module 04 downgrade rule):
on Basic, existing groups and tags stay visible and usable as filters;
only changes are refused (403 `feature_not_entitled`).

## 4. Closed from the B31 list

| B31 item | Now |
|---|---|
| Customers: no list or detail (G7) | List, detail, groups and tags, import, export, privacy screens |
| A staff order has no payment | Payment method on the order form; payment record created with the order |
| Promotion targets show "Product #n" | Names from the API; a deleted target says so |
| Package entitlements read-only | Contents editor with reason, step-up and history |
| Platform settings have no history | History and rollback |
| Theme has no draft preview | Preview link (30 min) |
| Security, Support, platform Backups/Support without breadcrumbs | Breadcrumbs |

Still open from B31: variants without their own price cannot be ordered;
no edit/delete for attributes, shipping zones and methods, segments; no
delete for warehouses and promotions; dashboard sums ignore currency.

## 5. Not executed — environment limitation

- Email delivery: the mail driver is `log`. The confirmation link was
  checked by tests (token stored hashed, one use, store-bound) and the
  wrong-link page in the browser, not from an inbox.
- Campaign sending to an inbox: same reason. The skip of blocked and
  archived customers is covered by `CampaignExecutionTest`.
- Saving package contents in the browser was not done on purpose (the
  local packages are shared by every local store); it is covered by
  `AdminGapsTest`. Opening, counting changes and the reason rule were
  checked in the browser.
- Screen readers and browsers other than Chromium.

## 6. Follow-up (2026-10-03): package mapping and customer merge

**Package mapping.** Feature key `customers.advanced` (Module 04 §7
naming). PackageSeeder: Basic false, Business and Premium true;
migration `2028_05_01_000003` adds the row to the three standard
packages of an existing database without overwriting a value platform
staff already set. Enforced in the API for: creating, renaming and
deleting groups and tags; giving a customer a group or tags; import;
export; merge. The screens hide those actions on Basic and say why.

**Customer merge (§56).** `POST /customers/{id}/merge` — `customers.manage`,
`customers.advanced`, step-up, the target's email typed, a reason. The
source (the duplicate) moves into the target (who stays):

| Moves | Rule |
|---|---|
| Orders, payments, promotion usages | All (per-customer coupon limits keep counting the same person) |
| Addresses | All; the target's default stays the default |
| Tags, group | Union of tags; the target's group, or the source's if none |
| Wishlist | Items the target does not have yet |
| Notes, notifications, campaign history, support requests | All (campaign rows the target already has for a campaign stay) |
| Identity, password, email confirmation, marketing consent | **Not moved**: the target keeps its own; consent is never widened |

The source is archived, marked `merged_into_customer_id`, loses its
sessions and verification/reset tokens, and can no longer be changed.
A `customer_merges` row (counts only) and two audit entries record it.

Refused: the same record; another store's record (404); an erased or
already merged record; a blocked one (unblock first, so a block is
never lost); a target that is not active; a source with its own account
(its sign-in would be lost — merge the other way) and two accounts
(not merged by staff). Concurrency: both rows locked, lower id first,
and re-checked; a source can be merged once (unique index).

Not moved on purpose: carts (an open cart belongs to a session of the
source, which ends).
