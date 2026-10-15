---
key: marketplace-apps
title: Build an app for the marketplace
summary: How a marketplace app is listed, how a store connects it with a one-time code, and what it may read.
category: developers
type: guide
audience: developer
module: 27
tags: marketplace, oauth, apps
keywords: marketplace, app, oauth, authorization code, client id, client secret, redirect, connect, installation, settings form
related: developer-api-start, developer-webhooks, developer-api-errors
applies_from: 1.1
owner: Marketplace (Module 27)
review_due_on: 2027-04-30
---
A marketplace app works for many stores at once. Each store installs it and decides to give it access. The app then uses the [Developer API](help:developer-api-start) and [webhooks](help:developer-webhooks) like any integration, with keys made for that store alone.

## Getting listed

Umar Techy registers your app and gives you a **client id** and a **client secret**. The secret is shown once; keep it on your server. For each version you declare:

- the scopes it reads (`products:read`, `orders:read`, `customers:read`, `inventory:read`), and only those it needs;
- the webhook events it receives;
- its settings form, which stores fill in (text, number, on/off, choice, https address or secret);
- how it uses the data: what it reads, why, where it goes and how long it is kept.

A second person at Umar Techy reviews every version before it is published.

## Connecting a store

1. The store owner installs your app. Their browser comes to your first redirect address with `code`, `store` and `installation`.
2. From your server, within 10 minutes, send:

```
POST /api/dev/v1/oauth/token
Content-Type: application/json

{"grant_type": "authorization_code", "code": "<code>",
 "client_id": "<your-client-id>", "client_secret": "<your-client-secret>",
 "redirect_uri": "<the redirect address>"}
```

3. The answer has `access_token` (the store's API key for your app, with exactly the scopes it granted) and `webhook_signing_secret`. Both are shown once. A code works once; every failure answers 400 `invalid_grant`.

If the store connects again, the credentials you had stop working and you get new ones.

## Your installation

`GET /api/dev/v1/installation` with the store's key returns the granted scopes and events and the store's values for your settings form, secrets included.

## When access ends

Your key and webhooks stop at once when the store pauses or removes your app, when its store closes, or when Umar Techy suspends your app. Calls then answer 401; handle it by stopping work for that store.

Never ask store owners for their password or their own API keys. Everything you need comes through the connection.
