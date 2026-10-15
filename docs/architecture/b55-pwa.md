# B55 — The store's installable app (gap G16, Module 25)

Sources:
- SRS PWA-001 to PWA-005.
- Module 25: §4–28 (principle, manifest, service worker, caching, offline, cart, connectivity, updates, versioning, tenant isolation, domains), §29 (mobile navigation), §51–59 (push, install, icons, shortcuts), §63–66 (storage and offline security), §68–69 (analytics), §72 (offline page), §76 (entitlements), §83–84 (suspension and deletion), §87–89 (tests).

## 1. What a store gets

| Piece | Where | Notes |
|---|---|---|
| Manifest | `{store}/manifest.webmanifest` | Built for the resolved store only. Name and short name come from the store's settings, or else the store name. Colours come from the settings, or else the published theme's primary and background. Icons 192 and 512 PNG. `start_url`, `scope` and shortcuts (shop, cart, your orders, help) stay inside the store's own address. Language and direction follow the store's default language. 404 when the app is not available. |
| Service worker | `{store}/sw.js` | Generated from `resources/pwa/sw.js` with the store's config. It is served whatever the shop's state (route default `storefront_always`), with `Cache-Control: no-store`. |
| Offline page | `{store}/offline` | Branded plain HTML (name, icon, colours, store language), with "Try again" and a reload when the connection returns. |
| Head tags | `app.blade.php` | `rel=manifest`, `theme-color`, `apple-touch-icon`, web-app-capable metas, only when the app is available. |
| Page prop | `storefront.pwa` | enabled, URLs, scope, cache prefix, theme colour, icon, install offer, phone navigation, push key. Worked out per request, not in the cached shell. |

`{store}` is `/shop/{slug}` on the platform host, or the root of a store's verified domain; the worker's scope is that address, so one store's worker never handles another's pages (§26–28).

**Available** means three things: the package includes `pwa.enabled` (all three packages today), the store has the app on, and the shop is open. When it is not, the manifest is 404, the head tags are gone, the page unregisters an old worker and clears that store's caches, and `sw.js` answers with a **retired** worker that clears its caches and unregisters itself (§83). A deleted store's subscriptions and counters go with its data (StoreDataMap PURGE, §84).

## 2. Cache policy (§11–14, §25–26, §65)

| Request | Strategy | Cache |
|---|---|---|
| `/api/*`, Inertia page data (`X-Inertia`), anything but GET, other origins | Network only, never cached | — |
| Built assets `/build/assets/*` (hashed names) | Cache first | `ut-{store}-assets-{version}`, at most 300 entries |
| The store's own images `/storage/stores/{store}/` | Cache first | `ut-{store}-images-{version}`, at most 60 entries |
| Public page navigations | Network first; when offline, the saved copy, else the offline page | `ut-{store}-pages-{version}`, at most 30 pages + the offline page |
| Private pages (`/account`, `/checkout`, `/cart`, `/support`, `/returns`), links with a token or preview, pages served with `X-Ut-Private` | Network only; offline: the offline page | never kept |

- `{version}` is the build's manifest hash plus the store's `pwa.version`, which goes up on every settings or icon change (§25, §82). Old caches of the store are deleted when the new worker activates.
- `X-Ut-Private` is set by ResolveStorefrontStore on every storefront page seen by a signed-in staff member, because the page data carries their account.
- When a shopper signs out, the page asks the worker to forget its saved pages (§66).

## 3. Offline behaviour (§15–22; PWA-004/005)

- A notice says the shopper is offline and that saved pages may be out of date. Prices and stock are checked when they order.
- Connectivity is the browser's state plus a check that the server answers (`/api/v1/public/health`). When the browser is back online, the server is asked again; while it does not answer, this repeats every 15 seconds.
- While offline, an Inertia link does a full page load, so the worker can serve the saved copy or the offline page instead of failing.
- Checkout: **Place order** is disabled offline with "Your cart is kept; connect to the internet to place the order." Nothing is queued.
- Back online, the cart and checkout pages reload their data from the server, which recomputes everything (§18, §22).
- Offline never creates an order, a payment or a stock change: those are API calls, and the API is never cached or replayed.

## 4. Install, update, navigation (§23–24, §29, §55–59)

- **Install offer**: the browser's install event is held, then offered in a small dismissible card after the third page view. A dismissal is remembered for 30 days in the browser. iPhones get "tap Share, then Add to Home Screen". The store can turn the offer off.
- **Update**: a new worker waits. The page shows "A new version of this store is ready — Refresh". Refresh switches to it and reloads once, so cached assets never mix versions.
- **Phone navigation**: Home, Shop, Search, Cart and Account, fixed at the bottom on small screens. The store chooses when it shows: in the installed app only (the default), on every phone, or never. It respects the safe-area inset.

## 5. Admin: Storefront → Mobile app

Needs `theme.view` to read and `theme.manage` to change; the menu entry follows the `pwa.enabled` feature. The page has:
- checks: package, turned on, shop open, icon, https address;
- settings: on/off, app name, name under the icon (12), top bar colour, opening screen colour, how it opens (standalone, minimal-ui, browser), phone navigation, install offer;
- the app icon: a square JPG/PNG of at least 512 px. It is re-encoded as the store's own media (`app_icon` purpose), and 192 and 512 PNGs are made from it;
- a preview of the home-screen icon and the opening screen;
- 30-day counters.

`pwa.*` settings can be changed only through this page; the generic settings API answers `managed_on_app_page`.

## 6. Push foundation (§51–54)

- `pwa_push_subscriptions`:
  - endpoint and keys encrypted;
  - found by a SHA-256 of the endpoint;
  - store-scoped (BelongsToTenant);
  - linked to the signed-in customer resolved by the server;
  - with a coarse browser label ("Chrome on Android"), no device identifier.
- `POST/DELETE /api/v1/storefront/app/push` need a signed-in customer, the platform's VAPID keys (`config/pwa.php`, environment only) and the store's `pwa.push` setting.
- `php artisan pwa:vapid-keys` prints a P-256 key pair for the operator; nothing is written.
- **Not built:** sending (a Module 21 web-push channel). Until it exists, shoppers are not asked: there is no button. The admin page says notifications come later. The setting cannot be turned on without the keys.

## 7. Counters (§68–69)

`pwa_daily_stats` (store, day, event, count). The events are `installed`, `launched` (from the home screen, once per session), `offline_view`, and push on/off, which the server counts when a subscription changes. Nothing identifies a device or a person. Unknown events are refused.

## 8. Not built / limits

- Push delivery, and asking shoppers for push (see §6).
- Offline wishlist and offline cart editing (the cart is kept on the server; checkout needs the network).
- Web-vitals monitoring and a performance budget (§45–46).
- Configuration drafts and published versions (§80–81): settings changes are versioned through setting revisions and the cache version.
- PWA health signals in Store health (§86): the Mobile app page shows the install checks.
- iPhone splash images (Safari makes its own from the icon and the background colour).
