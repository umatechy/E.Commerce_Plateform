# Production deployment — Umar Techy E-Commerce on the shared Umar Techy server

Status: **live at https://store.umartechy.com since 2026-10-24** (release
`v1.1.0-rc.1`, commit 8a6d87d). Done with the owner's approval: A1 preflight,
A2 clone, A3 settings, A4 DNS, A5 first install, A6 site file
(`/opt/utschoolerp/deploy/nginx/conf.d/20-umartechy-shop.conf`; the HTTP-only
version is kept in `/root/20-umartechy-shop.conf.phase1`), A7 certificate
(Let's Encrypt, renewed by School ERP's daily job), A8 super admins, A9 (done
2026-10-27 with v1.1.0-rc.4: mail from info@umartechy.com through
smtp.hostinger.com:587 — Hetzner blocks outgoing 25 and 465 — and backups on
the S3 bucket umartechy-store-backups, `BACKUP_DISK=backups_s3`; first S3 backup
verified deep and rehearsed). Every further server step still needs the owner's
explicit approval. The images and the Compose project are smoke-tested in CI
(`docker` job in `.github/workflows/ci.yml`) on every push.

Note for scripted server steps: `docker compose run` reads standard input, so
in a script fed through `ssh … bash -s` give it `< /dev/null` (or it consumes
the rest of the script).

The server already runs **School ERP** with real data (Docker + Nginx +
PostgreSQL). School ERP's availability and data come first: this deployment
never stops, restarts, recreates or reconfigures anything of it.

## 1. Topology

Verified on the server by the read-only preflight (2026-10-24): Ubuntu 26.04,
Docker 29.7 / Compose 5.5, 4 CPUs, 7.6 GiB RAM (6.2 GiB available; School ERP
uses ~0.7 GiB), 51 GB free disk, UFW active (deny incoming except 22/80/443).
School ERP is the Compose project `utschoolerp` (`/opt/utschoolerp`); its Nginx
is a **container** (`utschoolerp-nginx-1`, ports 80/443, network
`utschoolerp_internal` 172.18.0.0/16, `conf.d` mounted from
`/opt/utschoolerp/deploy/nginx/conf.d`, certificates in
`/opt/utschoolerp/data/letsencrypt`, renewed daily by
`/etc/cron.d/utschoolerp-certbot-renew`). It proxies to `http://web:8080` and
`http://api:8080`. Port 8085 and subnet 172.30.240.0/24 are free.

Because that Nginx is a container, it cannot reach `127.0.0.1:<port>` on the
host, and UFW blocks its network from the host's other ports. So the shop's
web container (only it) also joins `utschoolerp_internal`, under the unique
name `umartechy-shop-web` — nothing of School ERP is changed or restarted.
It is called `shop-web`, never `web`, so it cannot take over the `web` name
School ERP's Nginx proxies to (checked in CI).

```
Internet ──80/443──► utschoolerp-nginx-1 (School ERP's; one NEW file 20-umartechy-shop.conf)
                        │ proxy_pass http://umartechy-shop-web:8080  (network utschoolerp_internal)
                        ▼
          ┌──────── Compose project "umartechy-ecom" ───────────────────────┐
          │ shop-web (nginx:1.27) ── also on utschoolerp_internal, nothing else │
          │ network "app" (bridge, fixed subnet ECOM_APP_SUBNET)            │
          │   shop-web  ──fastcgi──►  app (php-fpm 8.3)                      │
          │                           worker (queue:work)                    │
          │                           scheduler (schedule:work)              │
          │ network "data" (internal: no route out, no published port)      │
          │   mysql (8.0, own volume)    redis (7.4, own volume, password)   │
          └──────────────────────────────────────────────────────────────────┘
School ERP: its containers, volumes, PostgreSQL and existing files — untouched.
```

| Concern | How it is kept apart |
|---|---|
| Containers, networks, volumes | One Compose project name (`umartechy-ecom`); every name is prefixed with it |
| Database | Its own MySQL 8 container and volume; ERP's PostgreSQL is not used (the app needs MySQL: MySQL-specific SQL and `mysqldump` backups) |
| Redis | Its own container, password, append-only file, `noeviction` |
| Ports | Only `shop-web`, only on `127.0.0.1:<ECOM_HTTP_PORT>` (local checks); MySQL and Redis publish nothing |
| Web traffic | One new Nginx site for the shop subdomain; no `default_server`, no catch-all, no edit to ERP's site |
| Resources | CPU and memory limits on every service (`.env`, see §6) |
| Logs | Docker json-file, 10 MB × 5 per container; audit log in its own volume |

## 2. What the repository provides

| File | Purpose |
|---|---|
| `Dockerfile` | `app` target (PHP-FPM, extensions, MySQL client, non-root uid 1000) and `web` target (Nginx with the built assets) |
| `.dockerignore` | Keeps `.env`, `mysql.env`, keys, dumps, `vendor`, `node_modules` and runtime data out of images |
| `docker-compose.prod.yml` | The production project (§1) |
| `docker/entrypoint.sh` | Refuses to start without required settings or with `APP_DEBUG=true`; builds Laravel's caches; never migrates |
| `docker/php/*`, `docker/nginx/default.conf` | PHP, PHP-FPM pool (health ping) and the web container's Nginx |
| `docker/mysql/init/10-rehearsal-grants.sh` | First start only: lets the app account create/drop `<db>_rehearsal_*` for restore rehearsals |
| `deploy/production/env.production.example`, `mysql.env.example` | Settings templates (no secrets) |
| `deploy/production/preflight.sh` | **Read-only** server check: resources, containers, networks, ports, Nginx; free port and subnet |
| `deploy/production/deploy.sh` | Build, pre-migration dump, migrate, start, health check (this project only) |
| `deploy/production/rollback.sh` | Back to the previous images (this project only) |
| `deploy/production/compose.shared-proxy.yml` | Lets only `shop-web` join an existing proxy's network (`SHARED_PROXY_NETWORK`) |
| `deploy/nginx/shop-in-proxy-container.conf.example` | **Used here:** the new site file for School ERP's Nginx container (HTTP, then HTTPS) |
| `deploy/nginx/ecommerce-site.conf.example` | For a server whose Nginx runs on the host (not this server) |

## 3. Application settings that matter in production

- **Proxies:** `TRUSTED_PROXIES` = the `app` subnet and `utschoolerp_internal`
  (172.18.0.0/16, where School ERP's Nginx container is). The app then reads only
  `X-Forwarded-For` and `X-Forwarded-Proto` from it (`App\Support\TrustedProxies`);
  `X-Forwarded-Host` is never trusted because the Host header selects the store.
  The site file *sets* both headers itself, so a visitor cannot supply them.
- **HTTPS:** `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`. HSTS is sent
  with `includeSubDomains` for the shop host only (it never reaches School ERP
  unless ERP is a subdomain *of the shop host*).
- **Test providers** (payments, couriers, messaging) are off in production.
- **Two-step sign-in:** required for platform staff; optional for store owners
  (owner decision 2026-10-21).
- **Seeding:** `--first-install` runs `db:seed`, which adds permissions,
  packages, themes and help articles only — no demo users or stores.
- **Super admins** (owner decision 16): after the two accounts register,
  `php artisan platform:staff <email> super_admin --operator=… --reason="Owner decision 16"`.

## 4. Approvals — every server action below needs the owner's yes

| # | Action | Risk to School ERP | Check | Undo |
|---|---|---|---|---|
| A1 | SSH in and run `preflight.sh` (read-only) | None (reads only) | Output reviewed | — |
| A2 | Create `/opt/umartechy-ecommerce` (deploy user), `git clone`, checkout the release tag | None | `ls`, `git log -1` | `rm -rf` the folder |
| A3 | Write `.env` and `mysql.env` (mode 600) with secrets generated on the server | None | `deploy.sh` checks them | Delete files |
| A4 | DNS: A record for the shop subdomain → 2.29.20.203 | None | `dig +short` | Remove record |
| A5 | `deploy.sh --first-install` (build, start the project, migrate its own DB) | CPU/RAM during the build | Health on 127.0.0.1; ERP health and `docker ps` unchanged | `docker compose -p umartechy-ecom -f docker-compose.prod.yml stop` |
| A6 | Add `20-umartechy-shop.conf` (PHASE 1, HTTP) to `/opt/utschoolerp/deploy/nginx/conf.d`, then `docker exec utschoolerp-nginx-1 nginx -t` and `nginx -s reload` (graceful; never a restart) | Shared Nginx: a bad file could fail the reload — `nginx -t` first; the upstream is resolved per request, so the file never stops Nginx from starting | `https://erp.umartechy.com` answers as before; `http://<shop>` redirects | Remove the file, `nginx -t`, reload |
| A7 | Certificate for the shop subdomain only, through School ERP's own certbot setup (same webroot and certificate folder, so its daily renewal job renews it too); then PHASE 2 (HTTPS) of the file, `nginx -t`, reload | Shared Nginx reload; a new folder in its certificate store | `curl -I https://<shop>`; ERP certificate unchanged | Comment PHASE 2 out, reload; `certbot delete --cert-name <shop>` |
| A8 | Create the two super admins | None | Sign in with 2FA | `platform:staff` to revoke |
| A9 | Off-server backup copy and SMTP credentials (owner input) | None | Test mail, restore rehearsal | — |

Commands that are **never** run on this server: `docker system prune`,
`docker volume rm/prune`, `docker compose down` without `-p umartechy-ecom`,
any `-v` on `down`, a restart or recreate of any School ERP container, an edit
of any existing School ERP file (the only addition is the approved
`20-umartechy-shop.conf`), firewall or SSH changes.

## 5. Step by step (after approvals)

1. **Preflight (A1).** `bash deploy/production/preflight.sh <port> <subnet>`.
   Pick `ECOM_HTTP_PORT` and `ECOM_APP_SUBNET` it reports free. Confirm free
   RAM ≥ ~2 GB and disk ≥ ~15 GB, or lower the limits in `.env` (§6). Record
   School ERP's baseline: `docker ps` (names, status, uptime) and its URL.
2. **Folder and code (A2).** As the deploy user: clone into
   `/opt/umartechy-ecommerce`, `git checkout <release tag>`.
3. **Settings (A3).** Copy the two templates, fill every `CHANGE`, generate the
   secrets on the server (commands in the template), `chmod 600 .env mysql.env`.
   Keep an offline copy of `APP_KEY`, `BACKUP_ENCRYPTION_KEY` and the MySQL root
   password — without them encrypted data and backups cannot be read.
4. **DNS (A4).** A record for the shop subdomain.
5. **Start (A5).** `bash deploy/production/deploy.sh --first-install`. It builds,
   starts MySQL/Redis, migrates, seeds reference data, starts the app and
   checks `http://127.0.0.1:<port>/api/v1/public/health`.
   Then compare School ERP with the baseline (same containers, same uptime,
   URL answers).
6. **Nginx site (A6) and certificate (A7).** From
   `deploy/nginx/shop-in-proxy-container.conf.example` as
   `/opt/utschoolerp/deploy/nginx/conf.d/20-umartechy-shop.conf`: PHASE 1 (HTTP),
   `docker exec utschoolerp-nginx-1 nginx -t`, `nginx -s reload`; the certificate
   with School ERP's certbot (webroot `/var/www/certbot`, same certificate store,
   so `/etc/cron.d/utschoolerp-certbot-renew` renews it); PHASE 2 (HTTPS),
   `nginx -t`, reload. After each reload: School ERP URL, then the shop URL.
7. **Verify from outside.** `https://<shop>/api/v1/public/health` = ok; sign-in page
   loads with styling; register a test store; upload a product picture; place a
   cash-on-delivery order; Super Admin → Monitoring shows no errors.
   Confirm the client IP in the audit log is the visitor's, not 172.18.x.x or 172.30.x.x.
8. **Super admins (A8)** and **backups/mail (A9).**

Later updates: `git fetch && git checkout <new tag> && bash deploy/production/deploy.sh`
(dumps the database before migrating; keeps the previous images for rollback).

## 6. Resources (defaults; set in `.env`)

| Service | CPU | Memory |
|---|---|---|
| app (PHP-FPM, 12 children max) | 1.0 | 768M |
| worker | 0.5 | 512M |
| scheduler | 0.25 | 256M |
| shop-web | 0.5 | 128M |
| mysql (buffer pool 256M) | 1.0 | 1G |
| redis (maxmemory 192 MB) | 0.25 | 256M |
| **Total ceiling** | **3.5** | **≈ 2.9 GB** |

Typical use is well below the ceiling (~1.2–1.8 GB). Lower `MYSQL_MEMORY`,
`MYSQL_BUFFER_POOL`, `APP_MEMORY` and `PHP_FPM_MAX_CHILDREN` if `preflight.sh`
shows less free memory. Image builds need ~3 GB of disk and some CPU for a few
minutes; build outside ERP's busy hours.

## 7. Backups, logs, monitoring

- **Application backups** (built in): encrypted daily database backups in the
  storage volume, verified, with weekly restore rehearsals — Super Admin →
  Backups. The key (`BACKUP_ENCRYPTION_KEY`) is never stored with them.
- **Pre-deploy dumps:** `deploy.sh` writes `backups/pre-deploy-*.sql.gz` (600)
  before every migration.
- **Off-server copy (owner input):** an S3-compatible bucket (`BACKUP_DISK`), or
  a nightly copy of the storage volume's backups and `backups/` to another
  machine. Until then a server loss loses the backups too.
- **Logs:** `docker compose -p umartechy-ecom -f docker-compose.prod.yml logs -f app shop-web worker`;
  audit log in the `storage_logs` volume; the site's Nginx logs in
  `/var/log/nginx/umartechy-ecommerce.*`.
- **Monitoring:** `/api/v1/public/health` (database, Redis, storage) for an external
  uptime check; container health in `docker ps`; Super Admin → Monitoring
  (store health, failures, browser errors).

## 8. Rollback

- **Code:** `bash deploy/production/rollback.sh` (previous images; migrations
  are additive, so older code runs on the newer schema).
- **Data (rare):** stop `app worker scheduler`, restore the newest
  `backups/pre-deploy-*.sql.gz` into the project's MySQL
  (`gunzip -c … | docker compose -p umartechy-ecom -f docker-compose.prod.yml exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"'`),
  then `rollback.sh`.
- **Take the shop offline entirely:** remove its Nginx site symlink, `nginx -t`,
  reload; `docker compose -p umartechy-ecom -f docker-compose.prod.yml stop`.
  Volumes (data) stay.

## 9. Not part of the first launch

- Stores' own domains and `<slug>.PLATFORM_BASE_DOMAIN` subdomains: need
  wildcard DNS and a wildcard (DNS-01) certificate or per-domain certificates.
  Until then stores open at `https://<shop>/shop/<slug>`.
- Real payment, courier and WhatsApp/SMS providers: owner credentials,
  entered in the app, and each adapter checked in its sandbox first.
- Web push for store apps: VAPID keys.
