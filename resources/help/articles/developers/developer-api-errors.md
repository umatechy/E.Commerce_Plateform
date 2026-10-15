---
key: developer-api-errors
title: Errors, paging, rate limits and retries
summary: How the Developer API answers errors, pages through lists, limits requests, and how to troubleshoot with a request ID.
category: developers
type: reference
audience: developer
module: 31
tags: api, errors, pagination, rate limit, request id
keywords: error, error code, 401, 403, 404, 423, 429, pagination, page, per_page, rate limit, retry, request id, idempotency
error_codes: unauthenticated, insufficient_scope, not_found, store_not_operational, too_many_requests, server_error
contexts: /settings/developer
related: developer-api-start, developer-webhooks
applies_from: 1.1
owner: API and developer platform (Module 31)
review_due_on: 2027-04-30
---
## Errors

Every error has the same shape:

```
{
  "code": "insufficient_scope",
  "message": "This API key does not have the \"orders:read\" scope.",
  "details": {},
  "requestId": "<request-id>"
}
```

Compare `code`, not `message`: messages may be reworded, codes stay.

| Status | Code | Meaning |
|---|---|---|
| 401 | `unauthenticated` | No key, or an invalid, revoked or expired key |
| 403 | `insufficient_scope` | The key lacks the scope this call needs |
| 404 | `not_found` | No such record in this store |
| 423 | `store_not_operational` | The store is suspended, closed or archived |
| 429 | `too_many_requests` | Rate limit reached |
| 503 | `developer_api_disabled` | The Developer API is paused by Umar Techy |
| 500 | `server_error` | Something failed on our side |

Error answers never contain internal details.

## Request IDs

Every answer has an `X-Request-Id` header (also `requestId` in errors). Log it. When you ask Umar Techy for help, give the request ID: it lets us find exactly that call. Never send your API key.

## Paging

Lists come in pages: `?page=2&per_page=50` (25 by default, at most 100). The answer has `data`, `links` (`next` is empty on the last page) and `meta` with the totals. Lists have a fixed order (orders newest first), so paging never skips or repeats a record.

## Rate limits

60 requests a minute per key by default; Umar Techy may set another limit. Over the limit you get 429 with a `Retry-After` header: wait that many seconds.

## Retries

Retry 429, 503 and 5xx answers after a pause that grows each time. Do not retry 401, 403 or 404 without fixing the cause. Every call is a read, so a retry never changes data.
