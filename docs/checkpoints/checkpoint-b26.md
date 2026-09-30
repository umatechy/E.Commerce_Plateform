============================================================
PHASE B26 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B26 — Support (Module 34)

Implementation Summary:
Shoppers and merchants can now ask for help, and the people who answer
have an inbox with service levels. There are two desks.

The store desk — shoppers asking a store:
- a contact form on the storefront (a honeypot and rate limits keep bots
  out);
- guests get a private link by email, with the token kept in the URL
  fragment so it never reaches a server;
- signed-in customers see their requests in their account and can start
  one from any order;
- requesters can reply, mark a request solved and rate it once.

The platform desk — a store's team asking the platform about billing,
technical problems or their account, answered by platform staff in
their own inbox.

Both teams' inboxes have:
- workload figures and filters;
- overdue tickets first;
- assignment, priority and topic changes (audited);
- internal notes that requesters never see;
- a reply box that sets the next status.

Service levels depend on priority (first reply and resolution
targets). An hourly job flags missed targets once, emails the assignee
or store owner, and closes resolved tickets after a 7-day reopen window.

Bugs fixed:
- Signed-out visitors of any admin page got a 500 (`login` route had no
  name). They now go to sign-in and come back to the page they wanted.
- The staff sign-in and store-registration forms never finished (JSON
  answer to Inertia's useForm) and never showed field errors.
- Admin Orders "cancel" and Inventory "adjust" were refused with 419 (no
  XSRF header).
- Agents could give a ticket another desk's topic.
- Service averages showed "—" for an average under a minute.
- Existing stores' Manager and Staff roles would have had no support
  access; a data migration grants it.

New Components Implemented:
App\Domain\Support — SupportTicket, SupportMessage, desk/category/
priority/status enums, SupportDeskService, TicketNumberGenerator,
SupportPresenter, SupportNotifier, SupportAgents, SupportPolicy,
support:maintain, and the Customer, Guest, Store and MerchantPlatform
support controllers; SuperAdminSupportController.

Frontend:
- storefront Contact and guest ticket pages;
- account Support, SupportNew and SupportTicket pages;
- admin Support/Index, Support/Platform and SuperAdmin/Support pages;
- shared MessageThread, RequesterTicket, AgentInbox, AgentTicketPanel
  and SlaBadge components;
- adminFetch and useApiForm helpers.

See docs/architecture/b26-support.md.

Database Changes:
- New: support_tickets, support_messages, support_ticket_sequences.
- Data: support permissions added to existing default Manager/Staff
  roles.

Automated Test Status:
EXECUTED.
- Backend: 876 tests — all passing on MySQL 8.0 (25 new in
  tests/Feature/Support/).
- Frontend: 32 Vitest tests passing (13 new); `npm run lint`,
  `tsc --noEmit` and `npm run build` pass.
- Static analysis: PHPStan/Larastan level 5 — no errors.

Runtime Verification Status:

| Area | Status |
|---|---|
| Migrations up / rollback / up (MySQL 8.0) | EXECUTED |
| PHPUnit suite (MySQL 8.0, Redis) | EXECUTED — PASSING |
| PHPStan level 5 | EXECUTED — CLEAN |
| Frontend lint / typecheck / Vitest / Vite build | EXECUTED — PASSING |
| `support:maintain` against the dev database | EXECUTED |
| End to end in headless Chromium (steps below) | EXECUTED |
| Support email delivery through a real mail provider | NOT EXECUTED — covered by the stored notification messages in tests |
| GitHub Actions CI run | NOT EXECUTED — runs on the next push to main/develop or a PR |

The end-to-end run, with zero page or API errors:
1. A guest uses the footer's contact form → S-number and a private link
   (token only in the #fragment, never in the server log) → the guest
   replies; a wrong token gets a friendly "link not valid".
2. A shopper registers and opens a request. HTML in the message shows as
   text.
3. The store owner signs in through the real form and lands back on
   `/support`. They set priority high, assign themselves, add an
   internal note and send a public reply → "Awaiting customer".
   A `?ticket=` deep link opens the guest's ticket.
4. The owner asks the platform for help (P-number).
5. The shopper sees the reply from "Omar" (first name only) without the
   internal note, marks it solved and rates it 5/5.
6. A platform agent answers and resolves the request in
   `/super-admin/support` (the store owner gets 403 there); the merchant
   sees the reply.

Known Limitations:
- No attachments, canned replies, email-in (replying by email), or
  customer-facing knowledge base yet.
- A guest's link cannot be rotated.
- Business-hours SLA calendars are not modelled (targets are in wall-clock
  hours).

Recommended Next Milestone:
Run the GitHub Actions workflow on a pull request for B21–B26. Then
choose the next module from the remaining list.
