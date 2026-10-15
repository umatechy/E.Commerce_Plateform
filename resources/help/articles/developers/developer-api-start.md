---
key: developer-api-start
title: Get started with the Developer API
summary: Create an API key, choose its scopes, and make your first call to read your store's products, orders, customers and stock.
category: developers
type: guide
audience: developer
module: 31
tags: api, api key, scopes
keywords: api, rest, developer, integration, api key, token, bearer, scope, openapi, postman
error_codes: unauthenticated, insufficient_scope, developer_api_disabled
contexts: /settings/developer
related: developer-api-errors, developer-webhooks
applies_from: 1.1
owner: API and developer platform (Module 31)
review_due_on: 2027-04-30
---
The Developer API lets your own software **read** your store's data. It cannot change anything.

## 1. Create an application and a key

1. Open [Developer](/settings/developer) and press **Add application**.
2. Open the application and create an **API key**. Choose only the **scopes** it needs:

| Scope | Lets the key read |
|---|---|
| `products:read` | Products |
| `orders:read` | Orders |
| `customers:read` | Customers |
| `inventory:read` | Stock levels |

3. Copy the key when it is shown: **it is shown once**. Keep it on your server, never in a web page or an app people download.

Issuing keys is for the Owner. You can revoke a key at any time.

## 2. Make a call

Send the key in the `Authorization` header:

```
GET /api/dev/v1/products
Authorization: Bearer <your-api-key>
Accept: application/json
```

The available calls:

- `GET /api/dev/v1/products` and `/api/dev/v1/products/{product}`
- `GET /api/dev/v1/orders` and `/api/dev/v1/orders/{order}`
- `GET /api/dev/v1/customers` and `/api/dev/v1/customers/{customer}`
- `GET /api/dev/v1/inventory`

## The full reference

The **API reference** on the Developer page lists every call, its scope and its fields. The same description is available as an OpenAPI file at `/api/dev/v1/openapi.json`, which tools such as Postman can import.

Next: [Errors, paging, limits and retries](help:developer-api-errors).
