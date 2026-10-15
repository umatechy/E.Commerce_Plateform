---
key: platform-support-playbook
title: "Support playbook: handling a store's request"
summary: "Internal: how Umar Techy staff take a store's request from intake to closure: verification, safe diagnosis, escalation and what never to ask for."
category: support
type: runbook
audience: platform
module: 34
tags: support, playbook, internal
keywords: playbook, support process, verify identity, escalation, impersonation, open store as staff
contexts: /super-admin/support
related: platform-help-writing, contact-umar-techy
applies_from: 1.1
owner: Help and support (Module 34)
review_due_on: 2027-01-31
---
Internal. Shown to Umar Techy staff only.

## 1. Intake

Requests from stores arrive under [Platform support](/super-admin/support). Read the whole conversation and the store's state (status, package, unpaid invoices) on its page under [Stores](/super-admin/stores).

## 2. Verify before sensitive help

Before anything that touches an account (two-step sign-in, ownership, email change), verify the person is the account holder through the request itself, from the signed-in account. **Never** rely on a name, an email address, an order number or anything public alone.

## 3. Never ask for

Passwords, two-step codes, recovery codes, API keys, webhook secrets or payment provider passwords. If a merchant sends one, tell them to change it at once, and do not copy it anywhere.

## 4. Diagnose safely

- Prefer what the store already shows: its health checks, audit log, payments and shipments.
- Use the request ID from an error to find the exact call.
- Open a store's page under Stores only when needed: it asks you to confirm it is you, and every visit is recorded in the audit trail.

## 5. Escalate

- Billing questions: someone with the billing role.
- Suspected security problem: open an incident under [Security](/super-admin/security) and follow docs/security/incident-response-runbook.md.
- A defect: record the request ID, store, time and steps, and pass it to engineering.

Keep the ticket history: escalate inside the same request.

## 6. Close

Answer in plain words, say what was done, and mark the request resolved. If the answer was missing from the help center, write an article (see [Writing help articles](help:platform-help-writing)).
