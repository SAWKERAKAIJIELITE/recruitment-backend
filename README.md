# Recruitment System: Backend Technical Documentation

Status: end of Step 2 (Foundation and Identity for staff). This file is updated at the end of every step.

---

## 1. Overview

Backend and database for the recruitment, exams and contracts management system described in the Analysis & Design Document v1.2. It is a Laravel REST API on PostgreSQL. Two kinds of client use it:

- **Staff** (hiring managers, administrators, general manager, system admin): a Windows EXE built with React + Electron.
- **Candidates**: a React web portal.

Staff and candidates are separate actors with separate tables, login pipelines and guards (D-005).

## 2. Module status

| Module                                      | Status  | Notes                                                                                   |
| ------------------------------------------- | ------- | --------------------------------------------------------------------------------------- |
| Foundation                                  | Done    | Project, PostgreSQL, module loader, tests on PostgreSQL                                 |
| Identity: staff accounts, RBAC, staff login | Done    | Device-bound login planned (D-017)                                                      |
| Identity: candidate accounts and login      | Planned | After the Recruitment core (D-016)                                                      |
| Recruitment                                 | Planned | Source offices, job titles, candidates, applications, nomination number, status history |
| Imports                                     | Planned | Excel batches, validation, duplicate detection, approval                                |
| Exams                                       | Planned | Subjects, parts, question bank, exam plans, scheduling, attempts, scoring               |
| Interviews                                  | Planned |                                                                                         |
| Contracts                                   | Planned |                                                                                         |
| Reports                                     | Planned |                                                                                         |
| Audit                                       | Planned |                                                                                         |
| Integration                                 | Planned | Outbox for the future "Mawarid" integration                                             |

## 3. Tech stack

- PHP 8.3+ with the `pdo_pgsql` extension
- Laravel (current skeleton with `bootstrap/providers.php`)
- PostgreSQL 17
- Laravel Sanctum (personal access tokens)
- PHPUnit through `php artisan test`

## 4. Local setup

```bash
composer install
cp .env.example .env            # then edit the values below
php artisan key:generate
docker compose up -d            # PostgreSQL, see docker-compose.yml
docker compose exec db psql -U postgres -c "CREATE DATABASE recruitment_test;"
php artisan migrate --seed
php artisan serve
php artisan test
```

Key `.env` values:

```dotenv
APP_LOCALE=ar
APP_FALLBACK_LOCALE=en
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=recruitment
DB_USERNAME=postgres
DB_PASSWORD=secret
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

Framework files that were changed from the Laravel defaults:

| File                                                              | Change                                                                                                                           |
| ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `bootstrap/providers.php`                                       | Registers `ModuleServiceProvider`                                                                                              |
| `bootstrap/app.php`                                             | Errors under `api/*` are always rendered as JSON; `UnescapedUnicodeJson` is appended to the `api` middleware group (D-018) |
| `app/Providers/AppServiceProvider.php`                          | Gate hook (D-012), login rate limiter (D-013), Scramble Bearer scheme (D-019)                                                    |
| `app/Http/Middleware/UnescapedUnicodeJson.php`                  | Keeps Arabic text unescaped in JSON responses (D-018)                                                                            |
| `config/database.php`                                           | `pgsql` connection has `'timezone' => 'UTC'` (D-004)                                                                         |
| `config/auth.php`                                               | Guards `web` (kept because Sanctum consults it) and `staff`; provider `staff_users`                                        |
| `config/sanctum.php`                                            | `expiration` = 720 minutes (placeholder)                                                                                       |
| `phpunit.xml`                                                   | Tests use PostgreSQL database `recruitment_test`                                                                               |
| `lang/ar/auth.php`                                              | Arabic login failure and throttle messages                                                                                       |
| `database/migrations/*_create_personal_access_tokens_table.php` | Published from Sanctum; `uuidMorphs('tokenable')` instead of `morphs`                                                        |
| `database/seeders/DatabaseSeeder.php`                           | Calls the Identity seeders (dev users only when `APP_ENV=local`)                                                               |

Removed from the skeleton: `app/Models/User.php`, `database/factories/UserFactory.php`, and the default users migration.

## 5. Architecture

### 5.1 Modular monolith (D-001)

One Laravel app. Each business area is a folder under `app/Modules`. `ModuleServiceProvider` scans every module and loads its `Database/Migrations` and `routes.php`. Laravel sorts migrations from all modules by filename timestamp, so a table that is referenced by a foreign key must have an earlier migration timestamp than the table that references it.

### 5.2 Request flow (D-010)

```
HTTP request
  → route (middleware: throttle, auth guard, can:permission)
  → FormRequest   (input validation)
  → Controller    (thin: passes validated input on, shapes the response)
  → Action        (business rules for one use case)
  → Eloquent models → PostgreSQL
  → Resource      (whitelist of fields returned)
```

There is no repository layer. Complex read queries (reports, search) will get dedicated query classes.

### 5.3 Identity module layout

```
app/Modules/Identity/
├── Actions/LoginStaff.php
├── Database/
│   ├── Factories/StaffUserFactory.php
│   ├── Migrations/
│   │   ├── 2026_10_13_100001_create_staff_users_table.php
│   │   ├── 2026_10_13_100002_create_roles_and_permissions_tables.php
│   │   └── 2026_10_13_100003_create_staff_access_tables.php
│   └── Seeders/
│       ├── RolesAndPermissionsSeeder.php
│       └── DevUsersSeeder.php
├── Http/
│   ├── Controllers/StaffAuthController.php
│   ├── Middleware/EnsureStaffIsActive.php
│   ├── Requests/LoginRequest.php
│   └── Resources/StaffUserResource.php
├── Models/
│   ├── StaffUser.php
│   ├── Role.php
│   └── Permission.php
└── routes.php
```

## 6. Conventions

- **Primary keys:** UUID (D-003). The readable nomination number is a separate column (later step).
- **Time:** `timestamptz` columns, application and database connection both in UTC (D-004). Display conversion happens in the client.
- **Numbers:** `DECIMAL`/`NUMERIC` for scores and weights, never float.
- **Foreign keys:** real foreign keys, all `RESTRICT`, no cascading deletes (D-007). People are deactivated, not deleted.
- **Constraints:** business invariants that can be expressed in PostgreSQL (CHECK, UNIQUE, partial indexes) are enforced there, not only in PHP.
- **Usernames:** lowercase `[a-z0-9._-]{3,50}` (D-008).
- **Responses:** every response body is shaped by a Resource class (D-011).
- **Authorization:** coarse capabilities through permission slugs (`area.action`); resource-level rules through Policies (D-012).
- **Errors:** login failures are deliberately generic (D-014).

## 7. Identity module

### 7.1 Actors and guards

| Actor     | Table                  | Model                | Guard              | Status  |
| --------- | ---------------------- | -------------------- | ------------------ | ------- |
| Staff     | `staff_users`        | `StaffUser`        | `auth:staff`     | Built   |
| Candidate | `candidate_accounts` | `CandidateAccount` | `auth:candidate` | Planned |

Both use Sanctum tokens stored in the polymorphic `personal_access_tokens` table. A guard's provider setting makes it accept only tokens owned by its own model (cross-guard test to be written with candidate accounts).

### 7.2 Database schema

**`staff_users`**

| Column                 | Type                      | Notes                                   |
| ---------------------- | ------------------------- | --------------------------------------- |
| id                     | uuid PK                   |                                         |
| username               | varchar(50) unique        | CHECK `^[a-z0-9._-]{3,50}$`           |
| full_name              | varchar(255)              |                                         |
| email                  | varchar(255) null, unique |                                         |
| password               | varchar(255)              | bcrypt/argon hash via `hashed` cast   |
| is_active              | boolean                   | default true                            |
| must_change_password   | boolean                   | default false; not yet enforced (D-015) |
| last_login_at          | timestamptz null          |                                         |
| created_at, updated_at | timestamptz               |                                         |

**`roles`**: id uuid PK, `slug` unique (50), `label_ar` (100), timestamps.

**`permissions`**: id uuid PK, `slug` unique (100), `label_ar` (150), timestamps.

**`permission_role`**: (permission_id, role_id) composite PK, both FKs `RESTRICT`.

**`role_staff_user`**: (role_id, staff_user_id) composite PK, both FKs `RESTRICT`.

**`permission_staff_user`**: per-user override.

| Column                 | Type                        | Notes                           |
| ---------------------- | --------------------------- | ------------------------------- |
| staff_user_id          | uuid FK                     | composite PK with permission_id |
| permission_id          | uuid FK                     |                                 |
| is_granted             | boolean                     | true = grant, false = deny      |
| granted_by             | uuid null FK → staff_users | who made the change             |
| created_at, updated_at | timestamptz                 |                                 |

**`personal_access_tokens`**: Sanctum's table with `tokenable_id` as uuid.

### 7.3 Permission catalogue and RBAC matrix

Source: analysis doc §02. `✓` = given by the role. `✓*` = not given by the role; may be granted to individual users. `—` = not available.

| Permission             | General manager | Recruitment staff | System admin |
| ---------------------- | :-------------: | :---------------: | :----------: |
| `reports.view`       |       ✓       |        ✓        |      ✓      |
| `imports.manage`     |       —       |        ✓        |      ✓      |
| `candidates.manage`  |       —       |        ✓        |      ✓      |
| `exam_bank.manage`   |       —       |        ✓*        |      ✓      |
| `attempts.extend`    |       ✓*       |        ✓*        |     ✓*     |
| `exceptions.approve` |       ✓*       |        ✓*        |     ✓*     |
| `interviews.manage`  |       —       |        ✓        |      ✓      |
| `contracts.manage`   |       —       |        ✓        |      ✓      |
| `users.manage`       |       —       |        —        |      ✓      |
| `audit.view`         |       ✓       |        —        |      ✓      |
| `audit.view_related` |       —       |        ✓        |      —      |

Candidates have no roles or permissions. Their access is the candidate guard plus policies limiting them to their own data.

Role slugs: `general_manager`, `recruitment_staff`, `system_admin`.

The matrix lives in `RolesAndPermissionsSeeder`. It is the source of truth: re-running the seeder re-syncs every role's permissions to it.

### 7.4 Effective permission resolution

```
effective = (permissions from all of the user's roles + direct grants) − direct denials
```

- Implemented in `StaffUser::permissionSlugs()`, cached per model instance for the duration of the request.
- `StaffUser::hasPermission($slug)` answers a single question.
- `Gate::before` (in `AppServiceProvider`) answers any ability named like a permission slug with `true` when the staff user holds it, otherwise `null`. It never returns `false`, so Policies still apply. Routes protect themselves with `->middleware('can:slug')`.
- There is no super-admin bypass.

### 7.5 Staff authentication API

Base path: `/api/v1/staff/auth`. Send `Accept: application/json`.

| Method | Path        | Auth             | Purpose                                           |
| ------ | ----------- | ---------------- | ------------------------------------------------- |
| POST   | `/login`  | none (throttled) | Exchange username and password for a Bearer token |
| POST   | `/logout` | `auth:staff`   | Revoke the current token                          |
| GET    | `/me`     | `auth:staff`   | Current user with roles and effective permissions |

**Login request**

```json
{ "username": "staff", "password": "password", "device_name": "optional label" }
```

**Login response (200)**

```json
{
  "token": "12|....",
  "token_type": "Bearer",
  "user": {
    "id": "uuid",
    "username": "staff",
    "full_name": "...",
    "email": null,
    "must_change_password": false,
    "roles": ["recruitment_staff"],
    "permissions": ["reports.view", "imports.manage"]
  }
}
```

**Errors**

| Status | Meaning                                                                                            |
| ------ | -------------------------------------------------------------------------------------------------- |
| 422    | Missing fields, or credentials wrong / account inactive (same message for all credential failures) |
| 429    | Rate limit exceeded                                                                                |
| 401    | Missing, invalid, expired or deactivated-owner token on protected routes                           |

Subsequent calls send `Authorization: Bearer <token>`.

The `permissions` list is for showing or hiding interface elements only. The server checks permissions on every request.

### 7.6 How login works (`LoginStaff` action)

1. Look up the staff user by lowercased, trimmed username.
2. If none exists, compute a throw-away hash so the response time is comparable, then fail.
3. Compare the password hash.
4. Fail if the password does not match or the account is inactive. All failures throw the same validation message (D-014).
5. Record `last_login_at` and create a Sanctum token.

Every authenticated staff route also runs `EnsureStaffIsActive`, so a deactivated account stops working immediately even if it holds a token that has not expired.

### 7.7 Rate limiting (D-013)

Limiter `staff-login` (applied as `throttle:staff-login`):

- 5 attempts per minute per username + IP
- 30 attempts per minute per IP

Behind a reverse proxy, configure trusted proxies in production; otherwise all clients share the proxy's IP and the per-IP cap affects everyone.

### 7.8 Seeding

| Seeder                        | Runs                   | Purpose                                                                                       |
| ----------------------------- | ---------------------- | --------------------------------------------------------------------------------------------- |
| `RolesAndPermissionsSeeder` | Every environment      | Creates permissions and roles from the matrix. Idempotent.                                    |
| `DevUsersSeeder`            | `APP_ENV=local` only | Creates `admin`, `manager`, `staff` (password `password`) and example per-user grants |

Production: run migrations, then
`php artisan db:seed --class="App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder" --force`
Re-run it whenever the matrix changes.

### 7.9 API documentation (Scramble)

Scramble generates the OpenAPI document from routes, FormRequests and Resources (D-019). The docs UI is available in the local environment.

- A Bearer security scheme is declared in `AppServiceProvider::boot()`, so the UI shows an Authorization control.
- Public endpoints (for example login) are tagged `@unauthenticated` in the controller docblock; every other endpoint is documented as requiring the token.
- To try protected endpoints: call login, copy the `token` value, open Authorization, choose Bearer and paste the token without the word `Bearer`.

## 8. Testing

Run `php artisan test`. Tests use PostgreSQL (`recruitment_test`) with `RefreshDatabase`.

| Test file                                     | Covers                                                                                                                                       |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `Feature/ModuleLoadingTest`                 | Module routes are discovered                                                                                                                 |
| `Feature/DatabaseConventionsTest`           | Session time zone is UTC; stored timestamps agree with the database clock                                                                    |
| `Feature/Identity/StaffUserTest`            | Username CHECK constraint rejects blank, short, long, spaced and unsafe values; lowercasing                                                  |
| `Feature/Identity/StaffAuthTest`            | Login (case-insensitive username), wrong password, inactive account, rate limit, `me`, logout revokes token, deactivated user loses access |
| `Feature/Identity/PermissionResolutionTest` | Role permissions equal the matrix; ✓* permissions are never implicit; direct grant adds; direct denial removes; unknown abilities denied    |

## 9. Known limitations and pending work

- Device-bound staff login is designed but not built (D-017, open decisions OD-1 to OD-5).
- `must_change_password` is stored and returned but not enforced; there is no change-password endpoint yet (D-015).
- No user-management endpoints. Accounts are created by seeders only. Grant and role changes are not yet audited (Audit module comes first).
- Candidate accounts and login are not built (D-016).
- Token lifetime (720 minutes) is a placeholder; a user may hold several active tokens.
- Arabic translations exist only for login messages; validation messages fall back to English.
- Production needs trusted-proxy configuration for IP-based throttling.

## 10. Changelog

| Step          | Summary                                                                                                                                                                                                                                                                |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1             | Foundation: project, PostgreSQL, module loader, tests on PostgreSQL                                                                                                                                                                                                    |
| 2             | Identity & RBAC. Revised after review: separate staff and candidate tables (D-005); RESTRICT foreign keys (D-007); UTC connection (D-004); username constraint (D-008); `LoginStaff` action (D-010); two-level throttling (D-013); active-account middleware (D-014) |
| 2 (follow-up) | JSON responses keep Arabic text readable (D-018); API docs generated by Scramble with a Bearer scheme (D-019)                                                                                                                                                          |
