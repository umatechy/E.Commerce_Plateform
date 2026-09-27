# Phase B20 — Secrets & Environment Configuration (Module 20 Phase 18-19)

B17 owns the APPLICATION's own configuration architecture
(`platform_settings`/`store_settings`, `ConfigService`). This document
covers the layer BELOW that — how the DEPLOYMENT itself supplies
environment variables to a running instance, and how each variable already
present in `.env.example` (all pre-existing, confirmed by inspection —
none invented this milestone) is classified.

## Classification

| Variable | Class | Notes |
|---|---|---|
| `APP_NAME`, `APP_URL`, `APP_TIMEZONE` | Public configuration | Safe to appear in client-visible contexts |
| `APP_ENV`, `APP_DEBUG` | Public configuration | `APP_DEBUG` MUST be `false` in production (Non-Negotiable — verbose error pages/stack traces must never reach a real user) |
| `APP_KEY` | **Application secret** | Backs Laravel's own `Crypt` facade (already used by B17's secret-type settings) — never logged, never rotated casually |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE` | Infrastructure configuration | Not secret by themselves, but not client-exposed either |
| `DB_USERNAME`, `DB_PASSWORD` | **Infrastructure credential** | Must be a LEAST-PRIVILEGE application user, never `root` (Non-Negotiable) |
| `REDIS_HOST`, `REDIS_PORT` | Infrastructure configuration | |
| `REDIS_PASSWORD` | **Infrastructure credential** | |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` | Public configuration | Architecture choice, not sensitive |
| `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` | Public configuration | Must be updated per-environment |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | **Infrastructure credential** | S3-compatible object storage — never logged, never returned via any API |
| `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT` | Infrastructure configuration | `AWS_ENDPOINT` is what keeps this provider-neutral |
| `MEILISEARCH_KEY` | **Infrastructure credential** | |
| `REVERB_APP_ID`, `REVERB_APP_KEY` | Public-ish configuration | Identifies the Reverb app to WebSocket clients |
| `REVERB_APP_SECRET` | **Infrastructure credential** | Used server-side to sign Reverb auth — never sent to a client |
| `MAIL_*` credentials (when a real provider replaces `log`) | **Infrastructure credential** | |

## Environment Separation (Module 20 Phase 18/38)

Development, staging, and production MUST each have their own, entirely
separate: `APP_KEY`, database, Redis instance/database-index, storage
bucket/disk, domain, and mail/notification provider credentials. Staging
must never share production's database or send real customer notifications
— stated explicitly (Phase 38).

## Where Secrets Actually Live

**In this sandbox**: nowhere — `.gitignore` already excludes `.env`,
`.env.backup`, `.env.production` (confirmed by inspection, unchanged this
milestone). **In a real deployment**: a `.env` file OUTSIDE the Git working
tree (or a real secrets manager — Module 20 explicitly permits either, and
explicitly forbids fabricating one if none exists). No concrete secrets-
manager product is chosen or coded here, since none is specified by the
authoritative documents.

## APP_KEY Rotation — Coordination With B17

Rotating `APP_KEY` makes every value B17 encrypted with the OLD key
unreadable (any future `secret`-typed setting) — noted here because
deployment tooling is what would actually perform a rotation, and it must
never do so casually or without a documented re-encryption step.

## Never Exposed, Anywhere (Non-Negotiable, Restated for This Layer)

No environment variable value is ever returned through any API response,
written to any log line, included in any audit record, or exposed to the
frontend build (Vite's own `import.meta.env` only exposes variables
explicitly prefixed `VITE_`, and none of this platform's secrets carry that
prefix — confirmed by inspection of `.env.example`).
