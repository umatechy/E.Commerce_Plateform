# B54 — Help center and documentation (gap G12)

Sources:
- SRS SUP-001, SUP-005, SUP-006, SUP-007.
- Module 34: §4–15 (documentation types, information architecture, knowledge base, metadata, versioning, lifecycle, ownership, search, contextual help, onboarding, role-aware help), §24–25 (troubleshooting, error codes), §28–35 (feedback, analytics, release notes, changelog, developer documentation), §43 (customer help center), §47–48 (content security, examples), §56–62 (quality, CI, documentation as code, deployment, localization, SEO, search security), §73.

Support tickets (§16–23) were built in B26 and are unchanged.

## 1. Where help content lives

| Source | What | How it reaches readers |
|---|---|---|
| `resources/help/articles/<category>/<key>.md` | Built-in articles in English (the canonical language, §60.1) | Reviewed in pull requests (§58); loaded by `php artisan help:sync` after every deployment and by `HelpContentSeeder` |
| `resources/help/articles/<category>/<key>.ur.md` | Urdu translations (title, summary, keywords, body; the rest comes from the English article) | Same |
| `resources/help/releases/<version>.md` | Release notes, sectioned like a changelog (§32) | Loaded as drafts; Umar Techy publishes them in the Super Admin |
| Super Admin → Help center | Articles written by staff with the `help` capability | Draft → review by a second person → published |

Each file starts with a front matter block (`name: value`, lists comma-separated). Fields follow Module 34 §7: key (stable id, §72.1), title, summary, category, type, audience, module (owner module, §10), tags, keywords, error codes, contexts (admin pages for page help), related, applies_from/applies_until (product version, §8), owner, review date. Security classification is derived from the audience.

`config/help.php` holds the product version (`1.1`). It changes with the code, not per environment.

Content at release: 47 English articles (store owners and staff 35, of which 4 troubleshooting; developers 3; Umar Techy internal 2; shoppers 7) and 7 Urdu translations of the shopper articles; one release note (1.1, draft). Every article describes behaviour verified in the code; things not built (card payments, courier booking without a connected courier, store-run status page) are said to be not available.

## 2. Data model

- `help_articles`: one row per key and language; its columns are the live text. Status: draft, in_review, published, deprecated (with `replaced_by`), archived. `revision` is the published version; `translation_source_revision` the English version a translation was made from.
- `help_article_revisions`: every version (draft, in_review, published, superseded, rejected) with author, reviewer and notes (§72.4–72.7).
- `help_feedback`: helpful or not, reason, comment (emails and phone numbers removed), the revision read, surface, and the store (purged with the store's data — StoreDataMap PURGE). No reader identity.
- `help_searches`: query (digits of five or more and emails masked), surface, language, result count. No user, store or IP (§29).
- `help_release_notes`: version, title, summary, sections, status, `released_on` (set when published, never typed or guessed).
- `users.help_seen_release`: the "What's new" mark.

## 3. Who sees what (§15, §62)

`HelpAudiencePolicy` (extends the existing `BaseTenantPolicy`; no second authorization model, §73.3):

| Reader | Audiences |
|---|---|
| Shopper on a storefront | customer |
| Store staff | merchant, customer |
| Store Owner | + owner |
| Owner or staff with `developer_platform.view` | + developer |
| Umar Techy staff | all, including platform (internal runbooks) |

Every list, search, category, page help, article and feedback call is filtered by these audiences before matching or counting. An article the reader may not open answers 404, so not even its title or existence leaks (§62.5). The checker refuses a link or "related" entry to an article some readers of the source could not open.

## 4. Reading

- **Search** (§11): scored in PHP over the reader's articles: exact title, title phrase, error code, tag/keyword, summary, then per word (word-start match; words of three letters or fewer must match whole, so "cod" is not "code"). Every word must appear unless the phrase matched. Deprecated articles ×0.3, other versions ×0.5.
- **Page help** (§12): the top bar's **Help** button opens a panel with the articles whose `contexts` match the current admin path (`*` = one segment), search, and links to the help center, What's new and Umar Techy support.
- **Onboarding** (§13–14): the B44 setup checklist on the dashboard gets a **How?** link per open step.
- **Admin help center**: `/help` (search, topics, start here, most helpful, latest release), `/help/articles/{key}`, `/help/whats-new`.
- **Storefront help center** (§43): `/help` and `/help/{key}` on every store (path and custom domain), shopper articles only, in the visitor's language where translated; out-of-date translations are marked with a link to English (§60.3); the store's published policy pages and contact form are linked; a footer link on every page. Search result pages are `noindex`.
- **Rendering**: CommonMark with raw HTML escaped and unsafe links dropped, then the allow-list `HtmlSanitizer` (now accepting caller-chosen extra elements: code, pre, tables). `help:` links resolve to the reader's help center; `store:` links to the reader's storefront (plain text where there is none).
- **Help stays open** for closed, suspended and archived stores (`EnsureStoreOperational` lets `/help/*` through): it is platform content. It stays behind the owner MFA requirement like the rest of the admin.

## 5. Writing and publishing (§6.2–6.4, §9, §47.1)

`HelpArticleWorkflow`: create (draft) → save draft → submit (checker must pass) → approve by **someone other than the author** (checker again) or send back with a note → deprecate (needs a replacement readers can open, or a reason) → archive → publish again. A published article stays live while its next version is a draft. Every step is in the platform audit log. Staff need the new `help` platform capability (super admin, platform admin, support admin); every platform role can read the Super Admin pages.

`HelpBundleSync` (`help:sync`): refuses to load anything when any file has a problem; publishes built-in articles as "Repository review"; does not overwrite an article changed in the Super Admin (it marks "newer built-in text", which staff can load as a draft); archives built-in articles removed from the repository; loads release notes as drafts and updates them later.

## 6. Checks (§57, §73.14–73.17)

`php artisan help:check` (CI, backend job) and the editor's **Preview and check** run `HelpContentChecker`:
- required metadata and known values; category fits the audience; version not newer than the product;
- links: `help:` articles exist and are readable by all the source's readers; admin paths and `store:` paths are real routes; only https external links;
- developer API paths exist in the OpenAPI description; webhook events exist in the catalog;
- troubleshooting articles have exactly the §24 sections;
- secrets and personal data: private keys, Umar Techy API keys, provider keys, AWS keys, tokens, long hex/random strings, `password: value`, real email addresses (only example.com/org/net allowed), Pakistani mobile numbers (only all-zero examples).

## 7. Feedback, analytics, maintenance

Super Admin → Help center → **Feedback & searches**: 30-day counts, searches with no result, most searched, feedback to read (mark handled). Per-article helpful/not counts in the list. `help:maintain` (daily) removes searches after 180 days and handled feedback after 365 days.

## 8. Not built / limits

- Support ticket attachments (§49) and a status page or incident notices to stores (§51).
- Migration guides and deprecation notices for APIs: no breaking change exists yet; SDK and sandbox documentation: no SDK or sandbox exists.
- Admin interface and merchant articles in Urdu (the admin is English; G11 covered storefronts).
- AI-assisted support (§45–46): Module 26.
- Help pages are not in the store sitemap; videos and interactive tours (§78).
