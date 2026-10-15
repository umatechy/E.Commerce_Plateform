# B55 security review — the store's installable app

| Area | Risk | Control | Test |
|---|---|---|---|
| Cross-store caches | Store A's pages, branding or images shown in store B's app (§26) | Worker scope is the store's own address; cache names carry the store's public id and version; only the store's own media prefix is cached; on a shared host every store has its own scope | worker isolation test |
| Private data on the device | Account, cart, checkout or a staff session kept offline on a shared phone (§14, §66) | Private paths and token/preview links never cached; `X-Ut-Private` on pages seen by signed-in staff; API and Inertia data never cached; sign-out clears saved pages | worker config test, private header test |
| Offline transactions | Orders, payments or stock changes made from stale data (PWA-004/005) | Place order disabled offline; nothing queued; every business action is an API call, never cached or replayed; cart and checkout reload from the server on reconnect | browser check, checkout code |
| Stale shop after closure | A closed or suspended shop keeps working offline (§83) | sw.js answered in every shop state; a retired worker clears its caches and unregisters; the page also clears that store's caches | retire test, browser check |
| Manifest abuse | Cross-store icons, external URLs, open redirects, markup in names (§9) | Icons only from the store's own `app_icon` media; start_url, scope and shortcuts built from the resolved base path; names stripped of markup and control characters, validated without `<` `>` | manifest test, settings test |
| Icon uploads | Script or oversized files | Square JPG/PNG only, re-encoded by the B37 pipeline (metadata dropped), 512–4000 px, 5 MB | icon test |
| Push subscriptions | Attaching a subscription to another customer or store; leaking endpoints (§54) | Customer and store come from the authenticated session; endpoint and keys encrypted; https endpoints only; throttled; nothing is sent yet | push test |
| VAPID keys | Committed or exposed | Read from the environment only; the command prints them once and writes nothing | vapid command test |
| Counters | Device tracking (§69) | Day totals of fixed events per store; no IP, device or user stored; throttled | counter test |
| Service worker script | Injection into the worker | Config is JSON-encoded with `JSON_HEX_TAG` from server values; the template is static | worker test |

No new dependency. New secrets: `PWA_VAPID_PUBLIC_KEY`, `PWA_VAPID_PRIVATE_KEY` and `PWA_VAPID_SUBJECT`, set by the operator when push delivery is built.
