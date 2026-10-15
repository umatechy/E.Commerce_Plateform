---
key: store-closed-or-suspended
title: Changes are refused because the store is closed, suspended or archived
summary: What the store_closed, store_suspended and store_archived messages mean and how to get your store working again.
category: troubleshooting
type: troubleshooting
audience: merchant
module: 03
tags: closed, suspended, archived, read-only
keywords: store closed, store suspended, store archived, read only, cannot save, nothing can be changed, shop closed
error_codes: store_closed, store_suspended, store_archived, store_not_operational
contexts: /settings/close-store
related: close-store, billing-invoices, contact-umar-techy
applies_from: 1.1
owner: Store management (Module 03)
review_due_on: 2027-04-30
---
## Problem

Saving anything in the admin is refused, and your shop may be closed to customers.

## Symptoms

- A banner at the top of every admin page says the store is closed, suspended or archived.
- Saving shows a message such as "This store is closed. Reopen it on the Close store page to make changes."
- Customers see that the shop has closed or is temporarily unavailable.

## Likely causes

- **Closed** (`store_closed`): the Owner closed the store.
- **Suspended** (`store_suspended`): Umar Techy suspended the store, for example over an unpaid invoice or a policy problem.
- **Archived** (`store_archived`): the store was closed more than 90 days ago.

## Safe checks

1. Read the banner at the top of the admin: it says which of the three it is.
2. Open [Plan and billing](/billing) and look for unpaid invoices.
3. While closed or suspended, you can still read everything and download your data. Nothing is lost.

## Resolution steps

- **Closed**: the Owner opens [Close store](/settings/close-store) and reopens the store, within 90 days of closing. See [Close your store, or reopen it](help:close-store).
- **Suspended**: pay any unpaid invoice (see [Pay your invoices](help:billing-invoices)) and ask Umar Techy from [Support](/support) to reactivate the store. Paying, support and your own account stay available while suspended.
- **Archived**: the store can no longer be reopened. Contact Umar Techy if you need something from it.

## Verification

The banner disappears and saving works again. Your storefront opens to customers.

## Prevention

Keep a payment method for your invoices up to date and pay invoices before their due date.

## When to contact support

If you do not know why the store was suspended, or you paid and it is still suspended after the team has had time to check your payment, open a request under [Support](/support) with the invoice number.
