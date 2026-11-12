# Deployment guide: one on-premises server (VPS)

This guide installs the recruitment backend on a single Ubuntu 24.04 LTS server in the organization's own network:
nginx serves the frontend and the API on one HTTPS address, PHP-FPM runs Laravel, PostgreSQL 16 holds the data on
the same machine, and cron runs the scheduler and the nightly backup. The files under `deploy/` and the commands of
steps 2 to 17 were run on an Ubuntu 24.04 machine and the result checked end to end (step 17). That machine had no
systemd, so the service, firewall and clock commands (`systemctl`, `ufw`, `timedatectl`, chrony) were not exercised
there; they are Ubuntu's standard ones.

> **With Docker instead** (the deployment at `rec.1005.date`): [docker-deployment.md](docker-deployment.md) runs the
> same layout in containers (PHP 8.2, PostgreSQL 16, Caddy with an automatic certificate). Administrator accounts,
> for both kinds of installation: [admin-accounts.md](admin-accounts.md).

```
 staff PCs (the EXE)  ─┐                         ┌─ /            → /var/www/frontend   (the web build, if any)
 exam-hall PCs        ─┼─ HTTPS 443 ─▶  nginx  ──┤
 administrator (SSH)  ─┘                         └─ /api/…       → PHP-FPM (pool «recruitment») → Laravel
                                                                       │
                              cron ── every minute: php artisan schedule:run      PostgreSQL 16 (127.0.0.1:5432)
                                   └─ nightly: recruitment-backup → /var/backups/recruitment
```

Contents: [0 Before you start](#0-before-you-start) · [1 The server](#1-the-server) ·
[2 Packages](#2-packages) · [3 The system user](#3-the-system-user) · [4 PostgreSQL](#4-postgresql) ·
[5 The code](#5-the-code) · [6 The settings (.env)](#6-the-settings-env) · [7 Schema and catalogue](#7-the-schema-the-catalogue-and-the-caches) ·
[8 PHP-FPM](#8-php-fpm) · [9 nginx and TLS](#9-nginx-and-tls) · [10 Permissions](#10-permissions) ·
[11 The scheduler](#11-the-scheduler) · [12 First administrator and devices](#12-the-first-administrator-the-devices-and-the-first-day) ·
[13 Backups and restore](#13-backups-and-restore) · [14 Logs and monitoring](#14-logs-and-monitoring) ·
[15 Updates and rollback](#15-updates-and-rollback) · [16 Hardening](#16-hardening-checklist) ·
[17 Checks after installing](#17-checks-after-installing) · [18 Troubleshooting](#18-troubleshooting) ·
[19 Reference](#19-reference)

## 0. Before you start

**The server.** Ubuntu Server 24.04 LTS (a VM or physical machine), with a fixed address on the internal network.

|        | Minimum | Comfortable                                                                           |
| ------ | ------- | ------------------------------------------------------------------------------------- |
| CPU    | 2 cores | 4 cores                                                                               |
| Memory | 4 GB    | 8 GB                                                                                  |
| Disk   | 40 GB   | 100 GB+ (documents are stored as files, up to 10 MB each; backups need as much again) |

**Decide these values first.** The guide uses the examples below; replace them everywhere you see them.

| Example in this guide                 | What it is                                                                                                              |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `hr.example.local`                  | The address the staff and the exam halls open. Give it a record in the internal DNS (or the `hosts` file of each PC). |
| `10.20.30.0/24`                     | The exam halls' network(s), as the server sees them. Candidates can log in only from there.                             |
| `10.20.0.0/16`                      | The networks allowed to reach the server at all (staff and halls).                                                      |
| `10.20.1.0/24`                      | The administrators' network (SSH).                                                                                      |
| `/var/www/recruitment`              | Where the backend lives.                                                                                                |
| `recruitment`                       | The Linux user that owns the code and runs PHP.                                                                         |
| `recruitment` / `recruitment_app` | The database and its role (user).                                                                                       |

**What the server must reach.** During installation: the Ubuntu mirrors, `packagist.org` and `github.com` (for
Composer), unless you install offline (step 5.3). After installation: nothing outside the network; only an NTP server
(step 1) and, if you use one, the backup target.

Commands are run as an administrator with `sudo`, unless they start with `sudo -u recruitment` (or are run inside
`sudo -iu recruitment`).

## 1. The server

```bash
sudo apt update && sudo apt full-upgrade -y
sudo hostnamectl set-hostname hr-app

# The system clock stays UTC: the application stores UTC and converts to Asia/Damascus itself (REPORTS_TIMEZONE).
sudo timedatectl set-timezone UTC

# Time must be right: staff tokens end at midnight in Damascus, login challenges last 10 minutes, the exam's late
# window and deadlines are counted by the server. Use the organization's NTP server if there is no internet.
sudo apt install -y chrony
# Internal NTP only:  echo "server ntp.example.local iburst" | sudo tee /etc/chrony/sources.d/local.sources
sudo systemctl enable --now chrony
chronyc tracking            # "Leap status: Normal" and a small "System time" offset

# Security updates installed automatically (no reboots without you).
sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades
```

The firewall: SSH from the administrators only, HTTP and HTTPS from the internal networks, nothing else. PostgreSQL is
never opened (it listens on 127.0.0.1 only, step 4).

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow from 10.20.1.0/24 to any port 22 proto tcp
sudo ufw allow from 10.20.0.0/16 to any port 80,443 proto tcp
sudo ufw enable
sudo ufw status verbose
```

> Add the SSH rule before `ufw enable` if you are connected over SSH, or you lock yourself out.

## 2. Packages

Ubuntu 24.04 ships PHP 8.3, PostgreSQL 16 and nginx 1.24: everything comes from the standard archive.

```bash
sudo apt install -y \
  nginx \
  postgresql postgresql-contrib \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-intl php8.3-gd \
  php8.3-zip php8.3-curl php8.3-bcmath php8.3-opcache php8.3-readline \
  git unzip curl ca-certificates openssl
```

What needs what: `pgsql` the database; `mbstring`, `intl` Arabic text; `xml`, `zip`, `gd` reading and writing Excel
files (PhpSpreadsheet) and PDFs (mPDF); `curl` Composer. Check them:

```bash
php -v                                  # PHP 8.3.x
php -m | grep -Ei 'pdo_pgsql|mbstring|intl|gd|zip|xml|opcache'
```

Composer, checked against its published signature:

```bash
cd /tmp
EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
curl -fsSL https://getcomposer.org/installer -o composer-setup.php
ACTUAL="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
[ "$EXPECTED" = "$ACTUAL" ] && sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

Node.js is **not** needed on the server. It is used only by `tools/device-client.mjs` (Node 18+), a test client for the
device login; run it on any PC if you need it (step 12).

## 3. The system user

One user owns the backend's files and runs its PHP; nginx (`www-data`) only talks to PHP through a socket.

```bash
sudo useradd --system --create-home --home-dir /home/recruitment --shell /bin/bash recruitment
sudo mkdir -p /var/www/recruitment /var/www/frontend
sudo chown recruitment:recruitment /var/www/recruitment
```

## 4. PostgreSQL

The role owns its database and nothing else; it is not a superuser.

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE recruitment_app LOGIN PASSWORD 'change-this-long-password';
CREATE DATABASE recruitment OWNER recruitment_app ENCODING 'UTF8'
       LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0;
REVOKE ALL ON DATABASE recruitment FROM PUBLIC;
SQL
```

Use a long random password (`openssl rand -base64 30`) and keep it for step 6.

Notes:

- **Extensions.** The migrations create `btree_gist` themselves (`CREATE EXTENSION IF NOT EXISTS btree_gist`, used by the
  rules that keep interview sittings and interviews from overlapping). It is a *trusted* extension, so the database
  owner may create it; `postgresql-contrib` provides it. Nothing else is needed.
- **Listening.** Ubuntu's default is `listen_addresses = 'localhost'`: keep it. Check with
  `sudo -u postgres psql -c 'SHOW listen_addresses'` and `ss -ltnp | grep 5432` (only `127.0.0.1` and `::1`).
- **Authentication.** Ubuntu's `pg_hba.conf` already asks for a password (`scram-sha-256`) on `127.0.0.1`. The
  application connects over TCP to `127.0.0.1` with the password.
- **Collation.** `C.UTF-8` gives the same order on every machine; the application sorts Arabic names itself where it
  matters (normalized keys), so the database collation is not relied on.
- **Memory.** On a 4 GB server, these are reasonable (in `/etc/postgresql/16/main/conf.d/recruitment.conf`, then
  `sudo systemctl restart postgresql`):

  ```ini
  shared_buffers = 1GB
  effective_cache_size = 2GB
  work_mem = 16MB
  maintenance_work_mem = 256MB
  ```

Check the connection the application will use:

```bash
PGPASSWORD='change-this-long-password' psql -h 127.0.0.1 -U recruitment_app -d recruitment -c 'SELECT version();'
```

## 5. The code

### 5.1 From the repository

As the `recruitment` user. Use a **tag** (a released version) rather than a branch, so you always know what runs.

The repository is private: give the server a **read-only deploy key** (GitHub → the repository → Settings → Deploy
keys → Add, "Allow write access" left off) rather than a personal account:

```bash
sudo -u recruitment mkdir -p -m 700 /home/recruitment/.ssh
sudo -u recruitment ssh-keygen -t ed25519 -N '' -C hr-app -f /home/recruitment/.ssh/id_ed25519
sudo cat /home/recruitment/.ssh/id_ed25519.pub          # paste it as the deploy key
```

Then (the first connection asks to trust GitHub's host key: compare it with the fingerprints GitHub publishes, then
answer `yes`):

```bash
sudo -iu recruitment
git clone git@github.com:SAWKERAKAIJIELITE/recruitment-backend.git /var/www/recruitment
cd /var/www/recruitment
git checkout --detach v1.0.0            # the version to install: a tag (git tag --list) or a commit
composer install --no-dev --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev   # every line "success"
exit
```

Tag each version you deploy on the development side (`git tag v1.0.0`, then push the tag), so the server always runs
a named version and a rollback has somewhere to go back to.

Composer may print "Ambiguous class resolution" warnings about `League\Flysystem\Local`: they come from how that
package is published and are harmless.

### 5.2 Never development dependencies

`--no-dev` leaves out PHPUnit, Mockery and the other test tools (Faker and Collision stay: the API documentation
package Scribe requires them). The demo seeders and the development users are not run outside `APP_ENV=local`
(step 7), so no test data can reach production.

### 5.3 A server without internet

Build on a connected machine with the **same PHP version** (8.3), then copy:

```bash
# on the connected machine
git clone … recruitment-backend && cd recruitment-backend && git checkout --detach v1.0.0
composer install --no-dev --optimize-autoloader --no-interaction
cd .. && tar --exclude=recruitment-backend/.env -czf recruitment-v1.0.0.tar.gz recruitment-backend

# on the server
sudo tar -xzf recruitment-v1.0.0.tar.gz -C /var/www --transform 's/^recruitment-backend/recruitment/'
sudo chown -R recruitment:recruitment /var/www/recruitment
```

## 6. The settings (`.env`)

```bash
sudo -iu recruitment
cd /var/www/recruitment
cp deploy/env.production.example .env
chmod 640 .env
nano .env                                # the values marked «change»: APP_URL, DB_PASSWORD, EXAMS_CANDIDATE_NETWORKS
php artisan key:generate --force         # fills APP_KEY
exit
```

The template `deploy/env.production.example` explains every line. The ones that matter most:

| Setting                                                            | Value                             | Why                                                                                                                                                                                                                                                |
| ------------------------------------------------------------------ | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV`                                                        | `production`                    | Production behaviour; demo data refused; destructive commands ask for `--force`.                                                                                                                                                                 |
| `APP_DEBUG`                                                      | `false`                         | **Always.** With `true`, an error page shows code, queries and settings.                                                                                                                                                                   |
| `APP_KEY`                                                        | set by `key:generate`           | Laravel's key for what it encrypts or signs. Set it once and keep it: the backup script saves `.env` with the files.                                                                                                                             |
| `APP_URL`                                                        | `https://hr.example.local`      | The public address.                                                                                                                                                                                                                                |
| `DB_*`                                                           | step 4                            | `DB_HOST=127.0.0.1`.                                                                                                                                                                                                                             |
| `SESSION_DRIVER`                                                 | `file`                          | The API uses tokens and keeps no session; nothing is written per request. (`database` works too: the `sessions` table exists.)                                                                                                                 |
| `CACHE_STORE`                                                    | `database`                      | Rate limits and the scheduler's locks (the `cache` table exists).                                                                                                                                                                                |
| `QUEUE_CONNECTION`                                               | `sync`                          | Nothing is queued: no queue worker to run.                                                                                                                                                                                                         |
| `LOG_STACK`, `LOG_DAILY_DAYS`                                  | `daily`, `30`                 | One file a day in `storage/logs`, kept 30 days.                                                                                                                                                                                                  |
| `EXAMS_CANDIDATE_NETWORKS`                                       | the halls, e.g. `10.20.30.0/24` | **Candidates are refused everywhere else**, and everywhere when it is empty. Use the address the server sees (after any NAT).                                                                                                                |
| `TRUSTED_PROXIES`                                                | empty                             | Only when another proxy or load balancer sits **in front of** nginx: its address. Then the real client address (for the halls check and rate limits) is taken from `X-Forwarded-For`. Never `*` when the server can be reached directly. |
| `REPORTS_TIMEZONE`                                               | `Asia/Damascus`                 | The organization's day: the end of staff tokens (midnight), report periods, contract dates.                                                                                                                                                        |
| `REPORTS_ORGANIZATION`, `REPORTS_DEPARTMENT`, `REPORTS_LOGO` |                                   | The header of the printed reports.                                                                                                                                                                                                                 |
| `CONTRACTS_ISSUING_UNIT`                                         |                                   | Printed on the contract form.                                                                                                                                                                                                                      |
| `CORS_ALLOWED_ORIGINS`                                           | the frontend's address            | Used only when the frontend is served from **another** address than the API. In this guide both are on one address, so it does not matter; never leave `*` when it does.                                                                   |

`.env` is read once into a cache (step 7): **after every change**, run `php artisan config:cache` (as `recruitment`)
and `sudo systemctl reload php8.3-fpm`.

## 7. The schema, the catalogue and the caches

```bash
sudo -iu recruitment
cd /var/www/recruitment
php artisan migrate --force        # creates every table, trigger, constraint and the btree_gist extension
php artisan db:seed --force        # the catalogue only (below)
php artisan optimize               # caches: configuration, events, routes, views
php artisan about                  # Environment production, Debug Mode OFF, caches CACHED
exit
```

`db:seed` in production adds the **catalogue**, and nothing else: the roles and their permissions, the reference lists
(districts and areas, nominating entities, job positions, application categories, qualifications, specializations,
marital statuses, health conditions), the exam subjects, the document types, the contract types and the units. The
lists can be edited later from the application. The development users and the demo data are added only when
`APP_ENV=local`. Running `db:seed --force` again later is safe: it adds what is missing and leaves existing roles'
permissions as they are.

> **Never run `php artisan migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:wipe` on the production
> database.** They drop every table. The audit tables are append-only on purpose (triggers refuse updates and deletes),
> so nothing else is meant to remove data. From the first production installation on, schema changes arrive only as
> new migrations, applied by `php artisan migrate --force` (step 15).

## 8. PHP-FPM

A pool of its own: PHP runs as `recruitment`, listens on its own socket, with limits sized for imports and exports.

```bash
cd /var/www/recruitment
sudo cp deploy/php/recruitment-fpm.conf /etc/php/8.3/fpm/pool.d/recruitment.conf
sudo cp deploy/php/90-recruitment.ini   /etc/php/8.3/fpm/conf.d/90-recruitment.ini
sudo mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled   # the default pool is not used
sudo php-fpm8.3 -t                         # "configuration file … test is successful"
sudo systemctl restart php8.3-fpm
ls -l /run/php/recruitment.sock            # srw-rw---- www-data www-data
```

What is in them:

| Setting                                    | Value                                  | Why                                                                                                                                            |
| ------------------------------------------ | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `upload_max_filesize`, `post_max_size` | 32M, 34M                               | Candidate imports up to 30 MB (`IMPORTS_MAX_FILE_KILOBYTES`), documents 10 MB.                                                               |
| `memory_limit`                           | 512M                                   | Reading a 2,000-row workbook and writing large Excel or PDF reports.                                                                           |
| `max_execution_time`                     | 180                                    | The same long requests (nginx waits 180 s too).                                                                                                |
| `pm.max_children`                        | 20                                     | Each busy worker uses about 60–100 MB; lower it on a server with less than 4 GB.                                                              |
| `date.timezone`                          | UTC                                    | The application converts to `REPORTS_TIMEZONE` itself.                                                                                       |
| `open_basedir`                           | the app, `/tmp`, the time zone files | PHP cannot open files elsewhere.                                                                                                               |
| `opcache.validate_timestamps`            | 0                                      | The compiled code is kept until PHP is reloaded: **every update ends with `systemctl reload php8.3-fpm`** (the update script does it). |

## 9. nginx and TLS

### 9.1 The certificate

The staff PCs must trust the certificate, or the EXE and the browsers refuse the connection. Two ways:

**A. The organization's internal certificate authority (recommended).** Ask for a server certificate for
`hr.example.local` (Subject Alternative Name `DNS:hr.example.local`), and install it as
`/etc/ssl/recruitment/hr.crt` (with the intermediate certificates appended) and `/etc/ssl/recruitment/hr.key`. The
PCs already trust that authority.

**B. A small authority of your own**, when the organization has none. Create it once, keep its key offline, and
install its certificate (`recruitment-ca.crt`, not the key) on every staff and exam-hall PC as a trusted root:

```bash
sudo mkdir -p /etc/ssl/recruitment && cd /etc/ssl/recruitment

# The authority (10 years). Keep recruitment-ca.key somewhere safe and off the server afterwards.
sudo openssl req -x509 -new -nodes -newkey rsa:4096 -sha256 -days 3650 \
  -keyout recruitment-ca.key -out recruitment-ca.crt -subj "/CN=Recruitment internal CA"

# The server's certificate (2 years), signed by it.
sudo openssl req -new -nodes -newkey rsa:2048 -keyout hr.key -out hr.csr -subj "/CN=hr.example.local"
printf "subjectAltName=DNS:hr.example.local\nextendedKeyUsage=serverAuth\nkeyUsage=digitalSignature,keyEncipherment\n" | sudo tee hr.ext
sudo openssl x509 -req -in hr.csr -CA recruitment-ca.crt -CAkey recruitment-ca.key -CAcreateserial \
  -days 730 -sha256 -extfile hr.ext -out hr.crt

sudo chmod 600 hr.key recruitment-ca.key
openssl verify -CAfile recruitment-ca.crt hr.crt            # hr.crt: OK
```

On Windows PCs: double-click `recruitment-ca.crt` → Install Certificate → Local Machine → "Trusted Root Certification
Authorities" (or push it with a Group Policy). The Electron EXE uses the Windows certificate store.

Renew `hr.crt` before it expires: run the server certificate's commands again (`openssl req` and `openssl x509`) where
the authority's key is kept, put the new `hr.crt` and `hr.key` in place, then `sudo systemctl reload nginx`.
`openssl x509 -enddate -noout -in /etc/ssl/recruitment/hr.crt` shows the date.

### 9.2 The site

```bash
cd /var/www/recruitment
sudo cp deploy/nginx/recruitment.conf /etc/nginx/sites-available/recruitment
sudo nano /etc/nginx/sites-available/recruitment         # server_name (twice) and the certificate paths
sudo ln -s /etc/nginx/sites-available/recruitment /etc/nginx/sites-enabled/recruitment
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

How the site is laid out (`deploy/nginx/recruitment.conf`):

- Port 80 only redirects to 443.
- `/` is the frontend's web build in `/var/www/frontend` (a single-page app: unknown paths get its `index.html`).
  Copy the build there (`sudo cp -r dist/* /var/www/frontend/`); it must be readable by `www-data`. If the frontend is
  only the EXE, the folder can hold a one-line `index.html`.
- `/api/…` goes to Laravel. **Only** `public/index.php` is run, and nothing else of the backend's folder is served:
  `.env`, `storage`, `vendor` and the code are not reachable through nginx at all.
- Because the frontend and the API share one address, the browser makes no cross-origin requests (no CORS), and the
  download names (`Content-Disposition`) are readable by the frontend as they are.
- `client_max_body_size 34m` and `fastcgi_read_timeout 180s` match PHP's limits (step 8).
- Security headers: `nosniff`, `X-Frame-Options DENY`, `Referrer-Policy no-referrer`, HSTS; `server_tokens off`.

The frontend (and the EXE) are configured with the API's base address: `https://hr.example.local/api/v1`.

## 10. Permissions

| Path                                | Owner           | Mode                   | Why                                                                                                                                                        |
| ----------------------------------- | --------------- | ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/var/www/recruitment` (the code) | `recruitment` | files 644, folders 755 | Read by PHP.                                                                                                                                               |
| `.env`                            | `recruitment` | 640                    | Holds the key and the database password.                                                                                                                   |
| `storage/`, `bootstrap/cache/`  | `recruitment` | writable by the owner  | Logs, the file cache, compiled views, mPDF's temporary files, **and the stored files** (`storage/app/private`: imports, documents, vacancy files). |
| `/var/www/frontend`               | `root`        | files 644, folders 755 | Read by nginx (`www-data`).                                                                                                                              |
| `/run/php/recruitment.sock`       | `www-data`    | 660                    | Only nginx may talk to PHP.                                                                                                                                |
| `/etc/ssl/recruitment/hr.key`     | `root`        | 600                    | Read by nginx's master process.                                                                                                                            |

If something was copied as root, put it right with:

```bash
sudo chown -R recruitment:recruitment /var/www/recruitment
sudo find /var/www/recruitment -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +
sudo chmod 640 /var/www/recruitment/.env
sudo chmod +x /var/www/recruitment/artisan /var/www/recruitment/deploy/*.sh
```

The stored files live under `storage/app/private` and are served only by the API, after checking the user's
permission; never point nginx at `storage`. There is no `storage:link` to run: nothing uses the public disk.

## 11. The scheduler

Laravel's scheduler must run every minute. It does:

| Job                           | When (UTC)                | What                                                                                                                                                                                                                      |
| ----------------------------- | ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `exams:sweep`               | every minute              | Marks booked candidates absent when the late window closes, and closes attempts at their deadline. **Without it, absences wait until someone opens the session**, and the dashboard and queues are wrong meanwhile. |
| `contracts:remind-expiring` | 06:00 (09:00 in Damascus) | One notification for each contract ending within 14 days.                                                                                                                                                                 |
| `sanctum:prune-expired`     | 00:00                     | Deletes tokens a day after they expired.                                                                                                                                                                                  |
| `model:prune`               | 00:00                     | Deletes old device login challenges.                                                                                                                                                                                      |

```bash
sudo cp /var/www/recruitment/deploy/cron /etc/cron.d/recruitment
sudo chmod 644 /etc/cron.d/recruitment
sudo -u recruitment php /var/www/recruitment/artisan schedule:list     # the four jobs and when they run next
```

`/etc/cron.d/recruitment` also holds the nightly backup: install its script now too (step 13.1), or that line fails
every night. There is **no queue worker and no Supervisor** to
set up: nothing in the application is queued (`QUEUE_CONNECTION=sync`).

## 12. The first administrator, the devices and the first day

A new installation has no user. Staff log in only from an approved device (a key pair made by the EXE on the PC), so
the first administrator and the first device are set up from the server's shell; everybody else is added from the
application.

**1. Create the administrator** (the password is asked twice and never written on the command line):

```bash
sudo -iu recruitment
cd /var/www/recruitment
php artisan staff:create-admin it.admin --first-name="مسؤول" --last-name="النظام" --personal-number=100001
# Password (8 or more: upper and lower case letters, a digit, a symbol): ********
# The password again: ********
```

Usernames are Latin lower-case letters, dots, dashes and underscores. The command refuses to run before the catalogue
is seeded (step 7), asks before adding a second administrator, and is recorded in the audit log
(`access.user_created`, `via: console`). It is also the way back in if every administrator is locked out.

**2. Register the administrator's PC.** Open the EXE on that PC, point it at `https://hr.example.local/api/v1` and
register; it shows a **device id** and a **fingerprint** (8 groups of 4 characters). Without the EXE, the test
client does the same from any PC with Node 18+:

```bash
# On the PC (trusting the internal authority of step 9.1):
NODE_EXTRA_CA_CERTS=recruitment-ca.crt API_URL=https://hr.example.local/api/v1 \
  node tools/device-client.mjs register "IT office PC"
```

**3. Approve it from the server**, comparing the fingerprint with the one on the PC's screen (that comparison is what
makes it safe):

```bash
php artisan staff:approve-device <device-id> it.admin --fingerprint="31E2 565D 78CD 9B06 CA51 B19F E7B2 80B0"
```

**4. Log in** on that PC with the username and password. The token lasts until the next midnight in Damascus.

**5. From the application, as the administrator:**

1. Add the staff users, one office each (Recruitment, Exams, Interviews, Contracts, Documenting, the general manager);
   each one registers their own PC from the EXE, and the administrator approves it in the application (devices,
   comparing the fingerprint read out by the employee).
2. Upload the vacancies file for the current three months (`vacancy-plans`): without it, applications cannot be
   started.
3. The offices check their lists: job positions (and their job codes, by the Contracts office), nominating
   entities, contract types, units, document types.
4. The Exams office adds its questions and publishes an exam plan for each job position before the first session.

## 13. Backups and restore

### 13.1 Every night

`deploy/backup.sh` backs up the database named in the application's `.env` (`DB_DATABASE`) and writes, to
`/var/backups/recruitment`:

- `db-<time>.dump`: the whole database (`pg_dump` custom format, taken while the application runs);
- `files-<time>.tar.gz`: `storage/app` (the stored imports, documents, vacancy files) and `.env` (with `APP_KEY`).

It checks that both can be read back, and deletes the ones older than 14 days (`KEEP_DAYS`).

```bash
sudo install -m 750 /var/www/recruitment/deploy/backup.sh /usr/local/sbin/recruitment-backup
sudo recruitment-backup                                  # one now: "backup 20261112-064501 of recruitment: 2.1M database, 13M files"
ls -l /var/backups/recruitment
```

The cron file of step 11 runs it at 23:30 UTC (02:30 in Damascus) and logs to `/var/log/recruitment-backup.log`.

**Copy the backups off the server** every day (another server, a NAS, removable media): a backup on the same disk is
lost with the disk. For example, a second line in `/etc/cron.d/recruitment` pushing them, as root, to a backup machine
that accepts root's SSH key: `0 1 * * * root rsync -a /var/backups/recruitment/ backup@nas.example.local:/srv/backups/hr-app/`.
The files contain personal data: keep them where only administrators can read them.

The database and the files belong together: a document's row points at its file. Restore both from the **same**
night.

### 13.2 Restore

On the same server (or a new one installed up to step 9, with the same version of the code):

```bash
STAMP=20261112-064501                                    # the backup to restore
cd /var/www/recruitment
sudo -u recruitment php artisan down

# The database: emptied and filled again from the dump (a copy postgres can read; the backups are root's only).
sudo install -o postgres -m 600 /var/backups/recruitment/db-$STAMP.dump /tmp/restore.dump
sudo -u postgres pg_restore --clean --if-exists --no-owner --role=recruitment_app --exit-on-error \
     --dbname=recruitment /tmp/restore.dump
sudo rm /tmp/restore.dump

# The files and the settings.
sudo rm -rf storage/app
sudo tar -xzf /var/backups/recruitment/files-$STAMP.tar.gz -C /var/www/recruitment
sudo chown -R recruitment:recruitment storage .env && sudo chmod 640 .env

sudo -u recruitment php artisan optimize
sudo systemctl reload php8.3-fpm
sudo -u recruitment php artisan up
```

On a new server, create the role and an empty database first (step 4), then restore the same way. The restored `.env`
is the old server's: check `DB_PASSWORD` (the new role's) and `APP_URL` before `optimize`.

**Test a restore** every few months on another machine (or into a second database:
`sudo -u postgres createdb -O recruitment_app recruitment_check` and `--dbname=recruitment_check` above), and compare,
for example, `SELECT count(*) FROM applications;` in both. A backup that was never restored is a hope, not a backup.

## 14. Logs and monitoring

| Log                                               | Where                                                                | Kept                            |
| ------------------------------------------------- | -------------------------------------------------------------------- | ------------------------------- |
| The application (errors, logins, security events) | `/var/www/recruitment/storage/logs/laravel-<date>.log`             | 30 days (`LOG_DAILY_DAYS`)    |
| The audit log (who did what)                      | the `audit_events` table, read in the application                  | for ever                        |
| nginx                                             | `/var/log/nginx/recruitment.access.log`, `recruitment.error.log` | 14 days (Ubuntu's logrotate)    |
| PHP-FPM                                           | `/var/log/php8.3-fpm.log`                                          | logrotate                       |
| PostgreSQL                                        | `/var/log/postgresql/postgresql-16-main.log`                       | logrotate                       |
| The backup                                        | `/var/log/recruitment-backup.log`                                  | add it to logrotate if you like |

Watch:

- **Is it up?** `curl -fsS https://hr.example.local/api/v1/ping` answers `{"status":"ok",…}` with status 200 (any
  monitoring tool can poll it every minute). It shows nginx and PHP are up, not the database: `pg_isready -h 127.0.0.1`
  answers "accepting connections" when PostgreSQL is.
- **Errors:** `sudo -u recruitment tail -f /var/www/recruitment/storage/logs/laravel-$(date -u +%F).log`
  (`production.ERROR` lines).
- **The disk:** `df -h /var` (documents and backups grow).
- **The scheduler:** an exam session whose late window has passed still shows "booked" candidates → cron is not
  running (step 18).
- **Time:** `chronyc tracking`.

## 15. Updates and rollback

### 15.1 Updating

Read the release notes (and `docs/code-documentation.md`, the changelog) for new settings. Then:

```bash
sudo /var/www/recruitment/deploy/update.sh v1.1.0
```

The script (`deploy/update.sh`) does, in order:

1. a backup (`recruitment-backup`);
2. `php artisan down` (the API answers 503 with `Retry-After: 60` meanwhile);
3. `git fetch --tags` and `git checkout --detach v1.1.0`;
4. `composer install --no-dev --optimize-autoloader`;
5. `php artisan migrate --force` (new migrations only);
6. `php artisan optimize` (the caches, rebuilt from the new code and `.env`);
7. `systemctl reload php8.3-fpm` (OPcache cleared; requests in progress finish first);
8. `php artisan up`.

If a step fails, the script stops with the system still in maintenance mode: read the error, fix it and run the
script again, or roll back. If the new version needs a new setting, add it to `.env` before running the script.

Update during quiet hours, and never during an exam session.

### 15.2 Rolling back

When the new version misbehaves after its migrations ran, the safe way back is the backup taken in step 1 of the
update (its time is printed first):

```bash
cd /var/www/recruitment
sudo -u recruitment php artisan down
sudo -u recruitment -H bash -c 'git checkout --detach v1.0.0 && composer install --no-dev --optimize-autoloader --no-interaction'
# then restore that backup's database (13.2, the pg_restore line), and:
sudo -u recruitment php artisan optimize && sudo systemctl reload php8.3-fpm && sudo -u recruitment php artisan up
```

When no migration ran (or none changed existing tables), going back to the old tag with `composer install`,
`optimize` and the reload is enough. Do not use `migrate:rollback` in production unless the release notes say its
migrations can be undone safely: some changes cannot be undone without losing data.

### 15.3 A server without internet

`git fetch` and `composer install` need the network. Build the new version's archive as in step 5.3, copy it to the
server, and do the script's steps by hand:

```bash
sudo recruitment-backup
cd /var/www/recruitment && sudo -u recruitment php artisan down
sudo tar -xzf recruitment-v1.1.0.tar.gz -C /var/www --transform 's/^recruitment-backend/recruitment/'
sudo chown -R recruitment:recruitment /var/www/recruitment
sudo -u recruitment php artisan migrate --force
sudo -u recruitment php artisan optimize
sudo systemctl reload php8.3-fpm
sudo -u recruitment php artisan up
```

Unpacking over the old version keeps `.env` and the stored files (the archive has no `.env`, and only the empty folders
of `storage`), but leaves behind the files the new version deleted; they are harmless, and a fresh folder (moving
`.env` and `storage` across) avoids them.

## 16. Hardening checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (`php artisan about` shows them).
- [ ] `.env` mode 640, owned by `recruitment`; `APP_KEY` and `DB_PASSWORD` are not the examples.
- [ ] The firewall: 22 from the administrators only, 80/443 from the internal networks; PostgreSQL on `127.0.0.1` only.
- [ ] SSH with keys only (`PasswordAuthentication no` in `/etc/ssh/sshd_config.d/`), no root login.
- [ ] `EXAMS_CANDIDATE_NETWORKS` lists only the halls; `TRUSTED_PROXIES` empty unless a proxy sits in front.
- [ ] nginx serves only `/` (the frontend) and `/api/…`; nothing else under `/var/www/recruitment` is served. Keep it
  so: the API documentation pages are outside `/api` on purpose (`/docs/api` from Scramble, which also refuses
  every environment but `local`; `/docs`, `/docs.openapi`, `/docs.postman` from Scribe, which do not check). Do not
  add a `location` that sends other paths to PHP.
- [ ] TLS only (port 80 redirects), with a certificate the PCs trust; the CA's key is not on the server.
- [ ] Unattended security upgrades on; reboot for kernel updates in a quiet hour.
- [ ] Backups copied off the server, and a restore tested.
- [ ] Only the administrators can read `/var/backups/recruitment` and `storage/logs` (personal data).
- [ ] The first administrator's device was approved by comparing the fingerprint on its screen.
- [ ] Staff passwords are personal; deactivate users who leave (their tokens stop working at once) and revoke the
  devices of PCs that are replaced.

## 17. Checks after installing

Quick checks from the server (or any PC that trusts the certificate). With your own authority (9.1 B), let the server
trust it first: `sudo cp /etc/ssl/recruitment/recruitment-ca.crt /usr/local/share/ca-certificates/ && sudo update-ca-certificates`.

```bash
H=https://hr.example.local
curl -fsS $H/api/v1/ping                                         # {"status":"ok","module":"identity"}
curl -s  $H/api/v1/staff/auth/me -H 'Accept: application/json'   # 401, an Arabic message
curl -sI $H/.env | head -1                                       # 403
curl -sI $H/index.php | head -1                                  # 404 (PHP is only reached through /api)
curl -sI http://hr.example.local/ | head -1                      # 301 to https
curl -sI $H/api/v1/ping | grep -iE 'strict-transport|x-frame|x-content-type'
sudo -u recruitment php /var/www/recruitment/artisan about | grep -E 'Environment|Debug|Cached'
sudo -u recruitment php /var/www/recruitment/artisan schedule:list
```

**The whole lifecycle, on a staging server.** `tools/live-lifecycle.php` drives the complete process through HTTPS,
the way the offices do: the administrator's device registered and approved from the shell, a signed login, one user
per office added and their devices approved through the API, the vacancies file, the import of four candidates and
their start, the exam bank and a published plan, two exam sessions taken by the candidates (one absent, marked by the
real cron scheduler; one admitted by exception; one excused and examined again), an interview sitting (one absent,
excused and rebooked; one failed and closed), three contracts with the printed form and ملف التثبيت, a renewal and
an end, the dashboard, the Excel and PDF reports and the audit log; 98 checks in about six minutes, the
downloaded PDFs and workbooks kept in a temporary folder to look at.

It writes data: run it **only on a staging server** installed like production (steps 1 to 12, up to
`staff:create-admin` and nothing more), with the hall networks including the server itself
(`EXAMS_CANDIDATE_NETWORKS=127.0.0.1/32,…`) and cron running. It needs nothing beyond the installation itself; run it
as root (it approves the administrator's device with `staff:approve-device`, as the `recruitment` user):

```bash
cd /var/www/recruitment
sudo php tools/live-lifecycle.php https://hr.example.local /var/www/recruitment it.admin 'the-admin-password' \
     /etc/ssl/recruitment/recruitment-ca.crt
# 1. The administrator's device: registered, approved from the shell, then a signed login
#   ok   the server answers at https://hr.example.local/api/v1
#   …
# 98 checks, 0 failed. The downloaded files: /tmp/live-lifecycle-20261112-061402
```

A second run needs a fresh staging database (the same people would be nominated twice).

## 18. Troubleshooting

| What you see                                                                                              | Likely cause                                                                                                                                                                                   | What to do                                                                                                                                             |
| --------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **502 Bad Gateway**                                                                                 | PHP-FPM is down, or nginx cannot use the socket.                                                                                                                                               | `sudo systemctl status php8.3-fpm`; `ls -l /run/php/recruitment.sock` (owner `www-data`); `sudo tail /var/log/nginx/recruitment.error.log`.    |
| **500**, JSON `{"message":"Server Error"}`                                                        | An exception.                                                                                                                                                                                  | The application log (step 14) has the error and its trace.                                                                                             |
| **413 Request Entity Too Large**                                                                    | The upload is above `client_max_body_size`.                                                                                                                                                  | Raise it in nginx together with PHP's `upload_max_filesize`/`post_max_size` and the application's `*_MAX_FILE_KILOBYTES`.                        |
| **504** on a large import or export                                                                 | Over 180 s.                                                                                                                                                                                    | Raise `fastcgi_read_timeout` and `max_execution_time` together, or split the file.                                                                 |
| A change in `.env` has no effect                                                                        | The configuration is cached.                                                                                                                                                                   | `sudo -u recruitment php artisan config:cache && sudo systemctl reload php8.3-fpm`.                                                                  |
| New code has no effect                                                                                    | OPcache keeps the old code (`validate_timestamps=0`).                                                                                                                                        | `sudo systemctl reload php8.3-fpm`.                                                                                                                  |
| `Permission denied` on `storage/…` in the log                                                        | Files created by root (a command run with `sudo` but not `-u recruitment`).                                                                                                                | `sudo chown -R recruitment:recruitment /var/www/recruitment/storage /var/www/recruitment/bootstrap/cache`.                                           |
| `SQLSTATE[08006] … Connection refused`                                                                 | PostgreSQL is down, or `DB_HOST`/`DB_PORT` are wrong.                                                                                                                                      | `sudo systemctl status postgresql`; `pg_lsclusters`.                                                                                               |
| `password authentication failed`                                                                        | `DB_PASSWORD` differs from the role's.                                                                                                                                                       | `ALTER ROLE recruitment_app PASSWORD '…';` then `.env`, `config:cache`, reload.                                                                 |
| Candidates get «لا يُدخل إلى الاختبار إلا من شبكة مكان الاختبار» | Their address is not in `EXAMS_CANDIDATE_NETWORKS`.                                                                                                                                          | Look up the address the server sees in `recruitment.access.log`; add its network; `config:cache`; reload. Behind a proxy, set `TRUSTED_PROXIES`. |
| Booked candidates who never came stay "booked"                                                            | The scheduler does not run.                                                                                                                                                                    | `cat /etc/cron.d/recruitment`; `sudo systemctl status cron`; run `sudo -u recruitment php artisan exams:sweep` once by hand to catch up.         |
| Every staff member is logged out at midnight                                                              | Expected: tokens end at the next midnight in `REPORTS_TIMEZONE`.                                                                                                                             | Log in again.                                                                                                                                          |
| A staff login always says «اسم المستخدم أو كلمة المرور غير صحيحة»        | One message for every failure, on purpose: the password, but also a device not approved (or approved for another user), a revoked device, an inactive user, a challenge older than 10 minutes. | The real reason is in the application log: `grep staff_login_failed storage/logs/laravel-*.log` (`reason`, `device_id`).                         |
| Exam times, absences or token ends are off by hours                                                       | The server's clock, or `REPORTS_TIMEZONE`.                                                                                                                                                   | `chronyc tracking`; the clock stays UTC (step 1), the organization's zone is `REPORTS_TIMEZONE`.                                                   |
| The EXE or a browser refuses the connection                                                               | The PC does not trust the certificate, or the name differs from the certificate's.                                                                                                             | Install the CA certificate (9.1); open the server by the name in the certificate.                                                                      |
| The maintenance page stays after an update                                                                | The update stopped part way.                                                                                                                                                                   | Read the script's output, fix, run it again; or `sudo -u recruitment php artisan up`.                                                                |
| Every administrator is locked out                                                                         |                                                                                                                                                                                                | `php artisan staff:create-admin <new.username>` (step 12) and approve a device for it.                                                               |

## 19. Reference

### 19.1 The files under `deploy/`

| File                                | Installed as                                            |
| ----------------------------------- | ------------------------------------------------------- |
| `deploy/env.production.example`   | `/var/www/recruitment/.env` (step 6)                  |
| `deploy/php/recruitment-fpm.conf` | `/etc/php/8.3/fpm/pool.d/recruitment.conf` (step 8)   |
| `deploy/php/90-recruitment.ini`   | `/etc/php/8.3/fpm/conf.d/90-recruitment.ini` (step 8) |
| `deploy/nginx/recruitment.conf`   | `/etc/nginx/sites-available/recruitment` (step 9)     |
| `deploy/cron`                     | `/etc/cron.d/recruitment` (steps 11 and 13)           |
| `deploy/backup.sh`                | `/usr/local/sbin/recruitment-backup` (step 13)        |
| `deploy/update.sh`                | run in place, as root (step 15)                         |

### 19.2 Commands

| Command                                                                        | What it does                                                                         |
| ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------ |
| `php artisan staff:create-admin <username>`                                  | A system administrator from the shell (the first one, or when locked out).           |
| `php artisan staff:approve-device <device-id> <username> --fingerprint="…"` | Approves a registered device for a user, after comparing the fingerprint.            |
| `php artisan exams:sweep`                                                    | What the scheduler runs every minute (absences, deadlines); harmless to run by hand. |
| `php artisan contracts:remind-expiring`                                      | The daily contract reminders.                                                        |
| `php artisan about`                                                          | Version, environment, drivers and caches.                                            |
| `php artisan optimize` / `optimize:clear`                                  | Builds / clears the caches.                                                          |
| `php artisan down --retry=60` / `up`                                       | Maintenance mode on / off.                                                           |
| `php artisan migrate:status`                                                 | Which migrations ran.                                                                |

All of them as the `recruitment` user, in `/var/www/recruitment`.
