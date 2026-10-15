---
key: platform-help-writing
title: Writing and publishing help articles
summary: "Internal: how help articles are written, reviewed, published, marked out of date and archived, and the rules every article follows."
category: getting_started
type: runbook
audience: platform
module: 34
tags: help center, documentation, internal
keywords: write article, publish article, review, knowledge base, release notes, deprecate, translation, urdu
contexts: /super-admin/help
related: platform-support-playbook
applies_from: 1.1
owner: Help and support (Module 34)
review_due_on: 2027-01-31
---
Internal. Shown to Umar Techy staff only.

## Where articles come from

- **Built-in articles** live in the code repository (resources/help) and are reviewed with the code. They are loaded after every deployment.
- **Articles written here**, under [Help center](/super-admin/help), by staff with the help role.

If you change a built-in article here, your version stays: a newer built-in text is then marked, and you can load it as a draft to compare.

## The steps

1. **Draft**: write it. Nobody outside the Super Admin sees a draft.
2. **Send for review**. The checker must pass first.
3. **Review and publish**: someone else with the help role approves it. You cannot approve your own version.
4. **Out of date**: mark it deprecated and name the article that replaces it. Readers are sent there.
5. **Archive** when nobody should read it any more.

A published article stays live while its next version is written. Every version is kept.

## Rules the checker enforces

- Audience: shoppers, store staff, owners only, developers, or Umar Techy staff (internal). It decides who can find the article at all.
- Links: `help:<key>` to another article the same readers can open, admin paths such as `/orders` that exist, `store:/path` for a shopper's store page, and https links.
- No passwords, keys, tokens, real email addresses or phone numbers. Use placeholders such as `<your-api-key>`, and example.com addresses.
- Troubleshooting articles have exactly these sections: Problem, Symptoms, Likely causes, Safe checks, Resolution steps, Verification, Prevention, When to contact support.

## Good practice

- Describe what the platform does today. Never promise what it does not do, or a response time without a support policy.
- Destructive steps need a clear warning.
- Urdu translations are made from the English article; when the English changes, the translation is marked out of date until it is updated.
- Check the **feedback** and the **searches with no result** under Help center → Insights: they show what to write next.

## Release notes

Release notes come with each version of the code as drafts. Publish them under Help center → Release notes: publishing sets the release date, and store teams see them under What's new.
