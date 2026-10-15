============================================================
PHASE B55 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B55 — The store's installable app (gap G16):
- SRS PWA-001 to PWA-005.
- Module 25 (§4–29, §51–59, §63–69, §72, §76, §83–84, §87–89).

Owner request (2026-10-15): "ye kam complete krny k bad agla kam start kr
dena. shaid b55 ya jo bhi aap tajweez kro". Proposed: G16 PWA, the next
module in the gap matrix's recommended order (G17 animation was done in
B36; G18 AI waits for the OpenAI key).

Starting point:
- v1.1 at b325848 (B54, CI green).
- Responsive storefront only.
- No manifest, service worker, offline handling or install experience.
- The `pwa.enabled` entitlement already existed in all three packages.

Implementation Summary:
1. Per-store manifest at {store}/manifest.webmanifest.
   - name, short name, colours (settings, else the theme);
   - icons 192 and 512;
   - start_url, scope and shortcuts inside the store's own address;
   - the store's language;
   - 404 when the app is not available.
2. Service worker at {store}/sw.js, scoped to the store.
   - Cache names carry the store and the version.
   - Never cached: API, Inertia data and private pages.
   - Public pages: network first, saved copy offline.
   - Assets and the store's images: cache first.
   - The worker waits for the shopper's "Refresh".
   - It is answered in every shop state. When the app is off or the shop
     is not open, it retires: it clears its caches and unregisters.
3. A branded offline page, in English or Urdu.
4. Storefront: offline notice; links load through the worker while
   offline; Place order disabled offline; cart and checkout reloaded on
   reconnect; install offer after three page views (30-day dismissal);
   iPhone guidance; update notice; phone tab bar (installed app only by
   default); sign-out clears saved pages.
5. Admin, Storefront → Mobile app (theme.view and theme.manage):
   - install checks;
   - settings and preview;
   - a square app icon (512 px or more; 192 and 512 PNGs made from it);
   - 30-day counters.
   - `pwa.*` settings are changed only here.
6. Push foundation:
   - encrypted, store-scoped subscriptions for signed-in shoppers;
   - `php artisan pwa:vapid-keys`.
   - Sending is not built, and shoppers are not asked yet.
7. Privacy-safe counters: daily totals per store, no device identity.
8. Help: "Your shop as a phone app" (store teams) and "Add the shop to
   your phone" (shoppers, English and Urdu); release notes 1.1 updated.

Docs:
- docs/architecture/b55-pwa.md
- docs/security/b55-security-review.md
- gap matrix: Module 25 row, PWA-001/003/004/005, G16.

Found and fixed on the way:
- A closed shop answered sw.js with its 503 page, so the old worker
  stayed and kept showing saved pages. sw.js is now answered in every
  shop state, with a retiring worker.
- Turning the app off: the page unregistered the worker before it could
  clear its caches. The page now also clears that store's caches (by the
  store's own cache prefix only).
- `pwa:vapid-keys` failed on Windows PHP: OpenSSL needs its bundled
  config. It now falls back to it.
- Emptying an app setting failed: settings do not take an empty string.
  It now goes back to the default.
- Vitest page tests timed out under load: one ran 2–3 s alone and over
  5 s with the PHP suite. The test timeout is now 15 s; this was the
  one-off flake seen in B54.

Decisions to confirm:
- The app is on by default for every store whose package includes
  `pwa.enabled` (all three packages today). Installing still needs an
  icon and https.
- Phone bottom navigation shows in the installed app only by default.
- Install offer after the third page view, at most once a month.
- Push: no shopper prompt until delivery exists. VAPID keys are a
  platform input (set at deployment).

Tests:
- New: StoreAppTest (12 tests, 116 assertions); pwa.test.tsx (2).
- PHP suite: 1278 passed.
- Vitest: 253 passed.
- PHPStan, ESLint and TypeScript are clean. Production build OK.
- help:check is valid (57 articles).

Browser check (real Chromium, B46 Tax Store owner with MFA; shopper as a
phone at 390 px): 6 of 6 passed, no 5xx.
1. Mobile app page: icon upload, settings, checks and preview. The https
   check stays open locally, because the server is http.
2. Manifest: scope and start inside the store, short name "B46 Shop",
   icons 192 and 512 load.
3. The service worker registers and controls /shop/demo-store-aao1ug/.
4. The phone tab bar shows 5 tabs.
5. Offline: the products page opens from the saved copy with the offline
   notice; /account/orders shows the offline page. The caches are named
   per store and version.
6. Turning the app off unregisters the worker, clears the caches and
   makes the manifest 404. The app was turned back on with "installed
   app only" navigation.

Local data note: the B46 Tax Store has an app icon and the short name
"B46 Shop".

Not built (b55-pwa.md §8):
- push delivery and the shopper's push prompt;
- offline wishlist and offline cart editing;
- web-vitals monitoring;
- configuration drafts;
- PWA health in Store health;
- iPhone splash images.

CI: 1e45b80 failed in backend (StoreAppTest rendered the 503 page with Vite, which CI does not build); fixed in 34e52f6 — run success (backend, frontend, security, e2e), verified 2026-10-15.

Next (on the owner's word): B56 — G19 Marketplace (Module 27), the next
module in the matrix order that needs no owner input; G18 AI waits for the
OpenAI key. A smaller alternative: G22 feature flags.
