============================================================
PHASE B28 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B28 — Store timezone (gap G5; Module 33 §50; SRS DATA-010, LOC-004)

Implementation Summary:
The `store.timezone` setting existed since B17 but nothing read it. Every
date calculation used UTC, so a store in Karachi saw "today" start at
5 a.m. and a campaign scheduled for 9:00 ran at 14:00. The setting now
decides what a day is and what a typed time means. Storage is unchanged:
every timestamp is still UTC (§50.2).

One class does the work: App\Domain\Settings\Services\StoreClock.
- A date or time typed without an offset is wall-clock time in the
  store's timezone. A value with its own offset or "Z" keeps it.
- Calendar periods start and end at the store's midnight.
- What it returns for queries and storage is already UTC.
- Outside a store context (platform, Super Admin, console) it is UTC.

Where it is used:
- Analytics date filters (today, this month, custom ranges, ...).
- Sales report: orders are grouped by the store's day. The range is
  split where the UTC offset changes (daylight saving), each piece is
  grouped in SQL with a fixed shift, and a day split by the change is
  added up. MySQL's CONVERT_TZ was not used because it needs timezone
  tables that are not installed everywhere.
- Campaign scheduling (scheduled_at).
- Promotion windows (starts_at, ends_at).
- Marketing segment rules that compare dates.
- Audit log "from"/"to" filters.
- Display (§50.3): the server shares the timezone with every page
  (`auth.timezone` for admin pages, `storefront.store.timezone` for the
  storefront). resources/js/lib/datetime.ts formats every date with it.

Defined behaviour that stays UTC (§50.5):
- Platform billing periods, invoices, due dates and usage periods are
  UTC periods. The billing pages show them as UTC dates.
- Support service levels are elapsed hours and do not depend on a
  timezone. Business hours (Module 33 §51) do not exist yet.
- API key expiry and Super Admin inputs are platform-level (UTC).

Tests:
tests/Feature/Settings/StoreTimezoneTest.php (8 tests): typed times,
no-store fallback, period boundaries, per-day grouping across midnight,
a day split by a daylight-saving change, campaign and promotion input,
shared page props. resources/js/lib/datetime.test.ts (4 tests).

Requirements closed:
DATA-010; LOC-004 for the timezone part. Number and date formats still
follow the viewer's browser locale; store locale formatting belongs to
localization (G11).

Known limits:
- There is no admin screen for changing the timezone. It is set through
  the settings API (PUT /api/v1/settings/store.timezone). The screen
  comes with the admin UI work (G6).
- A new store's timezone is UTC until it is set.

Next:
G2 (security baseline).
