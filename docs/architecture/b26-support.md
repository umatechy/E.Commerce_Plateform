# Phase B26 — Support (Module 34)

## Layout

```
app/Domain/Support/
  Models/SupportDesk.php            store | platform (S- / P- numbers)
  Models/SupportCategory.php        topics; forDesk() lists each desk's
  Models/SupportPriority.php        low … urgent, with SLA hours from config
  Models/SupportStatus.php          open, awaiting_customer, on_hold, resolved, closed
  Models/SupportTicket.php          tenant-scoped; guest_token_hash hidden
  Models/SupportMessage.php         requester | agent | system; is_internal
  Services/SupportDeskService.php   open, reply (both sides), update, resolve, rate, maintain
  Services/TicketNumberGenerator.php  locked per-desk sequence
  Services/SupportRequester.php     customer / guest / user value object
  Services/SupportPresenter.php     requester view vs team view
  Services/SupportNotifier.php      emails for the support outbox events
  Services/SupportAgents.php        who may be assigned (store team / platform staff)
  Policies/SupportPolicy.php        view / reply / manage / platform
  Console/MaintainSupportCommand.php  support:maintain (hourly)
  Http/Controllers/
    CustomerSupportController.php       /customer/support/tickets…
    GuestSupportController.php          /storefront/support/contact, /storefront/support/tickets/{id}…
    StoreSupportController.php          /support/… (the store's inbox)
    MerchantPlatformSupportController.php  /platform-support/tickets…
    HandlesAgentActions.php, HandlesSupportRequests.php  shared inbox and validation code
app/Domain/SuperAdmin/Http/Controllers/SuperAdminSupportController.php  /super-admin/support/…
config/support.php
database/migrations/2028_01_01_000001_create_support_tables.php
database/migrations/2028_01_01_000002_grant_support_permissions_to_existing_roles.php
resources/js/lib/{support,adminApi,useApiForm,xsrf}.ts
resources/js/Components/Support/{MessageThread,RequesterTicket,AgentInbox,AgentTicketPanel,SlaBadge}.tsx
resources/js/Pages/Storefront/{Contact,SupportTicket}.tsx
resources/js/Pages/Storefront/Account/{Support,SupportNew,SupportTicket}.tsx
resources/js/Pages/Support/{Index,Platform}.tsx
resources/js/Pages/SuperAdmin/Support.tsx
```

## Data

```
support_tickets
  public_id (ULID), store_id, desk, number (unique per desk+store)
  subject, category, priority, status, channel
  requester_type (customer|guest|user), requester_id, requester_name, requester_email
  order_id?, assignee_id?, guest_token_hash? (SHA-256)
  first_response_due_at, first_responded_at, resolution_due_at, sla_breached_at
  last_requester_activity_at, last_agent_activity_at, resolved_at, closed_at
  satisfaction_rating (1–5)?, satisfaction_comment?
support_messages
  public_id, store_id, ticket_id, author_type, author_id?, author_name, body, is_internal, created_at
support_ticket_sequences
  key ("store:{id}" | "platform"), next_value
```

## Who Uses What

| Who | Where | API |
|---|---|---|
| Guest shopper | `/contact`, then `/support/tickets/{id}#token=…` | `POST /storefront/support/contact`; `GET/POST /storefront/support/tickets/{id}[/messages\|/resolve\|/rating]` with `X-Support-Token` |
| Signed-in shopper | `/account/support`, `/account/support/new`, `/account/support/{id}`; "Get help with this order" on an order | `/customer/support/tickets…` |
| Store team | `/support` (inbox), `?ticket={id}` from emails | `GET /support/tickets`, `/support/summary`, `/support/agents`, `GET/PATCH /support/tickets/{id}`, `POST /support/tickets/{id}/messages` |
| Store team → platform | `/support/platform` | `/platform-support/tickets…` |
| Platform staff | `/super-admin/support` | `/super-admin/support/…` (platform context, audited) |

On the storefront, both paths work: `/shop/{slug}/…` and a store's
custom domain.

## Flows

```
Shopper opens ─► SupportDeskService::open (transaction)
                   number = TicketNumberGenerator::next (row lock)
                   ticket + first message, SLA due dates from the priority
                   outbox: support.ticket_created
                 guest: SupportNotifier::guestAcknowledgement (direct; link has #token)
Outbox consumer ─► NotificationEventRouter ─► SupportNotifier::route
                   ticket_created   → requester receipt (not guests) + store owner (store desk)
                   agent_replied    → requester (public replies only)
                   requester_replied→ assignee, else store owner
                   sla_breached     → assignee, else store owner
Agent replies ──► replyAsAgent: auto-assign if unassigned; public reply → first_responded_at,
                   status awaiting_customer (or the chosen one); internal → no event
Requester replies ► replyAsRequester: status open; refused when closed, or resolved > reopen_days
Hourly ─────────► support:maintain: flag overdue once (+ event), close resolved after reopen_days
```

## Inbox Ordering

Overdue tickets first, then by first-response due time (the oldest
waiting first), then by id. The filters are:
- status (default: all active);
- priority and topic;
- assignee: me, unassigned or a named agent;
- overdue only;
- a search over number, subject, requester name and email. LIKE
  wildcards in the search are escaped.
