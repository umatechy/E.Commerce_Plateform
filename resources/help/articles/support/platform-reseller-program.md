---
key: platform-reseller-program
title: Running the reseller program
summary: "Internal: tiers and terms, inviting and approving resellers, which stores count for them, the partner price, commissions and payouts."
category: support
type: runbook
audience: platform
module: 28
tags: resellers, partners, commissions, internal
keywords: reseller, partner, agency, tier, partner price, commission, payout, terminate, attribution, sign-up link
contexts: /super-admin/resellers
related: platform-support-playbook, marketplace-review
applies_from: 1.1
owner: Affiliates and resellers (Module 28)
review_due_on: 2027-04-30
---
Resellers are businesses (agencies, freelancers, consultants) that sell Umar Techy to their clients. Super Admin → [Resellers](/super-admin/resellers) runs the program. Changing anything needs the **Reseller program** capability. Terms, termination, store assignment, payout details, payouts and adjustments also ask for your password again.

## Tiers: the terms

A tier sets what a group of resellers gets:

- **Client discount**: how much less their clients pay than the public package price (at most 50%). This is the partner price.
- **First invoice** and **Renewals**: the commission on a client's first paid invoice, and on later invoices (renewals and upgrades).
- **Renewals for**: how many months renewals earn. Leave it empty for as long as the client pays; 0 means the first invoice only.
- **Hold**: the days after an invoice is paid before its commission is approved, so refunds come in first.
- **May create stores**, and how many.

Nothing is filled in for you: Umar Techy decides these numbers. **Each store keeps the terms of the day it was brought.** Editing a tier changes only stores brought afterwards.

## Resellers

- **Invite** a business, or **approve** an application from the public partner page (/partners) with a tier. They get an emailed invitation (14 days). It can be accepted only with the account of the invited address. The portal opens only with two-step sign-in.
- **Pause**: no new stores and no new sign-ups through their link. Their commissions continue.
- **Terminate**: the portal closes at once and invoices issued afterwards earn nothing. Commissions already earned can still be paid. Their clients keep their stores and their partner price.

## Which stores count

A store counts for a reseller when the reseller created it in the portal, or when the client signed up through the reseller's link (`/register?partner=CODE`). You can also **Assign a store** with a reason. That ends another reseller's attribution, and only invoices issued from then on count.

**End** an attribution when a client leaves a reseller: the store pays the public price from its next invoice.

A reseller never owns or joins a client's store. Their portal shows the store's name, package and state, and whether the owner joined. It never shows the store's customers, orders or settings.

## Commissions and payouts

Every hour, each paid Umar Techy invoice of a store that counts makes one commission. A credit note on that invoice takes back the same share, before or after payment. **Reject** a commission you should not pay (for example, a suspected fake store), with a note.

To pay: open the reseller, **Prepare payout** in a currency. You are shown the full account (each look is in the audit log). Send the money from Umar Techy's account, then **Record as paid** with the reference. Corrections are **adjustments** with a reason; they are settled with the next payout.
