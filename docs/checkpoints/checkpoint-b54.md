============================================================
PHASE B54 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B54 — Help center and documentation (gap G12):
- SRS SUP-001, SUP-005, SUP-006, SUP-007.
- Module 34: §4–15, §24–25, §28–35, §43, §47–48, §56–62, §73.

Owner request (2026-10-14): "yes ab b54 start kro".

Starting point:
- v1.1 at cb7a707 (B53, CI green).
- Module 34 had support tickets only (B26).
- No knowledge base, help center, page help, release notes or
  documentation checks.

Implementation Summary:
1. Help content as code: resources/help (47 English articles, 7 Urdu
   translations, release notes 1.1), front matter with Module 34 §7
   metadata.
   - `help:sync` loads it after deployment; it is idempotent, refuses bad
     content, keeps staff edits and archives removed articles.
   - `help:check` runs in CI.
2. Knowledge base: articles, revisions, statuses (draft, in review,
   published, deprecated with replacement, archived), product version,
   owner, review date, translations marked out of date.
3. Role-aware visibility (HelpAudiencePolicy on the existing roles):
   - shoppers, store staff, owners, developers, Umar Techy staff;
   - restricted articles answer 404 and never appear in search, counts or
     page help.
4. Admin:
   - a Help button on every page: page help, search, help center, What's
     new, Umar Techy support;
   - /help, /help/articles/{key}, /help/whats-new;
   - "How?" links on the setup checklist;
   - "Was this helpful?" with reasons.
5. Storefront help center (/help, /help/{key}): shopper articles in
   English or Urdu (RTL), search, the store's policies and the contact
   form, footer link, feedback.
6. Super Admin → Help center:
   - article list with filters (waiting for review, review due, newer
     built-in text);
   - editor with preview and checker;
   - review by a second person, deprecate, archive, publish again;
   - history, feedback, no-result searches;
   - release notes published there, which sets the release date.
   - New platform capability `help` (super admin, platform admin, support
     admin).
7. Checks: metadata, links to articles, admin and storefront pages,
   developer API paths and webhook events, the troubleshooting
   framework, secret and personal-data scanning.
8. Analytics without identity: searches (numbers and emails masked) and
   feedback.
   - `help:maintain` (daily) keeps searches 180 days and handled feedback
     365 days.

Docs:
- docs/architecture/b54-help-center.md
- docs/security/b54-security-review.md
- gap matrix: G12 closed; SUP-001, SUP-005, SUP-006 and SUP-007 ✅;
  Module 34 row.

Found and fixed on the way:
- Search matched inside words ("cod" found "code") and returned results
  when some words matched nothing. It now matches word starts, short
  words as whole words, and needs every word unless the phrase matched.
- The editor's required Owner field was inside a collapsed section. It is
  now always shown.
- The checker caught merchant articles linking to owner-only help. Close
  store and Change plan are now for all store staff; they say only the
  Owner can act.

Decisions to confirm:
- Built-in articles are published as "Repository review" (reviewed in
  the pull request), not reviewed again in the Super Admin.
- A second person must approve every article written in the Super Admin.
  There are two super admins today, so this works.
- Support admins get the new `help` capability (writing help articles).
- Release notes 1.1 stay a draft until Umar Techy publishes them at go-live.
  Publishing sets the date.
- Search analytics are kept 180 days and handled feedback 365 days.

Tests:
- New: HelpCenterTest (17 tests, 201 assertions); help.test.tsx (2).
- PHP suite: 1266 passed.
- Vitest: 251 passed.
- PHPStan, ESLint and TypeScript are clean. Production build OK.
- help:check is valid.

Browser check (real Chromium, B46 Tax Store owner and platform staff, MFA
on): 8 of 8 passed, no 5xx.
1. The Help button on the dashboard shows the setup article.
2. Searching store_closed opens the troubleshooting article.
3. Feedback is stored for the revision read, without the reader.
4. Help center topics; internal articles are not shown to the owner.
5. "How?" link: the checklist was already done for this store, so there
   was nothing to show (covered by the build).
6. Storefront help: search; staff articles are 404; an Urdu article at
   phone width with no horizontal scroll.
7. Super Admin editor: the checker flags a phone number and a broken link.
8. Feedback and searches tab; release notes 1.1 shown as a draft (not
   published locally).

Local data note:
- Local feedback rows from the check.
- The local draft "B54 check" was not saved (the editor was cancelled).

Not built (b54-help-center.md §8):
- support attachments;
- status page;
- migration and SDK guides (nothing to migrate, no SDK);
- admin in Urdu;
- AI-assisted support;
- help pages in the sitemap.

CI: pending.

Next: B55 — PWA (gap G16, Module 25), the next module in the matrix's
recommended order.
