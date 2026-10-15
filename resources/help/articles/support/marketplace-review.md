---
key: marketplace-review
title: "Reviewing and running marketplace apps"
summary: "Internal: how Umar Techy staff list an app, review a version, publish it, and stop it everywhere if something goes wrong."
category: support
type: runbook
audience: platform
module: 27
tags: marketplace, review, kill switch, internal
keywords: marketplace review, publish app, suspend app, kill switch, client secret, compromised app
contexts: /super-admin/marketplace
related: platform-support-playbook
applies_from: 1.1
owner: Marketplace (Module 27)
review_due_on: 2027-01-31
---
Internal. Shown to Umar Techy staff only. Writing needs the marketplace role.

## Listing an app

On [Marketplace](/super-admin/marketplace), **Add app** with the publisher's details, privacy policy, redirect addresses and webhook address (all https). Give the client id and secret to the publisher over a safe channel; the secret is shown once.

## A version

Write the version: what it may read, which events, its settings form and how it uses the data. Ask only for what its features need. Send it for review.

## Reviewing

Someone other than the person who submitted it opens **Review** and confirms each point:

- permissions match the features;
- the data use statement is complete and true;
- the privacy policy is published;
- addresses use https, credentials and webhook signatures are handled safely;
- it was installed and tried on a test store;
- stores can reach the publisher.

Then publish. Stores see the new version as an update; new permissions need the owner's agreement.

## If an app misbehaves

1. **Suspend everywhere** with a reason stores will see. Its keys and webhooks stop at once in every store.
2. If its secret may be known to others, make a **new client secret**.
3. Open an incident under [Security](/super-admin/security) when store data may be affected, and follow docs/security/incident-response-runbook.md.
4. **Resume** when it is safe; installations that were running continue, and paused ones stay paused.

**Retire from catalog** stops new installations; stores that have the app keep it.
