# B31 — Security review (Admin UI, gap G6)

Scope: the admin screens, the display props they receive, and the small
API additions of B31. The security baseline of B29/B30 is unchanged.

## 1. What did not change

- Store Owner MFA is mandatory; a Store Owner without it reaches only
  Security and its own `/auth/*` endpoints. Checked for the new pages in
  `AdminUiTest` and in the browser.
- Platform staff MFA is mandatory. Store staff MFA stays optional.
- Step-up is the existing server mechanism (`RequireStepUp`,
  `POST /auth/step-up`). No second mechanism, no new endpoint.
- The emergency MFA reset is console-only. There is no HTTP endpoint and
  no screen for it; the Users page says so in its source and offers no
  such action.
- Accounts with MFA get no remember-me cookie.
- No route was removed from a middleware group; no policy was relaxed.

## 2. The screen is not the boundary

| Control | Enforced by |
|---|---|
| Which pages a role may use | Policies on every `/api/v1` call. The page routes only render a shell; a role without access sees the API's refusal |
| Platform pages | `can:super-admin.platform` on the page routes **and** `privileged.mfa` + `super_admin.platform` on the API |
| Package features and limits | `EntitlementService` in the controllers |
| Tenant | `TenantContext` and `BelongsToTenant`. No page sends a store id; the only one that names a store is the store switcher, which the server checks against the user's memberships |
| Validation | Form Requests and services. Client checks are convenience (empty field, amount format) |
| State changes | The server's state machines (orders, shipments, campaigns, content pages, domains, backups) |

Verified by calling the API directly as a role that lacks the
permission (browser check S3, S4; `AdminUiTest`): 403 in every case,
including a hand-made `PUT` to a product by a view-only role.

## 3. New data sent to the browser

`AdminShellProps` shares, for the signed-in user only: the permission
keys of the user's own role, whether the user is the Owner, the feature
flags and name of the store's package, the user's own active stores
(id, name), and the store currency. Nothing about other users or other
stores. A signed-out visitor gets empty values (tested).

New resource fields (staff-only resources, behind the existing
policies): numeric keys (`internal_id`), a product's brand and category
ids, an order's customer and addresses, a payment's and shipment's order.
The order's customer contact and addresses are personal data: they are
returned only to staff who may view orders (`orders.view`), as Module 09
§37 describes. `cost_price_minor` is still sent only with
`products.view_cost` (checked in the browser as a "staff" role).

## 4. Public ids in URLs

`HasPublicId::resolveRouteBinding` adds a second way to find a row; it
does not add a way around the tenant scope. The binding query is the
model's own, with its global scopes. Another store's public id is a 404
(tested for read and write). Finding a row is not authorization: each
controller still calls its policy.

## 5. Step-up on screen

- The API layer opens the dialog on `403 step_up_required` and repeats
  the same request once after `/auth/step-up` succeeds.
- The password goes to `/auth/step-up` only, never with the action
  (unit-tested).
- Cancel changes nothing and is not shown as an error.
- A wrong password is reported on its field; the server's rate limit on
  password confirmation applies as before.
- Verified in a real browser for a store restore request and a platform
  setting, with the 15-minute window made to look expired for the test
  session.

## 6. Secrets and one-time values

- MFA secret and recovery codes: the Security page of B29, unchanged.
- API key secrets and webhook signing secrets: shown once in a dialog
  when created, held in component state only, gone when it closes.
- Secret settings: the API sends `configured: true/false`, never the
  value; the editor has a "new value" field and no reveal.
- Backup artifacts: no link, no download, on either backups page.
- Nothing is written to `localStorage` except the sidebar's
  open/collapsed preference.

## 7. Error text

A 5xx is always shown as one fixed sentence; the server's text is
discarded (unit-tested with an SQL error string, and in the browser with
an injected 500). Messages of 4xx answers are the application's own
authored sentences.

## 8. Injection

- No `dangerouslySetInnerHTML` in the admin. Content page text, theme
  values and message templates are rendered as text; the server
  sanitizes what the storefront later renders (`ContentSanitizer`,
  `CustomCssSanitizer`, `ThemeConfigValidator`).
- Search terms are sent as query parameters; the new server filters
  escape `%` and `_` and bind values.
- Filter values in the URL are read back only into known filter keys.

## 9. Findings

1. **Display props can be stale.** A permission removed while a page is
   open still shows its menu entry until the next page visit. The API
   refuses the call; the page shows the refusal. Acceptable.
2. **Numeric keys are visible to staff** (`internal_id`). They were
   already visible in several resources. They reveal row counts, not
   data. Removing them needs the API contract change in G13.
3. **An order's customer address is now in the order resource.** Limited
   to `orders.view`. If a role should see orders without contact
   details, a separate permission is needed (not specified today).
4. **The browser checks used throw-away local accounts** and a helper
   that reads a test account's MFA secret from the local database. The
   helpers live outside the repository and were not committed.
