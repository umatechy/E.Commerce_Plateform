# Running on Hostinger shared hosting (Business Web Hosting)

Status: **prepared, not yet run on a real account.** Everything marked "pilot"
below is unproven until the pilot passes. The Hetzner deployment
(`production.md`) stays live until the owner approves a move.

## Limits read from the owner's hPanel (2026-10-31)

| Item | Value |
|---|---|
| Plan | Business Web Hosting |
| Websites | 8 of 50 used: at most 42 more, one of them the shop's main address |
| Disk | 1.77 GB of 50 GB |
| Inodes | 78.92K of 600K |
| PHP | 8.3 available |
| SSH | Active |
| Disabled functions | `system, exec, shell_exec, passthru, mysql_list_dbs, ini_alter, dl, symlink, link, chgrp, leak, popen, apache_child_terminate, virtual, mb_send_mail` |
| Extensions off that the project needs | `sodium` (backup encryption) |

Settings are per website: those above are for `umartechy.com`. The shop's own
website must be checked itself; `php artisan hosting:preflight` does that.

`proc_open` is not on the list, and the scheduler and backups need it. Hostinger
documents it as disabled by default on newly created accounts, so if it appears
on the shop's website it has to be removed from the list.

Not yet known: the database engine and version.

A release (`build-release.sh`) measured 18.3 MB and 16,713 files, so one copy takes
about 17K inodes, and each kept release as many again.

## How it differs from the Docker deployment

| Docker | Here |
|---|---|
| Redis for cache, sessions, queue | The database (`CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION`) |
| Queue worker running always | The scheduler empties the queue each minute (`QUEUE_FROM_SCHEDULER=true`) |
| `schedule:work` | One cron entry, every minute (`deploy/hostinger/cron.sh`) |
| Rehearsal creates a database | A prepared empty database (`BACKUP_REHEARSAL_DATABASE`) |
| Caddy, on-demand TLS, own IP | hPanel issues the certificate for each website |

Emails and other queued work can wait up to about a minute. Checked locally on
Windows with MySQL 8 and no Redis: `schedule:run` started the worker, which
cleared 146 queued jobs within 20 seconds with none failed. That says the path
works, not how fast Hostinger will be.

## Each customer's domain

A customer's domain is a website in hPanel whose `public_html` points at the one
application, which picks the store from the host name:

1. In the store's Settings -> Domains, the customer proves ownership (TXT).
2. The team adds the domain as a website in hPanel, CDN off.
3. Over SSH: `rm -rf ~/domains/<domain>/public_html && ln -s ~/shop/current/public ~/domains/<domain>/public_html`
4. The customer points the A records at `SHOP_PUBLIC_IPV4`; the certificate
   follows and the platform marks the domain live.

The plan's parked domains are not an option (the limit table shows 2). Adding
websites through Hostinger's API is not established for shared plans.

## Security condition: this account also holds WordPress sites

Every website on the account runs as the same system user. A hole in any
WordPress plugin on the account can read `~/shop/shared/.env`: database
password, `APP_KEY`, backup key, customers' provider credentials. hPanel showed
**33 vulnerabilities** on `umartechy.com` on 2026-10-31.

**Owner decision 2026-10-31: the shop stays on this account**, against the advice
to give it an account of its own. So this is a condition of going live, not an
option: every WordPress site on the account is updated and unused plugins are
removed before real customers' data arrives here, and they are kept up to date.

The databases `u511003721_shop` and `u511003721_rehearsal` exist. hPanel lists
them under `umartechy.com`.

## Pilot

Run on a throw-away website and database. Hetzner and the WordPress sites are
not touched.

1. hPanel: add a test subdomain as a new website with PHP 8.3, tick `sodium`,
   CDN off. Make two databases, one named `..._rehearsal`, each with its user.
2. Build here and upload:
   `sh deploy/hostinger/build-release.sh`, then `scp -P <port> shop-<sha>.tar.gz <user>@<ip>:~/`
3. On the server: `mkdir -p ~/shop/shared && tar -xzf ~/shop-<sha>.tar.gz -C ~/shop/shared ./deploy/hostinger`,
   copy `deploy/hostinger/env.hostinger.example` to `~/shop/shared/.env`, and fill it in there.
4. `sh ~/shop/shared/deploy/hostinger/activate.sh ~/shop-<sha>.tar.gz`, then read
   every line of `hosting:preflight`.
5. Link `public_html` as in step 3 of "Each customer's domain".
6. hPanel -> Cron Jobs, every minute: `/bin/sh ~/shop/current/deploy/hostinger/cron.sh`

| Must be proven | How |
|---|---|
| The website serves the application through the link | Open the test address |
| Certificate on a second website with the same link | A second test domain |
| Cron and queue | A request-form email arrives within about two minutes |
| Scheduler works under the cron's PHP | `storage/logs/cron.log` |
| Backup with `--routines --events` under this account | `php artisan backups:run-scheduled`, or its scheduled run |
| Restore rehearsal | `php artisan backups:rehearse` passes all checks |
| The engine | Preflight. If MariaDB: the test suite on that engine |
| Speed | Pages under normal load, watched in hPanel usage |

RPO 24h and RTO 4h (owner decision of 2026-09-30) count only once a real backup
and restore have passed here.
