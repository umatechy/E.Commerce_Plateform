# Phase B24 — Security Review (Module 05)

All items below were verified by executed tests on MySQL 8, where a test
is named (`tests/Feature/Storefront/`, `tests/Unit/HtmlSanitizerTest.php`).
The storefront is the platform's first page open to everyone, so each
item below assumes a hostile visitor.

| # | Threat | Control | Status |
|---|---|---|---|
| 1 | Stored XSS through rich text (content pages, product descriptions) | Parser-based `HtmlSanitizer`: element and attribute allow-list; schemes checked after entity decoding and control-character removal; `style`, `on*`, `data:`, protocol-relative and insecure image URLs removed. Applied when content is saved (`ContentSanitizer`) and again when it is rendered | Tested: 14 vectors, including both B13 bypasses |
| 2 | XSS through JSON-LD (a product named `</script><script>…`) | `json_encode(… JSON_HEX_TAG …)` in the root view; all other head values are Blade-escaped | Tested (`test_product_pages_embed_safe_structured_data`) |
| 3 | XSS through theme data | Tokens are validated hex, radius and font values (B15), applied as CSS variables. Custom CSS was sanitized when it was saved and is inserted as `<style>` text by React, never as markup | Reviewed |
| 4 | Cross-tenant data | Every storefront query runs under the visited store's tenant scope. Slugs of another store are 404s. A customer token only works on its own store (403) | Tested |
| 5 | A signed-in merchant seeing their own store instead of the visited one | `ResolveStorefrontStore` re-resolves the tenant from the path or domain for storefront routes only | Tested (staff preview, custom domain) |
| 6 | Publishing internals | Public ids and slugs only; no `store_id`, cost price, exact stock or warehouse data | Tested (`test_product_detail_publishes_options_and_availability_but_no_secrets`) |
| 7 | Closed stores leaking | Live gate on every request, not cached: not launched, suspended, lapsed subscription and maintenance all answer 503 with no catalog data | Tested |
| 8 | Launching someone else's store / unauthorized launch | `storefront.manage` or Owner; the store comes from the resolved tenant only; launch runs under a row lock and is audited | Tested |
| 9 | Malicious uploads (polyglots, HTML with an image header, huge images) | MIME and size validation, then a GD decode and re-encode; files that do not decode are refused; dimensions are capped; files are stored under per-store paths with random names; the file is removed if the database write fails | Tested |
| 10 | Privacy leak through image metadata (GPS in EXIF) | Re-encoding drops all metadata | Tested (`test_an_upload_is_reencoded_without_its_metadata…`) |
| 11 | Filter injection / wildcard abuse | Validated filters; LIKE wildcards escaped; per-page ≤ 48; page ≤ 1000; invalid web query values dropped | Tested |
| 12 | Scraping / request floods | Public API throttled at 120 requests per minute per client; responses are served from the per-store cache | Reviewed |
| 13 | CSRF on storefront writes | Same-origin writes are Sanctum-stateful and send `X-XSRF-TOKEN`; cross-domain storefronts are stateless and use the cart's guest token | Verified end to end in headless Chromium (a 419 was seen when `SESSION_DOMAIN` did not match the host) |
| 14 | Double orders (double click, retry) | One idempotency key per checkout visit (the existing `CheckoutService` guarantee) | Reviewed |
| 15 | Duplicate content / index pollution | Filtered, sorted, paged and search pages are `noindex, follow`; cart and checkout are `noindex, nofollow`; canonical URLs come from the SEO resolver | Tested |

## Residual Risks

- There is no Content-Security-Policy on storefront pages yet. The
  sanitizer is the XSS control; a nonce-based CSP is the planned second
  layer (see B22).
- Stock shown on listings can be up to one cache TTL old when stock is
  changed by bulk query-builder updates. Cart and checkout re-check live.
- The storefront API rate limit is per client IP; a CDN in front of the
  storefront should forward the real client IP.
