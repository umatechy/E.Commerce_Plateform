# B40 security review — product import and export

| Area | Risk | Control | Test |
|---|---|---|---|
| Tenant isolation | Import updates another store's product; export leaks it | Every lookup goes through the tenant scope (another store's id is "not found"); export query is tenant-scoped | `test_permissions_and_store_boundaries_are_kept`, export test |
| Preview hijack | Another user or store confirms someone's preview | Cache key binds store id and user id; one-time `Cache::pull` | same test (410) |
| Data at rest | Uploaded rows linger | File never stored; checked rows encrypted (`Crypt`), 30 min TTL | — |
| Permissions | Viewer imports; editor creates; cost prices leak | `ProductPolicy::import`; per-row `create` / `update`; cost column only with `products.view_cost` (in and out) | permissions and export tests |
| Package limit bypass | Mass creation past `max_products` | Checked before each new counting product; usage recorded after | `test_rows_past_the_package_limit_are_reported_not_created` |
| CSV / formula injection | Exported cell runs as a formula in a spreadsheet | Cells starting with = + - @ tab CR get a leading `'` | export test |
| SSRF | Import fetches attacker-chosen URLs | `image_urls` ignored on import; no outgoing requests | — |
| Mass assignment | A column writes an arbitrary field (e.g. `store_id`) | Fixed column list; unknown columns refused | bad header test |
| Resource abuse | Huge files | 4 MB, 2,000 rows, throttles (10 / 10 min for import, confirm, export) | — |
| Partial corruption | One bad row breaks the import | Row-level transactions; skipped rows reported | first import test |
| Audit | Untraceable bulk catalog changes | `products.imported` (counts), `products.exported` (rows, filters, cost flag) | import test |

No new secrets, providers or outgoing network calls. Authentication, MFA and
step-up unchanged.
