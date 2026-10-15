---
key: you-do-not-have-permission
title: A page or button says you do not have permission
summary: Why a page is missing from your menu or an action is refused, and how the Owner can give you access.
category: troubleshooting
type: troubleshooting
audience: merchant
module: 02
tags: permissions, roles, access
keywords: forbidden, no permission, not allowed, access denied, page missing, menu missing, greyed out, locked, upgrade
error_codes: forbidden, feature_not_entitled
related: invite-your-team, change-your-plan, two-step-sign-in
applies_from: 1.1
owner: Authentication (Module 02)
review_due_on: 2027-04-30
---
## Problem

You cannot open a page or use an action you need.

## Symptoms

- The page is not in your menu, or it is shown grey with **Locked** or **Upgrade**.
- An action answers that you do not have permission (`forbidden`).
- A page says your package does not include it (`feature_not_entitled`).

## Likely causes

- Your **role** does not include that permission.
- The store's **package** does not include the feature.
- You are a store owner and **two-step sign-in** is not on yet: then every page except Security is locked.

## Safe checks

1. Hover over a grey menu entry: it says why it is closed.
2. Ask the Owner which role you have, or look it up under [Roles](/team/roles) if you can open it.

## Resolution steps

- **Role**: the Owner (or someone who manages the team) gives you a role that includes it, under [Team](/team). See [Invite your team](help:invite-your-team).
- **Package**: the Owner changes the plan under [Plan and billing](/billing). See [Change your plan](help:change-your-plan).
- **Two-step sign-in**: turn it on under [Security](/security). See [Turn on two-step sign-in](help:two-step-sign-in).

## Verification

Reload the page: the entry is open in the menu and the action works.

## Prevention

Give each person a role that fits their work, rather than sharing one account.

## When to contact support

If the Owner's own account is refused something, or the package should include a feature that is still refused, open a request under [Support](/support) and include the request ID from the message.
