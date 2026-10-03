# B32 — Security review (customer management, gap G7)

Scope: the customer API and screens, email verification and guest order
linking, import/export, staff orders with a customer and payment,
package contents editing, platform settings history, theme preview. The
B29/B30 baseline (MFA, step-up, emergency reset console-only) is
unchanged.

## 1. Tenant isolation

| Path | Control | Test |
|---|---|---|
| Every customer, group, tag and note route | `BelongsToTenant` + route binding by public id: another store's row is 404 | `CustomerAdminTest::test_another_stores_customer_is_not_found_anywhere` |
| Staff order `customer` / `customer_id` | Looked up under the tenant (was a cross-tenant `exists`) | `AdminGapsTest::…another_stores_customer_or_a_blocked_one` |
| Import | Cache key holds store and staff member; another staff member's confirm is 410 | `CustomerImportExportTest::test_another_staff_member_cannot_confirm_my_import` |
| Email verification | Row bound to store and customer; a token used through another store does nothing | `CustomerStandingTest::…does_nothing_in_another` |
| Theme preview token | Names its store; another store or an edited token shows the published theme | `AdminGapsTest::test_a_theme_preview_link…` |
| Settings history/rollback | Store routes: store keys and revisions only; platform routes: platform only | `AdminGapsTest` (both directions) |

## 2. Authorization

- Four permissions: `customers.view`, `.manage`, `.export`, `.import`;
  checked in `CustomerPolicy` on every call. Privacy export/erase keep
  `privacy.manage`. The menu hides what a role may not open, but the API
  is the boundary (`test_permissions_view_manage_export_import_are_separate`).
- Step-up added: customer erasure, package contents, platform setting
  rollback (route inventory in `StepUpTest`).
- Package contents and platform settings are platform-only
  (`super_admin.platform` + platform MFA); store staff get 403.

## 3. Personal data

- Notes are staff-only: not in any customer API; the audit stores the
  note id, never the text.
- The activity timeline shows a whitelist of audit context (reason,
  group, tags, …) — no IP addresses or user agents.
- Export: streamed, never stored on the server, audited with the count
  and filters; cells that start with `= + - @` (and tab/CR) are prefixed
  so a spreadsheet does not run them.
- Import: the file is read once and not stored; the rows wait encrypted
  in the cache for at most 30 minutes; the audit records counts only.
- Erasure now also removes addresses, notes, tags, verification rows
  and the group; the export now includes addresses.
- Verification tokens: 64 random characters; only SHA-256 stored; 24 h;
  one use; the link is a secret variable, so the stored notification
  body shows `[hidden]`.

## 4. Abuse and account safety

- A blocked customer: sessions revoked at once; a token from before is
  refused by `customer.principal`; sign-in refused **after** the right
  password only (a wrong password gets the usual answer, so the status
  is not revealed); registration with the same address refused; checkout
  as a guest or through another record with the address refused; staff
  orders refused.
- Guest orders join an account only on a proven address (link or
  password reset), never by name.
- Staff cannot change a registered customer's email (account takeover
  path).
- Rate limits, each with its own key: list 120/min, export 5/10 min,
  import 10/10 min (preview and confirm separately), preview link 20/min,
  send confirmation 3/10 min (plus 1/min in the service), verify 10/min.

## 5. Theme preview

Short-lived (30 min), tenant-scoped, issued only to staff with
`theme.view` and audited; encrypted and authenticated (cannot be forged
or edited); `noindex, nofollow`; cookie limited to the store's path,
HttpOnly, SameSite=Lax; preview pages are never cached. Anyone holding
an unexpired link sees the draft theme. The draft contains no personal
data, only colours, sections and CSS.

## 6. Residual risks

1. Duplicates remain until staff merge them (never automatic).
2. The verification email was not observed in a real inbox (mail driver
   `log`).

## 7. Follow-up (2026-10-03): package gate and customer merge

**Package gate.** `customers.advanced` is checked by
`EntitlementService` in the controllers before any change to groups or
tags, import, export and merge (403 `feature_not_entitled`). Hiding the
buttons on Basic is convenience only; `CustomerMergeAndPackageTest`
calls the API directly for all eight actions and checks nothing changed.

**Merge.**

| Risk | Control |
|---|---|
| Cross-tenant merge | Both records resolved under the tenant (another store's source is 404, its target 422); every write also filters by `store_id`; the service refuses different stores as a last line |
| Account takeover through a merge | A source with its own account is refused (its sign-in would move nowhere); two accounts are refused; the target keeps its password and email |
| A block lost by merging | Blocked records are refused on either side |
| Consent widened | The target's marketing consent is kept, never the source's |
| Mistaken merge | Explicit: chosen target, typed target email, reason, step-up; audited on both records; `customer_merges` keeps who/when/what (counts, no personal data) |
| Races | Both rows locked (lower id first) and re-checked in the transaction; unique `source_customer_id` |
| Leftover access | The source loses its tokens, email-verification and password-reset rows; a merged record can no longer be changed or restored |

Tests: `CustomerMergeAndPackageTest` (5), `StepUpTest` route list,
Vitest (3). Browser: Basic refusal in UI and API, Business merge.
