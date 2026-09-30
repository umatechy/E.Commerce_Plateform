# Phase B26 — Step 1: Inspection + Gap Analysis (Support, Module 34)

After B25, shoppers had accounts and order history, and merchants had
billing (B23). But there was no way for anyone to ask for help:
- a shopper with a late parcel could only find the store's email address
  somewhere, if the store published one;
- a merchant with a wrong invoice had no channel to the platform at all.

Nothing recorded who asked what, whether it was answered, or how fast.

## What Existed

| Area | State before B26 | Gap |
|---|---|---|
| Shopper → store | Nothing | No contact form, no request history, no link from an order to "get help" |
| Merchant → platform | Nothing | Billing and technical questions had no channel or record |
| Team inbox | Nothing | No queue, assignment, priorities or internal notes |
| Service levels | Nothing | No first-reply or resolution targets, no overdue alerts |
| Emails | Module 21 notifications and the outbox (ADR-004) | No support events or templates |
| Permissions | Store roles (B1) | No support permissions |

## Bugs Found (fixed in B26)

| # | Bug | Found by | Fix |
|---|---|---|---|
| 1 | **Signed-out visitors of admin pages got a 500.** The `auth` middleware redirects to the route named `login`, but `/login` had no name ("Route [login] not defined") — `/billing`, `/orders`, `/inventory` and `/store-health` all failed this way | `SupportPagesTest` (a signed-out visit to `/support`) | `/login` is named. The sign-in page now also receives the page the visitor wanted (`intended`, a same-host path only) and returns there afterwards |
| 2 | **The staff sign-in and store-registration forms never finished.** They posted with Inertia's `useForm` to the JSON API. The session was created, but the JSON answer opened Inertia's error dialog, the page never moved on, and 422 field errors were never shown | End-to-end run in Chromium | `useApiForm` posts to the API with `fetch`, shows field errors under their fields, and loads the next page on success |
| 3 | **Admin writes were refused with 419.** The Orders "cancel" and Inventory "adjust" actions used a bare `fetch` without the `X-XSRF-TOKEN` header that a Sanctum stateful (session) request needs. Inventory also left a failed request unhandled | Reading the admin pages while writing the support inbox's API helper | `adminFetch` sends the XSRF token and JSON headers; both pages use it and show errors |
| 4 | **An agent could give a ticket another desk's topic** (e.g. "billing & plan" on a shopper's ticket) | Writing the inbox's topic control | `PATCH` validates the category against the ticket's own desk |
| 5 | **Service averages showed "—" for a real 0.** The summary returned `0` both for "no data yet" and for an average first reply under a minute | End-to-end run (the owner answered within a minute) | The averages are `null` until there is something to average |
| 6 | **Existing stores' teams had no support access.** `StoreObserver` grants default role permissions only when a store is created | Reviewing how new permissions reach existing stores | A data migration gives existing default Manager and Staff roles the same support permissions new stores get (custom roles are left to the owner) |

Bugs 1–3 predate B26; they surfaced because the support inbox is the
first admin page reached from an email link while signed out.

## Design Decisions

### Two desks, one model

A ticket belongs to a **desk**:
- **store desk** — a shopper (signed-in customer or guest) asking a store;
- **platform desk** — a store's team asking the platform.

Both kinds live in one table with the same statuses, messages, SLAs and
emails. Every ticket carries the `store_id` it concerns, so tenant
scoping works unchanged. The platform inbox (`/super-admin/support`)
reads only platform-desk tickets across stores. The store inbox reads
only its own store-desk tickets, so a store never sees its own requests
to the platform, and platform staff never see shoppers' conversations.

Numbers are sequential and readable:
- `S-000001` is each store's first shopper ticket;
- `P-000001` is the platform's first ticket.

Both come from a locked sequence row inside the creating transaction.

### Requesters see less than agents

`SupportPresenter` builds two views of a ticket.

The requester's view never contains:
- internal notes;
- the assignee, SLA data or priority;
- an order's total or internal status.

Agents are named by first name only.

The team's view has everything, including the order's total and status.

### Guests get a private link, and its token never reaches a server log

A guest's ticket is opened with a 48-character random token. Only its
SHA-256 hash is stored, and it is compared with `hash_equals`. The token
is sent to the guest once:
- in the contact form's response;
- in the acknowledgement email, which is sent directly — the token is
  never written to an outbox payload.

The email link carries the token in the URL **fragment**:
`/support/tickets/{id}#token=…`. Browsers never send a fragment, so it
stays out of:
- request lines and access logs;
- the Inertia page object (which echoes the URL);
- `Referer` headers.

The page reads the fragment and sends the token in an `X-Support-Token`
header. A wrong token answers exactly like a missing ticket (404).

### Service levels by priority

| Priority | First reply | Resolution |
|---|---|---|
| Urgent | 1 h | 8 h |
| High | 4 h | 24 h |
| Normal | 24 h | 72 h |
| Low | 48 h | 120 h |

The targets are in `config/support.php` and are measured from when the
ticket was opened. Changing the priority recalculates targets that are
still running.

The hourly `support:maintain` job:
- flags a missed target once (`sla_breached_at`) and emails the assignee
  or the store owner;
- closes resolved tickets after the reopen window (7 days).

A requester's reply inside that window reopens the ticket. After it, the
requester is asked to open a new one.

### Conversation state follows the conversation

- A public agent reply records the first response and moves the ticket
  to "awaiting customer" (the agent can choose open, on hold or
  resolved instead).
- A requester's reply moves it back to "open".
- An unassigned ticket is assigned to the first agent who answers it.
- Internal notes change nothing the requester can see and send no email.

### Permissions

| Permission | Allows | Default roles |
|---|---|---|
| `support.view` | Read the store inbox | Manager, Staff |
| `support.reply` | Reply, add internal notes, change status | Manager, Staff |
| `support.manage` | Assign, change priority and topic | Manager |
| `support.platform` | Contact the platform's team | Owner only |

The owner has all of them. The inbox's `summary` endpoint reports the
signed-in agent's `abilities`, so the page disables controls the server
would refuse. The server still checks every request.
