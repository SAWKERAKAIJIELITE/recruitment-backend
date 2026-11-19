# Deployment with Docker: `rec.1005.date`

This guide runs the recruitment backend on one Linux server with Docker Compose, at `https://rec.1005.date`. It is
the container form of [deployment.md](deployment.md) (the same layout, without installing PHP, nginx or PostgreSQL
on the server); that guide still explains the settings, the first day and the hardening in detail.

```
                                             ┌─ /        → the frontend's web build (in the web image)
 browsers / the EXE ─ HTTPS 443 ─▶ the platform's proxy ─ HTTP, WEB_PORT ─▶ web (Caddy) ┤
                                             └─ /api/…   → app (PHP-FPM 8.2, Laravel) ──▶ db (PostgreSQL 16)
                                                           scheduler (php artisan schedule:work, every minute) ──┘
```

| Container     | Image                               | What it does                                                                                                     |
| ------------- | ----------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `web`       | `caddy:2-alpine` + `public/`    | The frontend and `/api/…` passed to PHP, in plain HTTP on `WEB_PORT`, behind the platform's proxy. |
| `app`       | `php:8.2.35-fpm-bookworm` + code  | Laravel. On start: migrations, the catalogue on a new database, the caches.                                      |
| `scheduler` | the same image as `app`           | Laravel's scheduler: exam absences and deadlines, contract reminders, pruning. There is no queue worker.         |
| `db`        | `postgres:16-bookworm` + init script | The database. Not published on the server: only `app` and `scheduler` reach it.                              |

The files: [Dockerfile](../Dockerfile) (the `app` image), [deploy/docker/web.Dockerfile](../deploy/docker/web.Dockerfile)
(the `web` image), [docker-compose.yml](../docker-compose.yml), [.dockerignore](../.dockerignore)
and everything under [deploy/docker/](../deploy/docker).

**Two layouts.** The default is for a platform that has its own proxy on ports 80 and 443 (**Openship**): that
proxy holds the certificate of `rec.1005.date` and sends the requests to the `web` container's port (`WEB_PORT`,
8080). On a bare server with no proxy, add `docker-compose.standalone.yml` to every `docker compose` command:
Caddy then holds 80 and 443 itself and obtains a Let's Encrypt certificate (step 4).

> **Status of this guide.** The files were written and statically checked (shell syntax, the lock file against
> PHP 8.2, the image tags), but the machine they were written on had no Docker: the stack was **not** built or
> started there. Run step 5 locally first, then step 6's checks, before the real deployment.

## 1. Before you start

- **The server**: Linux (Ubuntu 24.04 LTS is fine), 2 cores and 4 GB at least, 40 GB of disk or more.
- **Docker Engine with the Compose plugin** (`docker compose version` answers 2.20 or newer):
  <https://docs.docker.com/engine/install/ubuntu/>. Use Docker Engine on Linux, not Docker Desktop: with Desktop
  the containers do not see the clients' real addresses, which the exam-hall check needs.
- **DNS**: an `A` record `rec.1005.date` → the server's public address (and `AAAA` only if the server really
  answers on IPv6). Check: `dig +short rec.1005.date`.
- **Ports 80 and 443** (TCP) open to the internet, for the proxy in front (or for Caddy in the standalone layout:
  the certificate is issued through them). `WEB_PORT` (8080) is for that proxy only: do not open it to the
  internet. PostgreSQL is not published.

  ```bash
  sudo ufw allow 22/tcp && sudo ufw allow 80,443/tcp && sudo ufw allow 443/udp && sudo ufw enable
  ```

- **The clock** right (chrony or systemd-timesyncd): tokens, login challenges and exam windows depend on it. The
  containers work in UTC; the organization's day is `REPORTS_TIMEZONE`.

## 2. The code

```bash
sudo mkdir -p /srv/recruitment && sudo chown "$USER" /srv/recruitment
git clone git@github.com:SAWKERAKAIJIELITE/recruitment-backend.git /srv/recruitment
cd /srv/recruitment
git checkout --detach v1.0.0        # a released tag, so you always know what runs
```

(The repository is private: a read-only deploy key, as in [deployment.md](deployment.md) step 5.1.)

## 3. The settings (`.env`)

```bash
cd /srv/recruitment
cp deploy/docker/env.example .env
chmod 600 .env

# Two passwords (letters and digits only, so nothing needs quoting):
openssl rand -hex 24      # → DB_PASSWORD
openssl rand -hex 24      # → DB_SUPERUSER_PASSWORD
nano .env
```

Fill the values marked «change»:

| Setting                      | Value                                                                                                                                                    |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WEB_PORT`                 | The server's port that reaches the `web` container (8080; never 80 or 443, which the proxy holds). In the platform, point the domain `rec.1005.date` at port 8080 of `web`. Another number if 8080 is taken. |
| `API_DOCS_ENABLED`         | `true` serves the API's documentation page at `/docs/api`, to anyone who knows the address: for a test deployment. `false` (the default) in production. Set it on `app`, `scheduler` and `web`. |
| `TRUSTED_PROXIES`          | The private ranges (already set): the proxy in front is believed about the client's address and HTTPS. Empty in the standalone layout.                 |
| `APP_URL`                  | `https://rec.1005.date` (already set).                                                                                                                 |
| `DB_PASSWORD`              | The application's database password. Used when the database is first created: decide it **before** the first start.                                |
| `DB_SUPERUSER_PASSWORD`    | The `postgres` superuser's password (administration and backups).                                                                                      |
| `EXAMS_CANDIDATE_NETWORKS` | The exam halls' addresses **as the server sees them**: their public addresses when the halls come over the internet. Empty refuses every candidate. |
| `APP_KEY`                  | Below.                                                                                                                                                   |

**The frontend.** The `web` image serves whatever is in `deploy/docker/frontend` when it is built: a placeholder
page in the repository. To serve the real frontend, copy its web build there (`index.html` and its assets) before
`docker compose build`. Nothing is mounted from the server's folders: every container uses its image and named
volumes only.

Build the images, then make the key and paste it into `.env` (`APP_KEY=base64:…`). Set it once and keep it:

```bash
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show
nano .env
```

The other settings are explained in the template and in [deployment.md](deployment.md) step 6. `DB_HOST` and
`DB_PORT` are set by `docker-compose.yml`. **After every change in `.env`**: `docker compose up -d` (the
containers are made again and read the new values; there is no `config:cache` to run by hand).

## 4. Start

```bash
docker compose up -d
docker compose ps                    # db and app «healthy», scheduler and web «running»
docker compose logs -f app           # the first start: migrations, the catalogue, the caches, then «ready to handle connections»
```

What happens on the first start:

1. `db` creates the role `recruitment_app` (not a superuser) and its database `recruitment` (UTF-8, `C.UTF-8`).
2. `app` runs `php artisan migrate --force`, then, because the database is new, `php artisan db:seed --force` (the
   catalogue only: roles, permissions, reference lists; never demo data outside `APP_ENV=local`), then
   `php artisan optimize`, then PHP-FPM.
3. `scheduler` and `web` start once `app` is healthy; `web` answers in plain HTTP on `WEB_PORT`
   (`curl http://127.0.0.1:8080/api/v1/ping` on the server).

**On Openship**: `APP_KEY`, `DB_PASSWORD` and `DB_SUPERUSER_PASSWORD` go into the environment variables (there is
no `.env` in the repository); every other setting has its default in `docker-compose.yml` and is set only to
change it. A key without the containers, in PowerShell:
`"base64:" + [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))`.
The domain `rec.1005.date` is attached to the `web` service, port `WEB_PORT`.
Openship's proxy holds ports 80 and 443 and the certificate.

**On a bare server with no proxy** (the standalone layout): set `SITE_ADDRESS=rec.1005.date` and
`TRUSTED_PROXIES=` (empty) in `.env`, and add the second file to every command:

```bash
docker compose -f docker-compose.yml -f docker-compose.standalone.yml up -d --build
```

Caddy then listens on 80 and 443 and obtains the certificate (`docker compose logs web` shows «certificate
obtained successfully»).

On later starts `app` only applies new migrations and rebuilds the caches. `AUTO_MIGRATE=false` in `.env` turns the
automatic migration off (then: `docker compose exec app php artisan migrate --force`).

> **Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:wipe`** on this database, and never
> `docker compose down -v`: `-v` deletes the volumes, which are the database, the stored files and the certificates.

## 5. A local test first (optional, recommended)

On any machine with Docker, the same stack in plain HTTP on `http://localhost:8080`:

```bash
cp deploy/docker/env.example .env
# in .env: APP_URL=http://localhost:8080, the two passwords, APP_KEY (step 3)
docker compose up -d --build
bash deploy/docker/check.sh http://localhost:8080
```

## 6. Check it, one step at a time

```bash
cd /srv/recruitment
bash deploy/docker/check.sh
```

It prints one line per check («ok» or «FAIL») and ends with «All checks passed.»:

| Group              | Checks                                                                                                                                              |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. The containers  | `db`, `app`, `scheduler`, `web` running; `db` and `app` healthy.                                                                            |
| 2. The application | `APP_ENV=production`, `APP_DEBUG=false`; PHP 8.2 with its extensions; no pending migration; the catalogue seeded; the scheduler's jobs; storage writable. |
| 3. The site        | `/api/v1/ping` 200; `/api/v1/staff/auth/me` 401; `/` 200; `.env` and `/docs` not served; the security headers; HSTS; HTTP → HTTPS.                |

By hand, the same things:

```bash
curl -fsS https://rec.1005.date/api/v1/ping                                 # {"status":"ok",…}
curl -s   https://rec.1005.date/api/v1/staff/auth/me -H 'Accept: application/json'   # 401, an Arabic message
curl -sI  http://rec.1005.date/ | head -1                                   # 308 to https
docker compose exec app php -v                                              # PHP 8.2.35
docker compose exec app php artisan about
docker compose exec app php artisan migrate:status
docker compose exec scheduler php artisan schedule:list                     # the four jobs
```

## 7. The first administrator

A new installation has no user. See **[admin-accounts.md](admin-accounts.md)**; in short:

```bash
docker compose exec app php artisan staff:create-admin it.admin \
    --first-name="مسؤول" --last-name="النظام" --personal-number=100001
```

then register the administrator's PC from the EXE (API address `https://rec.1005.date/api/v1`) and approve it with
`staff:approve-device`.

## 8. Backups and restore

`deploy/docker/backup.sh` writes to `/var/backups/recruitment` (`BACKUP_DIR`), while everything runs:
`db-<time>.dump` (the database), `files-<time>.tar.gz` (`storage/app`: imports, documents, vacancy files) and
`env-<time>` (`.env`, with `APP_KEY`). It checks that the dump and the archive can be read back and deletes the
ones older than 14 days (`KEEP_DAYS`).

```bash
sudo bash /srv/recruitment/deploy/docker/backup.sh
ls -l /var/backups/recruitment
```

Every night at 23:30 UTC (02:30 in Damascus), in `/etc/cron.d/recruitment-backup`:

```
30 23 * * * root bash /srv/recruitment/deploy/docker/backup.sh >> /var/log/recruitment-backup.log 2>&1
```

Copy the backups off the server every day; they contain personal data. The database and the files belong together:
restore both from the **same** night.

**Restore** (same version of the code):

```bash
STAMP=20261112-233001
cd /srv/recruitment
docker compose stop web scheduler app

# The database: emptied and filled again from the dump.
docker compose exec -T db sh -c 'pg_restore --username postgres --clean --if-exists --no-owner \
    --role="$APP_DB_USER" --exit-on-error --dbname="$APP_DB_NAME"' < /var/backups/recruitment/db-$STAMP.dump

# The files (into the storage-app volume), owned by PHP's user.
docker compose run --rm --no-deps --user root --entrypoint sh -T app -c \
    'rm -rf storage/app/* && tar -xzf - -C /var/www/recruitment && chown -R www-data:www-data storage/app' \
    < /var/backups/recruitment/files-$STAMP.tar.gz

docker compose up -d
```

On a new server: steps 1 to 3 with the saved `env-<time>` as `.env`, `docker compose up -d db` only, then the
restore above. Test a restore every few months.

## 9. Updates and rollback

```bash
cd /srv/recruitment
sudo bash deploy/docker/backup.sh
git fetch --tags origin && git checkout --detach v1.1.0
docker compose build                 # the old containers keep serving meanwhile
docker compose up -d                 # new containers: migrations, caches, then live (a few seconds of 502)
bash deploy/docker/check.sh
docker image prune -f                # the old images
```

Read the release notes first: a new setting goes into `.env` before `up`. Update in quiet hours, never during an
exam session.

**Rollback.** No migration ran (or none changed existing tables): `git checkout --detach v1.0.0`,
`docker compose build`, `docker compose up -d`. Otherwise go back to the old tag the same way with
`AUTO_MIGRATE=false`, and restore the backup taken before the update (step 8). Do not use `migrate:rollback` in
production unless the release notes say it is safe.

## 10. Day to day

| To                                   | Run (in `/srv/recruitment`)                                                         |
| ------------------------------------ | ------------------------------------------------------------------------------------- |
| See the state                        | `docker compose ps`                                                                 |
| Follow the logs                      | `docker compose logs -f app` (also `web`, `scheduler`, `db`)                    |
| Read the application's log           | `docker compose exec app sh -c 'tail -f storage/logs/laravel-$(date -u +%F).log'`  |
| Run an artisan command               | `docker compose exec app php artisan <command>`                                     |
| Open the database                    | `docker compose exec db psql -U postgres recruitment`                               |
| Restart after changing `.env`      | `docker compose up -d`                                                              |
| Stop / start everything              | `docker compose stop` / `docker compose up -d`                                    |
| Maintenance (the API answers 503)    | `docker compose stop app` is enough; `artisan down` lasts only until the container is made again. |

Where the data is (`docker volume ls`): `recruitment_db-data` (the database), `recruitment_storage-app` (the stored
files), `recruitment_storage-logs`, `recruitment_caddy-data` (the certificates), `recruitment_desktop-releases`
(the desktop app's updates, step 13). Docker's own container logs are
kept to 5 files of 10 MB each.

## 11. Differences from the server without Docker

- **PHP 8.2.35** instead of 8.3 (`composer.json` asks for `^8.2`; the build runs `composer check-platform-reqs`).
  Another version: `PHP_VERSION` at the top of the `Dockerfile`.
- **Caddy** instead of nginx. The limits are the same (34 MB uploads, 180 s).
  Only `/api/…` reaches PHP, so the documentation pages (`/docs`, `/docs/api`) and `/up` are not reachable;
  `API_DOCS_ENABLED=true` opens `/docs/api` (and its `/docs/api.json`) and nothing else.
  A request for `/.env` or any unknown path gets the frontend's `index.html` (a single-page app), never a backend file.
- **The settings** are the containers' environment (from `.env`), not a file inside the image: no `.env`, no key
  and no password is ever in an image.
- **PHP runs as `www-data`** in the containers; `open_basedir` is not set (the container is the boundary).
- **Client addresses.** Behind the platform's proxy, the client's address arrives in `X-Forwarded-For`. Caddy
  believes it from private addresses only (`trusted_proxies static private_ranges` in the Caddyfile), and Laravel
  from `TRUSTED_PROXIES` (the same ranges in the template); otherwise the hall check and the rate limits see the
  proxy. In the standalone layout `TRUSTED_PROXIES` is empty: Caddy sees the clients itself.
- **An internal name instead of a public one** (no Let's Encrypt): put the organization's certificate in the
  `web` container and add `tls /path/cert.pem /path/key.pem` to the site block of `deploy/docker/caddy/Caddyfile.standalone`, or
  use `tls internal` and install Caddy's root certificate on the PCs.

## 12. Troubleshooting

| What you see                                                        | Likely cause                                                                                   | What to do                                                                                                                                                                 |
| ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app` restarts, the log says «APP_KEY is empty»                  | Step 3 was skipped.                                                                            | Make the key, put it in `.env`, `docker compose up -d`.                                                                                                                |
| `set DB_PASSWORD in .env` when running compose                    | `.env` is missing or incomplete.                                                             | Step 3.                                                                                                                                                                    |
| `app` log: `password authentication failed`                     | `DB_PASSWORD` changed after the database was created.                                        | `docker compose exec db psql -U postgres -c "ALTER ROLE recruitment_app PASSWORD 'the-new-one'"`, then `docker compose up -d`.                                         |
| The browser warns about the certificate; `web` log: ACME errors   | DNS does not point here yet, or 80/443 are closed.                                             | Fix DNS or the firewall; `docker compose restart web`. Let's Encrypt limits repeated failures: fix first, then retry.                                                    |
| `web` does not start: «failed to bind host port … address already in use» | Another program holds that port: `WEB_PORT` (8080) is taken, or, in the standalone layout, a proxy or nginx holds 80/443. | Another `WEB_PORT`, then point the platform's domain at it. Standalone: it cannot share 80/443 with another proxy; use the default layout instead. |
| The domain answers 502/404 from the platform's proxy               | The domain is not attached to the `web` service's port.                                        | In the platform, send `rec.1005.date` to `web`, port `WEB_PORT`; on the server, `curl http://127.0.0.1:8080/api/v1/ping` must answer.                                    |
| **502** from Caddy                                            | `app` is starting (migrations) or down.                                                      | `docker compose ps`; `docker compose logs --tail=50 app`.                                                                                                              |
| **500**, JSON `{"message":"Server Error"}`                  | An exception.                                                                                  | The application's log (step 10).                                                                                                                                           |
| «Client sent an HTTP request to an HTTPS server» (400)             | The `web` container was listening with HTTPS behind the proxy (an older image, or the standalone layout used behind a proxy). | Deploy the current version: in the default layout Caddy speaks plain HTTP only.                                                                                            |
| **413** on an upload                                          | Above 34 MB.                                                                                   | Raise `max_size` (`deploy/docker/caddy/site.caddy`), `upload_max_filesize`/`post_max_size` (`deploy/docker/php/app.ini`) and `*_MAX_FILE_KILOBYTES` together; `docker compose up -d --build`. |
| A change in `.env` has no effect                                  | The containers still have the old environment.                                                 | `docker compose up -d` (not `restart`).                                                                                                                                |
| New code has no effect                                              | The image was not rebuilt.                                                                     | `docker compose build && docker compose up -d`.                                                                                                                          |
| Candidates are refused from the halls                               | Their address is not in `EXAMS_CANDIDATE_NETWORKS`, or the server does not see real addresses. | `docker compose logs web` shows the address seen (`remote_ip`); add its network. If every client shows a `172.…` address, Docker is hiding them (Docker Desktop, or `userland-proxy` with IPv6): use Docker Engine on Linux. |
| Booked candidates who never came stay "booked"                      | The scheduler is not running.                                                                  | `docker compose ps scheduler`; `docker compose logs scheduler`; once by hand: `docker compose exec app php artisan exams:sweep`.                                     |
| Every administrator is locked out                                   |                                                                                                | [admin-accounts.md](admin-accounts.md), «Locked out».                                                                                                                      |

The other rows of [deployment.md](deployment.md) step 18 (logins, midnight tokens, time) apply unchanged.

## 13. The desktop app's updates (`/desktop/`)

The installed desktop app updates itself (electron-updater) from `https://rec.1005.date/desktop/`: it reads
`latest.yml`, then downloads the installer and its `.blockmap` named in it. The `web` container serves these from
the `desktop-releases` volume, mounted read-only at `/srv/desktop`:

| Path                                   | Answer                                                                                     |
| -------------------------------------- | ------------------------------------------------------------------------------------------ |
| `/desktop/latest.yml`                  | The current manifest, `Cache-Control: no-store` (never cached by a browser or Cloudflare). |
| `/desktop/<installer>.exe`, `.blockmap` | The files, cached for a year (`immutable`): their names carry the version.               |
| `/desktop/` or a missing file          | **404** with `Cache-Control: no-store`. No listing, and never the frontend's `index.html`. |

### 13.1 Publishing a release: automatic

Nobody copies files to the server. A release published on GitHub reaches `/desktop/` by itself:

```
the desktop app's repository ── release v0.2.4 ──▶ the fork ──▶ desktop-sync (on the server) ──▶ /desktop/
 (BaraaJadaan/hiring-management)    mirror-releases.yml,         every 5 minutes: the fork's
                                    every 15 minutes             latest release, into the volume
```

1. **The fork copies the releases** (syncing a fork never does). Put
   [deploy/github/mirror-releases.yml](../deploy/github/mirror-releases.yml) in the fork as
   `.github/workflows/mirror-releases.yml`, on its default branch, and follow the three steps at the top of that
   file: enable Actions in the fork, add the `UPSTREAM_TOKEN` secret (a fine-grained token that reads the upstream
   repository, Contents read-only), check `UPSTREAM`. It copies the latest release, its notes and every file, as
   a draft first and published only once complete. **Actions → Mirror upstream releases → Run workflow** runs it
   at once.
2. **The server takes the fork's latest release.** The `desktop-sync` service
   ([deploy/docker/desktop-sync.sh](../deploy/docker/desktop-sync.sh)) looks every 5 minutes. In Openship, on the
   `desktop-sync` service (or in `.env`):

   | Setting                  | Value                                                                                              |
   | ------------------------ | -------------------------------------------------------------------------------------------------- |
   | `DESKTOP_RELEASES_REPO`  | The fork, `owner/name`. Empty: the service idles and `/desktop/` stays empty.                      |
   | `DESKTOP_RELEASES_TOKEN` | A fine-grained token: repository access the fork only, Contents read-only. Needed for a private fork. |
   | `DESKTOP_SYNC_INTERVAL`  | Seconds between two looks (300).                                                                   |
   | `DESKTOP_KEEP_DAYS`      | Days the files of older releases are kept after a new one (30), for clients mid-download.          |

   It downloads the installer and the `.blockmap` first, each under a hidden name, checks its size, and renames
   it; `latest.yml` comes **last**, so a client never reads a manifest naming an installer that is not there yet.
   Drafts and pre-releases are never taken. Its log (`docker compose logs desktop-sync`, or the service's Logs
   tab) shows each release: «release v0.2.4 is served».

The server can also read the upstream repository directly (`DESKTOP_RELEASES_REPO=BaraaJadaan/hiring-management`
with a token that reads it); then the fork's workflow is not needed.

Keep the tokens in GitHub's secrets and Openship's settings only, and give each one read access to one
repository. A token that ends stops the copying: the workflow's run and the service's log say so.

Rules for the desktop app's releases:

- **Never publish a different file under a name that was published**: browsers and Cloudflare keep an installer
  for a year. A new build is a new version number.
- **To withdraw a release**, delete it (or mark it a pre-release) in both the upstream repository and the fork: the
  previous one becomes the latest, and the server serves it again within minutes.
- The files are not in `backup.sh`: GitHub keeps them.

Check from any machine:

```bash
curl -sI https://rec.1005.date/desktop/latest.yml | grep -iE '^HTTP|cache-control|cf-cache-status'
#   HTTP/2 200, cache-control: no-store…, cf-cache-status: DYNAMIC or BYPASS (never HIT)
curl -s https://rec.1005.date/desktop/latest.yml                  # the manifest, not the frontend's HTML
curl -sI https://rec.1005.date/desktop/no-such-file | head -1      # 404
```

### 13.2 Publishing by hand (when GitHub cannot be used)

Over SSH, never through the site: the three files at the top of the `desktop-releases` volume, `latest.yml`
**last**. Set `DESKTOP_RELEASES_REPO` empty first, or `desktop-sync` puts GitHub's latest release back.

```bash
# On your PC: the release's three files, to a folder on the server.
scp "Kafaa-Setup-0.2.4.exe" "Kafaa-Setup-0.2.4.exe.blockmap" latest.yml admin@SERVER:/tmp/desktop-release/
```

```bash
# On the server. The volume's folder (under Openship the name has a prefix: check the listing).
docker volume ls | grep desktop-releases
DESKTOP_DIR="$(docker volume inspect -f '{{ .Mountpoint }}' NAME-FROM-THE-LISTING)"

cd /tmp/desktop-release
sudo install -m 644 *.exe *.blockmap "$DESKTOP_DIR/"
# latest.yml last, and at once: written under a hidden name (never served), then renamed.
sudo install -m 644 latest.yml "$DESKTOP_DIR/.latest.yml.new" && sudo mv "$DESKTOP_DIR/.latest.yml.new" "$DESKTOP_DIR/latest.yml"
rm -r /tmp/desktop-release
```

### 13.3 Cloudflare (`1005.date`)

The site is behind Cloudflare, which can cache and challenge what the app asks for. The app cannot answer a
challenge (a JavaScript or CAPTCHA page breaks the update), so in the Cloudflare dashboard of `1005.date`:

1. **No cache for the manifest.** Caddy sends `Cache-Control: no-store` (and `Cloudflare-CDN-Cache-Control`), which
   Cloudflare honours by default. To be explicit: **Caching → Cache Rules →** a rule *URI Path equals
   `/desktop/latest.yml`* (hostname `rec.1005.date`) → **Bypass cache**. The installers may be cached: they follow
   the year Caddy gives them.
2. **No challenge on `/desktop/`.** **Security → WAF → Custom rules →** a rule *URI Path starts with `/desktop/`*
   (hostname `rec.1005.date`) → action **Skip**, skipping the remaining custom rules, rate limiting rules, managed
   rules and Super Bot Fight Mode. Place it first.
3. **Bot Fight Mode** (the free plan's) cannot be skipped by a rule, as far as Cloudflare's documentation goes: if it
   is on, the app may be challenged. Turn it off (**Security → Bots**), or use Super Bot Fight Mode, which the rule
   above can skip. "I'm Under Attack" mode must stay off for the same reason.

Then check with `curl` as above: no `cf-mitigated: challenge` header, and the YAML itself in the body.

**After copying a release, if an installer still answers 404** with `cf-cache-status: HIT`, Cloudflare kept an
old 404 (before the fix of 18 November 2026 a 404 went out with the year-long cache). Purge it: **Caching →
Configuration → Purge Cache → Custom purge**, the installer's and the blockmap's full URLs (or, with a plan that
allows it, the prefix `rec.1005.date/desktop/`).

The checks of step 6 include `/desktop/`: a missing file and the folder itself must answer 404.
