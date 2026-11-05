# Recruitment System: Backend Technical Documentation

Status: Step 6 (Foundation, staff identity, RBAC, device-bound login, Recruitment with corrections and deactivation, Excel import of the real legacy template, seeders, exam subjects, versioned exam plans, question bank, scoring engine, documents). Updated at the end of every step.

Audience: developers joining or reviewing the backend.
Companion file: `decisions.md` explains *why* things are the way they are. Decision ids (D-0xx) refer to it.

---

## 1. Overview

Backend and database for the recruitment, exams and contracts management system described in the Analysis & Design Document v1.2. A Laravel REST API on PostgreSQL. Two kinds of client use it:

- **Staff** (hiring managers, administrators, general manager, system admin): a Windows EXE built with React + Electron. Staff log in only from approved devices.
- **Candidates**: a React web portal, no device binding. Exams are taken at the company's premises.

Staff and candidates are separate actors with separate tables, login pipelines and guards (D-005).

## 2. Module status

| Module                                                         | Status  | Notes                                                                                                                                                                                                  |
| -------------------------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Foundation                                                     | Done    | Project, PostgreSQL, module loader, tests on PostgreSQL                                                                                                                                                |
| Identity: staff accounts, RBAC, access management              | Done    | Roles, per-user overrides, management API (D-020)                                                                                                                                                      |
| Identity: device-bound staff login                             | Done    | Registration, approval, challenge/signature login (D-017)                                                                                                                                              |
| Identity: user management (create, reset password)             | Done    | Adding staff users (D-058); an administrator sets a new password and ends the user's sessions (D-071); roles follow the direct permissions (D-070). Deactivating staff: planned                         |
| Candidate login for exams                                      | Done    | National number and the session's password, a token per exam booking (D-072, section 10.8)                                                                                                            |
| Recruitment                                                    | Done    | Nine managed lists (areas inside districts), candidates, applications, nomination number, chained status history with database-maintained status, one live application per person, closing, corrections, deactivating people (section 8) |
| Imports                                                        | Done    | Legacy Excel template: upload, column detection, row checks, corrections and review, atomic approval, report (section 9)                                                                               |
| Exams                                                          | Done    | Subjects and parts, versioned exam plans (one session, parts marked by hand, easy/medium/hard), question bank, pass policy as versioned data (added through the API), exact scoring engine; sessions with drawn questions, bookings, candidate login, attempts with server time and saved answers, extensions, absences, hand marks, automatic results, excuses and exceptions (section 10) |
| Documents                                                      | Done    | Document types, uploads checked by content, download, archive, missing documents per application (section 11)                                                                                       |
| Interviews                                                     | Done    | Sittings (a committee, or one interviewer), duplicates refused, results entered by the committee moving the application, reschedules, cancellations, corrections, absence excuses, queue by staff priorities, bulk booking (section 12) |
| Contracts                                                      | Done    | First contract, renewals, corrections, end without renewal, expiry reminders (section 13)                                                                                                             |
| Notifications                                                  | Done    | In-system staff notifications (section 14)                                                                                                                                                             |
| Reports                                                        | Done    | Dashboard indicators; staff reports after the legacy workbook (applications, exams, interviews, the whole workbook) in Excel / PDF; the list for شعبة التجنيد; results for candidates (section 15). Exam marks are recorded now (section 10.9); adding them to the exams report is next |
| Audit                                                          | Started | `AuditTrail` seam only; events go to the application log until the table exists                                                                                                                      |
| Integration                                                    | Planned | Outbox for the future "Mawarid" integration                                                                                                                                                            |

## 3. Tech stack

- PHP 8.2+ with the `pdo_pgsql` and `mbstring` extensions
- Laravel, PostgreSQL 16/17, Laravel Sanctum (personal access tokens)
- Scramble (API documentation, Scalar renderer)
- PhpSpreadsheet (reading `.xlsx`/`.xls` imports, writing import reports)
- PostgreSQL features used: UUID, `macaddr`, CHECK constraints, partial unique indexes, composite foreign keys, triggers and a deferred constraint trigger (PL/pgSQL)
- PHPUnit through `php artisan test`
- Node 18+ only for the device test client (`tools/device-client.mjs`)

## 4. Local setup

```bash
composer install                # after pulling Step 4: composer update phpoffice/phpspreadsheet (updates composer.lock)
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
```

Framework files changed from the Laravel defaults:

| File                                                     | Change                                                                                                                    |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `bootstrap/providers.php`                              | Registers `ModuleServiceProvider`                                                                                       |
| `bootstrap/app.php`                                    | Errors under `api/*` rendered as JSON; `UnescapedUnicodeJson` appended to the `api` group (D-018)                   |
| `config/database.php`                                  | `pgsql` connection has `'timezone' => 'UTC'` (D-004)                                                                  |
| `config/auth.php`                                      | Guards `web` (kept for Sanctum), `staff` and `candidate` (exam login, D-072); providers `staff_users`, `candidates` |
| `config/sanctum.php`                                   | Global `expiration` is `null`: lifetime is set per token (D-009)                                                      |
| `config/staff_auth.php`                                | Challenge lifetime, maximum pending devices, staff token lifetime (one day, D-036)                                        |
| `config/recruitment.php`                               | Nomination number format                                                                                                  |
| `config/scramble.php`                                  | API documentation settings                                                                                                |
| `app/Providers/AppServiceProvider.php`                 | Gate hook (D-012), rate limiters (D-013), custom token model, Scramble Bearer scheme, mass-assignment guard (D-021)       |
| `phpunit.xml`                                          | Tests use PostgreSQL database `recruitment_test`                                                                        |
| `lang/ar/auth.php`, `lang/ar/pagination.php`         | Arabic login failure, throttle and pagination messages                                                                    |
| `lang/ar/recruitment.php`, `lang/en/recruitment.php` | Recruitment business-rule messages                                                                                        |
| `config/imports.php`                                   | Import header aliases, sheet name, row and size limits, storage disk (section 9.4)                                        |
| `lang/ar/imports.php`, `lang/en/imports.php`         | Import row and batch messages, status labels, report headers                                                              |
| `config/exams.php`                                     | Late window (`EXAMS_LATE_MINUTES`, 15), login before the start (`EXAMS_LOGIN_EARLY_MINUTES`, 60), candidate token lifetime (`EXAMS_CANDIDATE_TOKEN_MINUTES`, 360), largest extension (240) (D-072, D-073) |
| `config/contracts.php`, `config/reports.php`         | Contract reminder window (14 days); report header lines (`REPORTS_ORGANIZATION`, `REPORTS_DEPARTMENT`), logo (`REPORTS_LOGO`), الجهة العسكرية of the شعبة التجنيد list (`REPORTS_MILITARY_ENTITY`), time zone, row limits |
| `lang/*/interviews.php`, `contracts.php`, `reports.php` | Interview, contract and report messages                                                                                 |
| `lang/ar/validation.php`                               | Arabic validation messages and field names (without it, `APP_LOCALE=ar` showed raw keys such as `validation.boolean`) |

## 5. Architecture

### 5.1 Request flow (D-010)

```
HTTP request
  → route (middleware: throttle, auth guard, active check, can:permission)
  → FormRequest   (input validation)
  → Controller    (thin: passes validated input to an Action, returns a Resource)
  → Action/Service (business rules for one use case, one transaction)
  → Eloquent models → PostgreSQL
  → Resource      (whitelist of fields returned)
```

There is no repository layer. Complex read queries (reports, search) will get dedicated query classes.

### 5.2 Identity module layout

```
app/Modules/Identity/
├── Actions/            LoginStaff, StaffLoginData, IssueLoginChallenge, RegisterDevice,
│                       ApproveDevice, RevokeDevice, ManageRolePermissions, ManageStaffAccess
├── Console/            ApproveDeviceCommand  (php artisan staff:approve-device)
├── Database/           Factories, Migrations, Seeders
├── Enums/              DeviceStatus
├── Http/
│   ├── Controllers/    StaffAuthController, StaffDeviceController, PermissionController,
│   │                   RoleController, StaffUserController
│   ├── Middleware/     EnsureStaffIsActive, RequireAnyPermission
│   ├── Requests/       Login, Challenge, RegisterDevice, ApproveDevice, RevokeDevice,
│   │                   AddRolePermissions, SetUserPermission, AddUserRole
│   └── Resources/      StaffUser, StaffUserSummary, StaffUserAccess, StaffDevice,
│                       DeviceRegistration, DeviceStatus, Role, Permission
├── Models/             StaffUser, Role, Permission, StaffDevice, DeviceLoginChallenge, AccessToken
├── Support/            Username, DeviceCrypto, AccessInvariants, AccessLog
└── routes.php
```

## 6. Conventions

- **Primary keys:** UUID (D-003), except the managed lists, which use bigint auto-increment (D-034). The readable nomination number is a separate column.
- **Time:** `timestamptz` columns, application and database connection both UTC (D-004).
- **Numbers:** `DECIMAL`/`NUMERIC` for scores and weights, never float.
- **Foreign keys:** real foreign keys, all `RESTRICT`, no cascading deletes, no `ON UPDATE` actions (D-007). People are deactivated, not deleted.
- **Constraints:** business invariants that PostgreSQL can express (CHECK, UNIQUE, partial indexes) are enforced there, not only in PHP.
- **Mass assignment:** `$fillable` holds only caller-supplied columns; rule-controlled columns are set with `forceFill()` in Actions (D-021).
- **Responses:** Resource classes; literal status codes; return types on controller methods (D-019).
- **Authorization:** coarse capabilities through permission slugs (`area.action`); resource-level rules through Policies (D-012).
- **Errors:** login failures are deliberately generic (D-014).
- **Usernames:** Latin only, stored and compared trimmed and lowercase (D-035).
- **Digits typed in Arabic:** national numbers and phone numbers are normalised to ASCII digits before validation and storage (`NationalNumber`, `PhoneNumber`).
- **Messages:** business-rule messages go through `lang/*/recruitment.php` (`__('recruitment.key')`).

## 7. Identity module

### 7.1 Actors and guards

| Actor     | Table                  | Model                | Guard              | Status  |
| --------- | ---------------------- | -------------------- | ------------------ | ------- |
| Staff     | `staff_users`        | `StaffUser`        | `auth:staff`     | Built   |
| Candidate | `candidates`         | `Candidate` (Recruitment) | `auth:candidate` | Built: exam login only (D-072) |

Both use Sanctum tokens stored in the polymorphic `personal_access_tokens` table (custom model `AccessToken`, which adds `staff_device_id` for staff and `exam_booking_id` for candidates). A candidate has no password of their own: they log in with their national number and the password of the exam session they are booked into, and the token belongs to that booking (section 10.8). A token of one guard is refused by the other.

### 7.2 Database schema

**`staff_users`**: `id` uuid PK; `personal_number` unique; `username` varchar(50) unique, CHECK allows only `a-z . _ -`, 3 to 50 characters (D-035); `first_name`, `last_name` (CHECK: at least 3 characters, no digits); `password`; `is_active` (default true); `last_login_at` timestamptz; timestamps.

**`roles`**: `id`, `slug` unique, `label_ar`, timestamps.

**`permissions`**: `id`, `slug` unique, `label_ar`, `individual_only` boolean (true = can never be attached to a role), timestamps.

**`permission_role`**: composite-key link table, foreign keys `RESTRICT`.

**`role_staff_user`**: a user's roles: `automatic` (true when the system added the role because every permission of it is granted to the user directly, D-070), `note` (why), `assigned_by`, `created_at`.

**`permission_staff_user`**: per-user override. `is_granted` true = grant, false = deny; `granted_by` = the administrator who made the change; `note` when the system acted on it (a role removed because all its permissions are denied, D-070).

**`staff_devices`**

| Column                                | Notes                                                                       |
| ------------------------------------- | --------------------------------------------------------------------------- |
| id                                    | uuid PK                                                                     |
| label                                 | free text from the device                                                   |
| public_key                            | PEM, canonical form                                                         |
| fingerprint                           | sha256 hex of the key's DER bytes, unique                                   |
| reported_mac                          | PostgreSQL `macaddr`, required, informational only                        |
| os_info                               | free text                                                                   |
| status                                | `pending` / `approved` / `revoked` (varchar + CHECK), default pending |
| staff_user_id                         | the one owner; set at approval                                              |
| approved_by, approved_at              | null for console approvals (`approved_by`)                                |
| revoked_by, revoked_at, revoke_reason |                                                                             |
| last_seen_at                          | updated at each successful login                                            |

CHECK constraints: an approved device needs `approved_at` and `staff_user_id`; a revoked one needs `revoked_at`. A partial unique index allows at most one approved device per staff user (a revoked device frees the slot).

**`device_login_challenges`**: `id`, `staff_device_id`, `challenge`, `expires_at`, `used_at`, `created_at`. Expired rows are prunable (`model:prune`).

**`personal_access_tokens`**: Sanctum's table with `tokenable_id` as uuid and an extra nullable `staff_device_id`.

### 7.3 Permission catalogue and default RBAC matrix

Source: analysis doc §02. `✓` = given by the role by default. `✓*` = `individual_only`: never given by a role, granted per user. `—` = not given.

| Permission             | General manager | Recruitment staff | System admin |
| ---------------------- | :-------------: | :---------------: | :----------: |
| `reports.view`       |       ✓       |        ✓        |      ✓      |
| `imports.manage`     |       —       |        ✓        |      ✓      |
| `candidates.manage`  |       —       |        ✓        |      ✓      |
| `exam_bank.manage`   |       —       |  — (grantable)  |      ✓      |
| `exams.manage`       |       —       |        ✓        |      ✓      |
| `attempts.extend`    |       ✓*       |        ✓*        |     ✓*     |
| `exam_results.correct` |     ✓*       |        ✓*        |     ✓*     |
| `exceptions.approve` |       ✓*       |        ✓*        |     ✓*     |
| `interviews.correct` |       ✓*       |        ✓*        |     ✓*     |
| `pass_policies.manage` |     ✓*       |        ✓*        |     ✓*     |
| `applications.close` |       ✓*       |        ✓*        |     ✓*     |
| `imports.approve`    |       ✓*       |        ✓*        |     ✓*     |
| `interviews.manage`  |       —       |        ✓        |      ✓      |
| `contracts.manage`   |       —       |        ✓        |      ✓      |
| `users.manage`       |       —       |        —        |      ✓      |
| `users.add`          |       —       |        —        |      ✓      |
| `devices.approve`    |       —       |        —        |      ✓      |
| `audit.view`         |       ✓       |        —        |      ✓      |
| `audit.view_related` |       —       |        ✓        |      —      |
| `exams.take`         |       —       |        —        |      —      |

Role slugs: `general_manager`, `recruitment_staff`, `system_admin`. Candidates have no roles.

The seeder gives a role its defaults only when the role is first created; afterwards administrators own the data through the access-management API (D-020). `exams.manage` and `exam_results.correct` (Step 10) are added to an existing database by a migration of the Exams module (`exams.manage` to the recruitment staff and system administrator roles).

### 7.4 Effective permissions

```
effective = (permissions from all of the user's roles + direct grants) − direct denials
```

`StaffUser::permissionSlugs()` computes it (cached per instance for the request). `Gate::before` answers any ability named like a permission slug with `true` when the staff user holds it, otherwise `null` (never `false`), so Policies still apply. There is no super-admin bypass.

Roles follow the direct permissions (`AutomaticRoles`, D-070), checked after every change of a user's roles or overrides and, for everyone, after a role's permissions change:

| Change | Effect |
| ------ | ------ |
| The direct grants now hold every permission of a role the user does not have | the role is added, `automatic`, with a note; the grants stay direct |
| One of those grants is cleared or denied | the automatic role is removed |
| The direct denials now cover every permission of a role the user holds | the role is removed; the denials stay, each with a note |
| A role is given by hand | the user's grants and denials of its permissions are cleared: they come from the role (an automatic role becomes one given by hand) |
| An automatic role is taken away by hand | the grants it stood for are taken away too |

A role without permissions is never added or removed automatically. Each automatic change is logged (`access.role_added_automatically`, `access.role_removed_automatically`, `access.role_dropped_automatically`).

### 7.5 API reference

Base path: `/api/v1/staff`. Send `Accept: application/json`. Protected routes need `Authorization: Bearer <token>`. Interactive documentation is generated by Scramble (D-019).

**Public (device enrolment and login)**

| Method | Path                         | Throttle                             | Purpose                                                             |
| ------ | ---------------------------- | ------------------------------------ | ------------------------------------------------------------------- |
| POST   | `/devices/register`        | 20/hour per IP                       | Register a device. 201 new, 200 existing (idempotent by public key) |
| GET    | `/devices/{device}/status` | 30/min per IP                        | `{"status": "pending"}` for the EXE's waiting screen              |
| POST   | `/auth/challenge`          | 60/min per IP, 10/min per device     | Get a login challenge                                               |
| POST   | `/auth/login`              | 5/min per username+IP, 30/min per IP | Log in with a signature                                             |

**Authenticated staff**

| Method | Path             | Permission |
| ------ | ---------------- | ---------- |
| POST   | `/auth/logout` | none       |
| GET    | `/auth/me`     | none       |

**Device administration** (`devices.approve`)

| Method | Path                          | Purpose                                                                                             |
| ------ | ----------------------------- | --------------------------------------------------------------------------------------------------- |
| GET    | `/devices?status=pending`   | Paginated list                                                                                      |
| POST   | `/devices/{device}/approve` | Body `fingerprint`, `staff_user_id` (the user must be active and have no other approved device) |
| POST   | `/devices/{device}/revoke`  | Body `reason`; deletes the device's tokens                                                        |

**Access management** (`users.manage`)

| Method | Path                                       | Purpose                                                                     |
| ------ | ------------------------------------------ | --------------------------------------------------------------------------- |
| GET    | `/permissions`                           | Catalogue, with the `individual_only` flag                                |
| GET    | `/roles`                                 | Roles with their permissions                                                |
| POST   | `/roles/{role}/permissions`              | Body `permissions: [slug]`; `individual_only` permissions refused (422) |
| DELETE | `/roles/{role}/permissions/{permission}` | 404 if the role does not have it                                            |
| GET    | `/users`, `/users/{user}`              | List; detail with roles, `role_assignments` (`automatic`, `note`, `assigned_at`), overrides (with `note`), effective permissions |
| PUT    | `/users/{user}/password`                 | A forgotten password (D-071): body `password` + `password_confirmation` (same rules as a new account); deletes every token of the user (signed out everywhere); the device stays approved |
| POST   | `/users/{user}/roles`                    | Body `role`; clears the user's grants and denials of the role's permissions (D-070) |
| DELETE | `/users/{user}/roles/{role}`             | 404 if the user does not hold it; an automatic role takes its direct grants with it |
| POST   | `/users/{user}/permissions`              | Body `permission`, `effect` = `grant` or `deny`; may add or remove a role automatically (D-070) |
| DELETE | `/users/{user}/permissions/{permission}` | Clear the override; 404 if none                                             |
| POST   | `/new` (`users.add`)                    | Add a staff user with a first role (D-058): `username`, `first_name`, `last_name`, `personal_number` (4 to 50 digits), `is_active`, `role` (slug), `password` + `password_confirmation` (8+, upper and lower case, digit, symbol, not in known leaks). 201. Without `users.manage`, only a role whose permissions the caller holds (403) |

`{role}` and `{permission}` are slugs. Any change that would leave no active staff user able to manage users is rejected with 422 and rolled back. Changes are logged as `access.*` entries.

### 7.6 Device-bound login protocol (for the Electron team)

1. **Enrol once.** Generate an ECDSA P-256 key pair. Send the public key (SPKI PEM), a label, the MAC address (required) and OS info to `POST /devices/register`. Show the returned **fingerprint** to the employee; an administrator compares it and approves the device for one staff user. Poll `GET /devices/{id}/status` until `approved`.
2. **Log in.** Call `POST /auth/challenge {device_id}`. Build the payload:

```
payload   = "recruitment-staff-login-v1" \n challenge_id \n challenge \n device_id \n username
            (joined with "\n"; challenge exactly as returned)
signature = base64( ECDSA-P256-SHA256( payload as UTF-8 ) ), DER-encoded
```

   Send `device_id`, `challenge_id`, `signature`, `username`, `password` to `POST /auth/login`.

3. **Sign what you send.** The username is signed exactly as sent: trim it, do not change its case. The server lowercases it only for the lookup (D-035).
4. **DER, not raw.** Node's `crypto.sign` produces DER by default. WebCrypto's `subtle.sign` returns raw `r||s`, which must be converted to DER first.
5. **Challenges** are single use and short lived (`staff_auth.challenge_ttl_seconds`). Any failure returns the same generic 422; request a new challenge and retry.
6. The private key never leaves the device; store it encrypted with the OS per-user protection. See the limitation in D-017.

`tools/device-client.mjs` is a working reference client (register, status, login).

**Bootstrap and break-glass:** `php artisan staff:approve-device <device-id> <username> --fingerprint="XXXX XXXX ..."`.

### 7.7 Login internals (`LoginStaff`)

1. Consume the challenge atomically (one UPDATE: valid, unexpired, unused, bound to this device).
2. Load the device (must be approved) and verify the signature over the payload built from database values and the username as sent.
3. Only then look up the user by `Username::normalize()`; for an unknown user a throw-away hash is computed to equalise timing.
4. Check password, active flag and that the device belongs to this user.
5. Update `last_seen_at` / `last_login_at` and issue a token that expires after `staff_auth.token_lifetime_minutes` (one day, D-036), linked to the device.

Every failure writes a `staff_login_failed` log entry with the real reason (no username) and returns the same message. `EnsureStaffIsActive` rejects tokens of deactivated users on every request.

### 7.8 Seeding

| Seeder                        | Runs                   | Purpose                                                                                       |
| ----------------------------- | ---------------------- | --------------------------------------------------------------------------------------------- |
| `RolesAndPermissionsSeeder` | Every environment      | Upserts the permission catalogue; gives a role its defaults only when created                 |
| `DevUsersSeeder`            | `APP_ENV=local` only | Creates `admin`, `manager`, `staff` (password `password`) and example per-user grants |

Production: run migrations, then
`php artisan db:seed --class="App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder" --force`.

## 8. Recruitment module

### 8.1 What it does

Holds the managed lists, the people (candidates), their applications, the nomination number, and the application's status history. Later modules (Imports, Exams, Interviews, Contracts) call into it: `CreateApplication` opens an application and `ApplicationLifecycle::move()` changes its status. Nothing else may change a status; the database enforces that (D-033).

```
app/Modules/Recruitment/
├── Actions/       CreateApplication, ApplicationLifecycle, ManageReferenceData,
│                  UpdateCandidate, UpdateApplication, ChangeCandidateActivation (D-049)
├── Database/      Migrations (3), Seeders (one per list + ReferenceDataSeeder, demo seeders, section 8.9)
├── Enums/         ApplicationStatus, DecisionType, ReferenceType
├── Exceptions/    IllegalStatusTransition
├── Http/          Controllers (ReferenceData, Application, Candidate), Requests, Resources (+ ReferenceSummary helper)
├── Models/        Candidate, Application, ApplicationStatusEvent, Concerns/IsReferenceData,
│                  District, Area, NominatingEntity, JobPosition, ApplicationCategory,
│                  AcademicQualification, Specialization, MaritalStatus, HealthCondition
├── Contracts/     StatusChangeCause (implemented by records that cause a status change, D-038)
├── Support/       NominationNumberGenerator, NationalNumber, PhoneNumber, ApplicationRules (D-042),
│                  StatusEventCauseColumns (D-038)
└── routes.php
app/Support/ArabicText.php          shared Arabic helpers (search key, digits)
app/Modules/Audit/Support/AuditTrail.php
```

### 8.2 Database schema

**Managed lists** (D-031, D-034): `districts`, `nominating_entities`, `job_positions`, `application_categories`, `academic_qualifications`, `specializations`, `marital_statuses`, `health_conditions`, each with `id` bigint, `name_ar`, `name_key` (unique, normalised spelling), timestamps, `deleted_at` (archived).

**`areas`**: same shape plus `district_id` (FK `districts`). `name_key` is unique per district; `(district_id, id)` is unique so applications can reference the pair.

**`candidates`** (the person; facts that do not change between applications):

| Column                 | Notes                                                                        |
| ---------------------- | ---------------------------------------------------------------------------- |
| id                     | uuid PK                                                                      |
| national_number        | varchar(20) column, unique, CHECK 11 to 20 digits; stored as given (D-032) |
| full_name, mother_name | varchar(150), CHECK no digits (ASCII or Arabic-Indic), at least 3 characters |
| name_search_key        | `ArabicText::searchKey(full_name)`, indexed                                |
| birth_date             | date, CHECK after 1950-01-01                                                 |
| birth_place_id         | FK `districts`                                                             |
| is_active, notes       | inactive people cannot be nominated (D-049)                                  |
| deactivated_at, deactivated_by, deactivation_reason | who deactivated the person and why; CHECK: a reason whenever inactive, nothing when active |

**`nomination_counters`**: `year` PK, `last_value`.

**`applications`** (one nomination; everything declared at application time):

| Column                                                             | Notes                                                                                                         |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| id                                                                 | uuid PK                                                                                                       |
| nomination_number                                                  | unique                                                                                                        |
| candidate_id                                                       | FK `candidates`                                                                                             |
| nominating_entity_id, job_position_id, application_category_id     | FKs, required, indexed                                                                                        |
| existing_employee_number                                           | optional                                                                                                      |
| academic_qualification_id, specialization_id                       | FKs, required                                                                                                 |
| studying_years                                                     | optional, CHECK ≤ 15                                                                                         |
| studies_discontinued                                               | boolean, default false; CHECK: needs studying_years (D-044)                                                   |
| quran_parts_memorized                                              | optional, CHECK ≤ 30                                                                                         |
| marital_status_id, health_condition_id                             | FKs, required                                                                                                 |
| health_details                                                     | optional free text                                                                                            |
| residence_district_id, residence_area_id                           | district required; area optional (D-043); when set, the pair is a composite FK to `areas (district_id, id)` |
| residence_details                                                  | optional free text                                                                                            |
| phone_number                                                       | text, CHECK `^\+?[0-9]{6,15}$`                                                                              |
| works_for_other_government_entity, full_commitment, overnight_work | required booleans                                                                                             |
| notes                                                              | optional                                                                                                      |
| current_status                                                     | enum + CHECK; written only by the database (8.3)                                                              |
| created_by                                                         | FK `staff_users`                                                                                            |

Partial unique index: **one live application per person**, whatever the job position (live = not `contract_ended` / `closed`, D-037).

**`application_status_events`** (the history; append-only chain):

| Column            | Notes                                                                                                                                      |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| id                | uuid PK                                                                                                                                    |
| application_id    | FK `applications`                                                                                                                        |
| previous_event_id | unique; `(application_id, previous_event_id)` is a composite FK to `(application_id, id)` of this table; null only for the first event |
| to_status         | enum + CHECK                                                                                                                               |
| decision_type     | `created` / `outcome` / `exception` / `excuse_accepted` / `closure`                                                              |
| reason, actor_id  | required (non-blank reason) for `exception`, `excuse_accepted` and `closure`                                                         |
| occurred_at       | when the decision happened (D-039): not after `created_at`, not before the previous event's `occurred_at`                              |
| created_at        | when it was recorded                                                                                                                       |
| *cause columns* | added by later modules, one nullable FK per cause (`interview_id`, `contract_id`, `exam_booking_id`), at most one set per event (D-038, section 8.5) |

Constraints: one first event per application (partial unique index); only `created` has no previous event, and `created` always leads to `awaiting_exam`; `occurred_at <= created_at`.

### 8.3 Database triggers (D-033)

| Trigger                                                     | On                    | Effect                                                                                                    |
| ----------------------------------------------------------- | --------------------- | --------------------------------------------------------------------------------------------------------- |
| `application_status_events_append_only`, `_no_truncate` | events                | UPDATE, DELETE, TRUNCATE refused                                                                          |
| `application_status_events_follow_tail`                   | events, before insert | Locks the application; refuses an event that does not follow the latest event, or that is dated before it |
| `application_status_events_set_current_status`            | events, after insert  | Copies `to_status` into `applications.current_status`                                                 |
| `applications_current_status_guard`                       | applications          | A new application must be `awaiting_exam`; any other write to `current_status` is refused             |
| `applications_have_history` (deferred)                    | applications          | At commit, refuses an application that has no first event                                                 |

So `current_status` always equals the status of the last event, whatever code writes to the database. Other application columns can still be updated normally.

### 8.4 Status lifecycle (D-037)

```
awaiting_exam ──outcome──► awaiting_interview ──outcome──► awaiting_contract ──outcome──► contract_written ──outcome──► contract_ended
     │  │                        │  │
     │  └─outcome─► exam_absent   │  └─outcome─► interview_absent
     │                  │         │                    │
     │          excuse_accepted   │            excuse_accepted
     │                  ▼         │                    ▼
     │           awaiting_exam    │             awaiting_interview
     └─outcome─► exam_failed ─exception─► awaiting_interview
                                  └ interview_failed ◄─outcome─ awaiting_interview

Every status before contract_written ──closure (reason + actor, applications.close)──► closed
```

Terminal statuses: `contract_ended`, `closed`; everything else is live. `ApplicationLifecycle::move($application, $to, $type, actor:, reason:, cause:, occurredAt:)` locks the application row, reads its status from the database, refuses any move not in `ApplicationStatus::transitions()`, any wrong decision type, a person-made decision without reason and actor, and a date in the future or before the previous decision (`IllegalStatusTransition`). It then appends the event after the latest one and reloads the caller's model (the database has changed `current_status`). `open()` writes the first event inside the transaction that creates the application, without a savepoint of its own. `correct($application, $to, $cause, $actor, $reason)` replaces the latest outcome after its record was corrected (D-060): a new `correction` event, only while the outcome caused by `$cause` is still the latest decision, and only towards another outcome the same step could give (from the status before that outcome). `Application::history()` returns the events in chain order with each event's previous event attached, so `fromStatus()` costs no extra query.

### 8.5 Causes of a status change (D-038)

A module whose records cause moves (Exams, Interviews, Contracts) adds its column in its own migration and makes its model implement `StatusChangeCause`:

```php
// migration of the Exams module
StatusEventCauseColumns::add('exam_booking_id', 'exam_bookings');   // up(): nullable uuid FK, RESTRICT, index
StatusEventCauseColumns::remove('exam_booking_id');                 // down()

// model
class ExamBooking extends Model implements StatusChangeCause
{
    public static function statusEventColumn(): string { return 'exam_booking_id'; }
}

// when the result is decided (ExamResults)
$lifecycle->move($application, ApplicationStatus::ExamFailed, DecisionType::Outcome, cause: $booking, occurredAt: $attempt->submitted_at);
```

Every `add`/`remove` rebuilds the CHECK `application_status_events_at_most_one_cause` over all cause columns. The helper accepts only safe identifiers.

Cause columns in place: `interview_id` (Interviews, D-059), `contract_id` (Contracts, D-064), `exam_booking_id` (Exams, D-074: the booking, since an absence has no attempt).

### 8.6 Nomination number

`{YYYY}{SEQ}` by default, six-digit counter restarting each year (`config/recruitment.php`). `NominationNumberGenerator::next()` takes one number, `reserve($n)` takes `$n` consecutive numbers with one statement (import approval). Always inside the transaction that creates the applications, so a refused request uses up no number.

### 8.7 People, duplicates and concurrency

- A person is recognised by national number only, never by name. A matched person's stored data is kept: the `candidate` fields of a new application are ignored (`candidate_matched: true`). Application data (phone, residence, education, ...) is per application.
- **One live application per person** (D-037): 422 on `candidate.national_number` with `recruitment.live_application_exists` (names the live nomination number), and the partial unique index as the last guard. A person can be nominated again only after the contract ended or the application was closed.
- The national number is required, so every person can be matched (D-032).
- An inactive person cannot be nominated: 422 `recruitment.candidate_inactive` on `candidate.national_number`, and the import marks the row invalid (`candidate_inactive`). A person with a live application cannot be deactivated (D-049).
- `CreateApplication::lockPeople()` takes a transaction-level advisory lock per national number (sorted, so callers cannot deadlock). The API create and the import approval both take it, so a check made under the lock cannot be overtaken (D-041).

### 8.8 API reference

All under `/api/v1/staff`, staff token required.

| Method | Path                               | Permission                                | Purpose                                                                                 |
| ------ | ---------------------------------- | ----------------------------------------- | --------------------------------------------------------------------------------------- |
| GET    | `/reference/{type}`              | any staff user                            | List a managed list; `?include_archived=1` (or `true`); for areas `?district_id=` |
| POST   | `/reference/{type}`              | `candidates.manage`                     | Add an entry (`name_ar`; areas also `district_id`)                                  |
| PUT    | `/reference/{type}/{id}`         | `candidates.manage`                     | Rename (an area never changes district)                                                 |
| DELETE | `/reference/{type}/{id}`         | `candidates.manage`                     | Archive                                                                                 |
| POST   | `/reference/{type}/{id}/restore` | `candidates.manage`                     | Restore                                                                                 |
| POST   | `/applications`                  | `candidates.manage`                     | Open an application (finds or creates the person)                                       |
| GET    | `/applications`                  | `reports.view` or `candidates.manage` | Search and list                                                                         |
| GET    | `/applications/{id}`             | `reports.view` or `candidates.manage` | File with person and full history                                                       |
| PATCH  | `/applications/{id}`             | `candidates.manage`                     | Correct a live application: send only the fields that change (D-049)                    |
| POST   | `/applications/{id}/close`       | `applications.close` (individual)       | End the application: body `reason`, optional `occurred_at` (D-037, D-039)           |
| GET    | `/candidates`                    | `reports.view` or `candidates.manage` | People: `q` (name in any spelling or national number in any digits), `is_active`, `birth_place_id`, `has_live_application`; 25 per page |
| GET    | `/candidates/{id}`               | `reports.view` or `candidates.manage` | The person with every application, newest first                                         |
| PATCH  | `/candidates/{id}`               | `candidates.manage`                     | Correct the person (national number included, never to another person's)                |
| POST   | `/candidates/{id}/deactivate`    | `candidates.manage`                     | Body `reason`; refused while the person has a live application                         |
| POST   | `/candidates/{id}/activate`      | `candidates.manage`                     | Nominable again                                                                         |

`marital-statuses` is only read here: the owner seeds it, and the change endpoints bind `{type}` to `EditableReferenceType`, which does not include it (404, and absent from the API documentation; D-048). Areas come with `district_id` and `district` (`{id, name_ar, archived}`), loaded once for the whole list. For reading, `{type}` binds to the `ReferenceType` enum (an unknown list is 404): `districts`, `areas`, `nominating-entities`, `job-positions`, `application-categories`, `academic-qualifications`, `specializations`, `marital-statuses` or `health-conditions`; `{id}` is an integer. `district_id` is required for a new area and refused for every other list. A name that already exists in any spelling is refused (422), also when the existing entry is archived (restore it instead). Archived entries cannot be chosen for new applications but still show (with `archived: true`) in existing files.

**Open an application** (`POST /applications`), all ids are integers:

```json
{
  "nominating_entity_id": 1, "job_position_id": 1, "application_category_id": 1,
  "existing_employee_number": null,
  "academic_qualification_id": 1, "specialization_id": 1, "studying_years": 3, "studies_discontinued": true, "quran_parts_memorized": 5,
  "marital_status_id": 1, "health_condition_id": 1, "health_details": null,
  "residence_district_id": 1, "residence_area_id": 3, "residence_details": "شارع الجلاء",
  "phone_number": "0912345678",
  "works_for_other_government_entity": false, "full_commitment": true, "overnight_work": true,
  "notes": null,
  "candidate": {
    "national_number": "01234567890", "full_name": "أحمد محمد علي", "mother_name": "فاطمة حسن",
    "birth_date": "1998-03-01", "birth_place_id": 1, "notes": null
  }
}
```

`residence_area_id` may be null (D-043). `studies_discontinued` may be left out or sent as `null`: both mean false (D-044); `true` requires `studying_years`. Names may not contain digits. Arabic-Indic digits and separators in `phone_number` and `national_number` are converted. `birth_date` is `YYYY-MM-DD`. The area must belong to the district (422 `recruitment.area_not_in_district`). Response 201 with `data` (the file) and `candidate_matched`.

**Search** (`GET /applications`, `ApplicationSearch`): every filter is optional; they combine with AND; a filter applies whenever its key has a value, so `0` and `false` filter too. Yes/no values: `1`/`0`/`true`/`false`.

| Filter | Meaning |
| ------ | ------- |
| `q` | Nomination number exactly, or part of a name in any spelling |
| `national_number`, `phone_number`, `existing_employee_number` | Exact (Arabic-Indic digits and separators accepted) |
| `has_employee_number` | Has an existing employee number or not |
| `status` | One status, or several: `status[]=exam_failed&status[]=exam_absent` |
| `live` | `1`: not ended; `0`: contract ended or closed |
| `job_position_id`, `nominating_entity_id`, `application_category_id`, `academic_qualification_id`, `specialization_id`, `marital_status_id`, `health_condition_id`, `residence_district_id`, `residence_area_id` | Exact list entry |
| `birth_place_id`, `candidate_active` | The person's birth place; whether the person is active |
| `studies_discontinued`, `works_for_other_government_entity`, `full_commitment`, `overnight_work` | Yes/no |
| `studying_years` | Exact; `studying_years_min`, `studying_years_max` for a range |
| `quran_parts_min`, `quran_parts_max` | Parts memorized, range |
| `birth_date_from`, `birth_date_to` | `YYYY-MM-DD`, included |
| `age_min`, `age_max` | Whole years today |
| `created_by` | The staff user who opened it |
| `from`, `to` | Opening date, both days included, UTC |
| `sort` | `created_at` (default, newest first), `nomination_number`, `full_name`, `birth_date`, `status`; `direction` `asc`/`desc` |
| `per_page` | 5 to 100 (25); filters stay in the page links |

Each row (`ApplicationSummaryResource`) carries the application's data and the person (`candidate`, with birth place and who deactivated them). Its relations load with the page (`ApplicationSummaryResource::RELATIONS`): 14 queries for any page size.

**File** (`GET /applications/{id}`): the application with each list value as `{id, name_ar, archived}`, grouped `education`, `health`, `residence`, `availability`; the `candidate`; and `history` in chain order, each event with `from_status`, `to_status`, `decision_type`, `reason`, `actor`, `occurred_at`.

**Correct an application** (`PATCH /applications/{id}`): any field of the create body except `candidate`; each follows its create rule, `null` clears an optional field. Changing `residence_district_id` without `residence_area_id` keeps the current area only if it belongs to the new district (otherwise 422 on `residence_area_id`); `studies_discontinued: true` needs `studying_years`, sent or stored. 422 on `status` when the application has ended. The nomination number, the person and the status never change here.

**People** (`GET /candidates`): each person with `applications_count` and `live_application` (`id`, `nomination_number`, `status`). `GET /candidates/{id}` adds every application (newest first) and `deactivation` (`at`, `reason`, `by`) when inactive. `PATCH /candidates/{id}` takes `national_number`, `full_name`, `mother_name`, `birth_date`, `birth_place_id`, `notes` with the create rules; a national number that belongs to someone else is 422 `recruitment.national_number_taken`.

**Close** (`POST /applications/{id}/close`): `{"reason": "انسحب المرشح", "occurred_at": "2026-10-20"}`. Returns the file. 422 on `status` when the application has already ended or has a written contract; 422 on `occurred_at` when the date is in the future or before the previous decision.

### 8.9 Seeding

| Seeder | Runs | Content |
| ------ | ---- | ------- |
| `ReferenceDataSeeder` | Every environment | Calls the list seeders below, the owner's `NominatingEntitiesSeeder` included (the template's five entities, OD-13) |
| `DistrictsSeeder` | Every environment | 15 governorates (تدمر included) |
| `AreasSeeder` | Every environment | Suggested areas per governorate (administrative areas; neighbourhoods for دمشق); a governorate missing from the list is skipped |
| `MaritalStatusesSeeder` | Every environment | أعزب، متزوج، مطلق، أرمل. The only way to change this list (D-048) |
| `JobPositionsSeeder`, `ApplicationCategoriesSeeder`, `AcademicQualificationsSeeder`, `SpecializationsSeeder`, `HealthConditionsSeeder` | Every environment | The template's values (`FROM_TEMPLATE`) plus suggestions (`SUGGESTED`); remove a suggestion from the constant, or archive it through the API, if it does not apply |
| `RecruitmentDemoSeeder` | `APP_ENV=local` | 38 invented people (national numbers `09…`), 39 applications in every status up to `awaiting_contract` (the contracts demo takes some further) with dated histories (outcomes, an exception, accepted excuses, closures), a person nominated twice, a deactivated person |
| `ImportsDemoSeeder` | `APP_ENV=local` | Three batches from generated workbooks: approved (six rows, uploaded by `staff`, approved by `manager`), cancelled, pending (valid rows, a person nominated again, a spelling variant, unknown job position, bad phone, duplicate, person mismatch, live application) |

Every list seeder matches entries by normalized name, archived ones included (`Concerns/SeedsReferenceList`): running it again adds only what is missing. The demo seeders use the real actions (the triggers accept nothing else), move the clock back so dates spread over the last months, need `DevUsersSeeder`, and run once. When no nominating entity exists, the demo adds the template's five (`Demo/DemoReferences`). Rebuild a development database with `php artisan migrate:fresh --seed`.

Production: run migrations, then `php artisan db:seed --class="App\Modules\Recruitment\Database\Seeders\ReferenceDataSeeder" --force`, then the owner's nominating entities seeder.

## 9. Imports module

### 9.1 What it does

Reads the "بيانات المرشحين" sheet of the legacy Excel template and turns its rows into applications, through a reviewed, recorded batch (analysis doc §09; D-040, D-041):

```
upload ─► read sheet ─► parse rows ─► check rows ─► (correct / confirm person / exclude) ─► approve ─► report
          keep original    cells → values    errors / reviews     whole batch re-checked      locked, atomic
```

Nothing becomes an application before approval. Batches and rows are never deleted.

```
app/Modules/Imports/
├── Actions/       UploadImportBatch, ReviewImportRows, ApproveImportBatch, CancelImportBatch, BuildImportReport
├── Database/      Migrations (import_batches, import_rows)
├── Enums/         ImportField (the columns read and where they go), ImportBatchStatus, ImportRowStatus
├── Http/          Controllers (ImportBatch, ImportRow), Requests, Resources
├── Models/        ImportBatch, ImportRow
├── Support/       WorkbookReader, HeaderMatcher, CellValue, SheetData, RowParser, ReferenceLookup,
│                  ImportChecker, ImportProblem
└── routes.php
config/imports.php   header aliases and limits
```

### 9.2 Database schema

**`import_batches`**

| Column                                                      | Notes                                                           |
| ----------------------------------------------------------- | --------------------------------------------------------------- |
| id                                                          | uuid PK                                                         |
| original_filename, stored_path, file_sha256, file_size      | the original, kept unchanged on the `imports.disk` disk       |
| sheet_name, header_row                                      | what was read                                                   |
| column_map                                                  | jsonb: field → `{column, header}`                            |
| ignored_columns                                             | jsonb: `[{column, header}]` headers the import does not use   |
| nominating_entity_id, source_district_id, notes             | optional; the entity also fills rows whose entity cell is empty |
| status                                                      | `pending` / `approved` / `cancelled`                      |
| uploaded_by, approved_by/at, cancelled_by/at, cancel_reason | who did what; CHECKs require them for each status               |

Partial unique index on `file_sha256` where status is not `cancelled`: the same file once (AC-10).

**`import_rows`**

| Column                                                 | Notes                                                                                                                              |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| id                                                     | uuid PK                                                                                                                            |
| import_batch_id, row_number                            | unique together; the row in the sheet                                                                                              |
| raw                                                    | jsonb: column letter → the cell as Excel displayed it (all header columns, never changed)                                         |
| data                                                   | jsonb: dotted application input key → understood value (`job_position_id`, `candidate.birth_date`, …); corrections change it |
| parse_issues                                           | jsonb: key → `{code, message, value}` for cells that could not be read; a correction of that key clears it                      |
| issues                                                 | jsonb: `[{field, code, severity, message}]` from the last check; severity `error` or `review`                                |
| status                                                 | `valid` / `needs_review` / `invalid` / `excluded` / `imported`                                                           |
| candidate_id                                           | the person on file with this national number, if any                                                                               |
| confirmed_national_number, reviewed_by/at, review_note | "same person" confirmation, valid only for that number                                                                             |
| excluded_by/at, exclude_reason                         | CHECK: an excluded row has a reason                                                                                                |
| application_id, imported_at                            | the application created (unique); CHECK: set exactly when imported                                                                 |

### 9.3 Reading the file (D-040, D-045)

1. **`FileInspector`** (before any parsing): `.xlsx` must be a zip, `.xls` an OLE file, whatever the extension. Refused: macros, external links, embedded objects, ActiveX, data connections, executable or traversing part names, parts that expand more than 200 times (zip bombs).
2. **Sheet:** the one named like "بيانات المرشحين", alone; otherwise the first 15 sheets one by one. **Only that sheet is loaded**, with shared strings and styles, whose unpacked size must stay under `max_unpacked_megabytes`. The real template's other 11 sheets (about 210 MB unpacked) are never decompressed.
3. **Header row:** searched in the first 20 rows; it must contain at least the full name and the national number. In the real template it is row 5.
4. **Columns:** matched by header text after normalisation (أ/ا, ى/ي, ة/ه, harakat and punctuation ignored), exact before contained, longer alias first, each column and field once. A required column that is missing refuses the file with the list of missing columns.
5. **Formulas** are never evaluated (their saved value is used). Formulas that reach outside the workbook or run something (DDE, external workbooks, paths, `WEBSERVICE`, `HYPERLINK`, …) refuse the file, naming the cell.
6. **Rows:** a row counts if one mapped cell has a value (helper formulas copied down an empty template do not make rows). **Any row with an empty national number refuses the whole file** (D-046). Cell text is cut at 4,000 characters; raw cells are stored at 1,000.

| Field                                               | Real template header (column)                                                                                                                                    | Required column    | Goes to                                                                                                                      |
| --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| nominating entity                                   | الجهة المرشحة (B)                                                                                                                                    | no (batch default) | `nominating_entity_id`                                                                                                     |
| job position, category                              | المسمى الوظيفي (C), الفئة (D)                                                                                                                  | yes                | `job_position_id`, `application_category_id`                                                                             |
| employee number                                     | الرقم الذاتي (E)                                                                                                                                      | no                 | `existing_employee_number`                                                                                                 |
| full name, mother's name                            | الاسم الثلاثي (F), اسم الأم (G)                                                                                                               | yes                | `candidate.full_name`, `candidate.mother_name`                                                                           |
| birth place, birth date, national number            | مكان الولادة (H), تاريخ الولادة (I), الرقم الوطني (J)                                                                          | yes                | `candidate.birth_place_id`, `candidate.birth_date`, `candidate.national_number`                                        |
| marital status                                      | الحالة الاجتماعية (K)                                                                                                                            | yes                | `marital_status_id`                                                                                                        |
| health (with details)                               | الحالة الصحية/بالتفصيل (L)                                                                                                                   | yes                | `health_condition_id` + `health_details`                                                                                 |
| residence district, place                           | محافظة الإقامة (M), مكان الإقامة الحالية (N)                                                                                      | yes                | `residence_district_id`; `residence_area_id` when N names an area of that district, else N goes to `residence_details` |
| qualification, specialization                       | المؤهل العلمي (O), الاختصاص (P)                                                                                                              | yes                | `academic_qualification_id`, `specialization_id`                                                                         |
| study years, Quran parts                            | عدد السنين الدراسية (Q), عدد الأجزاء المحفوظة من القرآن (R)                                                           | no                 | `studying_years` + `studies_discontinued`, `quran_parts_memorized`                                                     |
| other government entity, overnight, full commitment | هل تعمل مع جهة حكومية أخرى (S), الالتزام بالمبيت والدوام بنسبة 50% (T), التفرغ الكامل للعمل (U) | yes                | the three booleans                                                                                                           |
| phone, notes                                        | رقم الهاتف (V), ملاحظات (W)                                                                                                                      | V yes, W no        | `phone_number`, `notes`                                                                                                  |

Columns A (navigation links), X (import date), Y (derived id formula) and Z (exam number) are kept in the raw cells but not imported.

### 9.4 Reading cells (`RowParser`)

| Kind                    | Accepted                                                                                                                                                                                                     | Not guessed                                                                                         |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| Dates                   | Excel serials in the workbook's own date system (1900/1904); `d/m/Y`, `Y-m-d`, `-` or `.` separators, Arabic-Indic digits, a trailing time; month first only when the first number cannot be a month | impossible dates                                                                                    |
| National number         | 11 to 20 digits as text; separators dropped                                                                                                                                                                        | a number cell with fewer digits (its leading zero is gone): error with the suggested value; letters |
| Phone                   | text, separators dropped, `+` kept                                                                                                                                                                         | a number cell not starting with 0: error with a suggestion                                          |
| Yes / no                | نعم/لا, ن, اي, بلى, صح/خطأ, موافق, ملتزم/غير ملتزم, متفرغ/غير متفرغ, يعمل/لا يعمل, yes/no, y/n, true/false, ✓/✗, and the numbers 1/0 only         | anything else (`-1`, `2`, `ربما`)                                                         |
| Study years             | a number, or "3 ومنقطع" / "3 منقطع" (years + discontinued)                                                                                                                                        | anything else                                                                                       |
| Whole numbers           | number cells or digit text, up to 9999                                                                                                                                                                       | decimals, words, huge numbers                                                                       |
| List values             | the entry's name in any spelling                                                                                                                                                                             | unknown names (error, named), archived entries (error, says archived)                               |
| Health (list + details) | "مريض - سكري": the longest leading part that names an entry, the rest is details; entries whose names contain punctuation win over shorter ones                                                      | unmatched text is kept as details and the row needs a correction                                    |
| Residence place         | an area of the residence district, possibly followed by details                                                                                                                                              | never an error: unmatched text becomes `residence_details`                                        |

### 9.5 Checking rows (`ImportChecker`)

Run after upload, after every correction or review decision, on `revalidate`, and again inside approval. Lists, people and live applications are loaded once per run.

- **Errors** (status `invalid`): what could not be read; missing required values (and study years when `studies_discontinued` is true); list entries archived or missing; area not in the residence district; the field limits of `ApplicationRules` (names, lengths, ranges, phone pattern, birth date between 1950 and today), the same as the API (D-042); a national number used by an earlier row of the file (`duplicate_in_file`); a person who already has a live application (`live_application`, names its nomination number).
- **Review** (status `needs_review`): the national number belongs to a person on file whose name, mother's name or birth date differ (spelling variants ignored). Staff confirm it is the same person (stored data kept) or exclude the row.
- Excluded and imported rows are not checked.

### 9.6 Approval (`ApproveImportBatch`)

1. Lock the batch (pending only). The approver needs `imports.approve` and must not be the uploader (403, D-047).
2. Lock every national number of the batch (`CreateApplication::lockPeople`).
3. Check the batch again. A row that was valid but no longer is → 422 (`rows_changed`, names the rows; revalidate to see why). Rows that need review → 422 (`unresolved_reviews`).
4. Reserve one nomination number per valid row, in sheet order.
5. Create each valid row through `CreateApplication::createLocked()` (no savepoints), mark it imported with its application.
6. Mark the batch approved; audit `import.approved`.

If anything fails, everything rolls back: nothing is imported, the reserved numbers are given back, the batch stays pending, and the 422 names the row. Measured: 2,000 rows take about 5 s to upload and 12 s to approve.

### 9.7 API reference

All under `/api/v1/staff/imports`. Reading needs `imports.manage` or `imports.approve`; changing needs `imports.manage`; approving needs `imports.approve` (individual) and is refused to the uploader (D-047).

| Method | Path                                   | Purpose                                                                                                                                          |
| ------ | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| GET    | `/`                                  | Batches, newest first, with row counts; `?status=`                                                                                             |
| POST   | `/`                                  | Upload (multipart, max 10 per minute): `file`, optional `nominating_entity_id`, `source_district_id`, `notes`. 201 with the batch        |
| GET    | `/{batch}`                           | The batch: file, sheet, column map, ignored columns, counts per status, who did what                                                             |
| POST   | `/{batch}/revalidate`                | Check every open row again (after list changes)                                                                                                  |
| POST   | `/{batch}/approve`                   | Approve (section 9.6); `imports.approve`, not the uploader                                                                                     |
| POST   | `/{batch}/cancel`                    | Cancel with `reason`; frees the file for a new upload                                                                                          |
| GET    | `/{batch}/original`                  | The uploaded file, unchanged (attachment, fixed type, `nosniff`)                                                                               |
| GET    | `/{batch}/report`                    | `.xlsx`, right-to-left: row, status, nomination number, name, national number, reasons; every text cell written as text (no formula ever runs) |
| GET    | `/{batch}/rows`                      | Rows in sheet order, 50 per page; `?status=invalid`                                                                                            |
| GET    | `/{batch}/rows/{row}`                | One row (only within its batch)                                                                                                                  |
| PATCH  | `/{batch}/rows/{row}`                | Correct: `{"values": {"job_position_id": 4, "candidate.birth_date": "1995-03-01"}}` (ids for lists, true/false, `YYYY-MM-DD`, null clears)   |
| POST   | `/{batch}/rows/{row}/confirm-person` | Confirm the person on file; optional `note`                                                                                                    |
| POST   | `/{batch}/rows/{row}/exclude`        | Exclude with `reason`                                                                                                                          |
| POST   | `/{batch}/rows/{row}/include`        | Bring an excluded row back                                                                                                                       |

A row in the response: `status`, `issues` (`field`, `code`, `severity`, Arabic `message`), `values` (dotted keys; list values as `{id, name_ar}`), `cells` (`column`, `header`, `value` as in Excel), `person_on_file`, `review`, `exclusion`, `application` (`id`, `nomination_number` once imported).

Refusals of a whole file or batch are 422 on `file` or `batch`: unreadable file, unsafe content (macros, links, embedded objects, zip bomb), unsafe formula (cell named), too large once unpacked, no header row, missing columns (named), no data rows, a row without a national number (rows named), too many rows, same file already uploaded, batch not pending, rows still needing review, rows no longer valid.

### 9.8 Configuration (`config/imports.php`)

| Key                        | Default                                | Meaning                                                                                                      |
| -------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `headers.<field>`        | §23 names and variants                | header texts that identify each column; add a variant here when a file uses another header                   |
| `sheet_name`             | بيانات المرشحين          | preferred sheet                                                                                              |
| `header_search_rows`     | 20                                     | rows searched for the header row                                                                             |
| `max_rows`               | 2000 (`IMPORTS_MAX_ROWS`)            | upload and approval run in the request (D-041)                                                               |
| `max_file_kilobytes`     | 30720 (`IMPORTS_MAX_FILE_KILOBYTES`) | upload size limit (the real template is 16.5 MB)                                                             |
| `max_unpacked_megabytes` | 100                                    | the parts actually read (chosen sheet, shared strings, styles), unpacked; the real template needs about 6 MB |
| `disk`, `directory`    | `local`, `imports`                 | where originals are kept                                                                                     |

Audit events: `import.uploaded`, `import.row_corrected` (with before/after), `import.person_confirmed`, `import.row_excluded`, `import.row_included`, `import.approved`, `import.cancelled`.

## 10. Exams module

### 10.1 What it does

What each job position is examined on, the question bank, how a result is decided (analysis doc §04, §05), and the exam day: sessions, bookings, the candidate's login and attempt, extensions, absences, hand marks and the automatic result (§06, §07; D-056, D-072 to D-075; sections 10.8 to 10.11).

```
app/Modules/Exams/
├── Actions/       ManageExamSubjects, ManageExamPlans (drafts, replace, readiness, publish, delete), ManageQuestions,
│                  ManageExamSessions, DrawSessionQuestions, ManageExamBookings, CandidateExamLogin, TakeExam,
│                  ExtendAttempt, ExamResults, SweepExamSessions, ExamDecisions (excuse, exception)
├── Console/       SweepExamSessionsCommand (exams:sweep, every minute)
├── Database/      Migrations (6), Seeders (ExamSubjectsSeeder, ExamsDemoSeeder, ExamSessionsDemoSeeder)
├── Enums/         ExamPlanStatus, Assessment (exam | manual), Difficulty (easy | medium | hard), BookingStatus,
│                  AttemptStatus, ClosedBy, SessionMode (unused since D-054)
├── Http/          Controllers (ExamSubject, ExamPlan, ExamQuestion, PassPolicy, ExamSession, ExamBooking,
│                  ExamDecision, CandidateExam), Requests, Resources
├── Models/        ExamSubject, ExamSubjectPart, PassPolicy, ExamPlanVersion, ExamPlanSubject, ExamPlanSubjectPart,
│                  ExamQuestion, ExamQuestionVersion, Concerns/HasDifficultyCounts, ExamSession, ExamSessionQuestion,
│                  ExamBooking, ExamAttempt, ExamAttemptItem, ExamAnswerRevision, ExamSessionExtension,
│                  ExamAttemptExtension, ExamManualMark, ExamResult
├── Queries/       ExamQueue
├── Scoring/       ExamScorer, ScoringPlan, Mark, PassRules, ExamOutcome, SubjectResult (pure PHP, D-052)
└── routes.php
```

### 10.2 Database schema

| Table | Notes |
| ----- | ----- |
| `exam_subjects` | `id` bigint, `name_ar`, `name_key` unique, timestamps, `deleted_at` (archived) |
| `exam_subject_parts` | `subject_id`, `name_ar`, `name_key` (unique per subject), `default_weight` numeric(7,2) CHECK > 0, `default_assessment` (`exam` or `manual`); `(subject_id, id)` unique for composite keys |
| `exam_pass_policies` | `version` unique, `subject_pass_mark`, `tolerance_floor`, `max_tolerated_subjects`, `average_must_exceed`, `description`; CHECK floor ≤ pass mark ≤ 100; append-only (trigger). Version 1 = 60 / 58 / 1 / 70, inserted by the migration |
| `exam_plan_versions` | uuid; `job_position_id`, `version` (unique per job position), `status` (`draft`, `published`, `retired`), `duration_minutes` (the single session), `pass_policy_id`, `notes`, `created_by`, `published_by`, `published_at`, `retired_at`. CHECKs: publication data exactly for published/retired, `retired_at` exactly for retired, a published plan has a duration. Partial unique indexes: one `published` and one `draft` per job position |
| `exam_plan_subjects` | `plan_version_id` (cascade), `subject_id`, `position`, `easy_count`, `medium_count`, `hard_count` (a subject scored as a whole: all three ≥ 1; scored from parts: all null) |
| `exam_plan_subject_parts` | `plan_subject_id`, `plan_version_id`, `subject_id`, `part_id`, `position`, `weight` (> 0), `assessment`, `easy_count`/`medium_count`/`hard_count`, `max_score`. CHECK: an exam part has all three counts ≥ 1 (or none in a draft) and no `max_score`; a manual part has `max_score` > 0 and no counts. Composite keys to its plan subject and to `exam_subject_parts (subject_id, id)` |
| `exam_questions` | `subject_id`, `part_id` (nullable; composite key: a part of that subject), `difficulty`, `created_by`, timestamps, `deleted_at` (archived) |
| `exam_question_versions` | `question_id`, `version` (unique per question), `prompt` (not blank), `options` jsonb array of 2 to 6 texts, `correct_option` (index, CHECK < number of options), `created_by`, `created_at`; append-only (trigger) |

Triggers: `exam_plan_versions_frozen` (a non-draft version changes only published → retired with nothing else; only drafts are deleted; a draft cannot jump to retired), `exam_plan_*_frozen` (subjects and parts change only while their version is a draft), `exam_question_versions_append_only`, `exam_pass_policies_append_only`. CHECKs that compare nullable columns use `coalesce`, because a CHECK that evaluates to NULL passes.

### 10.3 Plans: one session, parts marked by hand, three difficulties (D-054)

- Every online question is asked in **one session** (`duration_minutes` of the plan).
- A part is assessed `exam` (questions in the session) or `manual` (marked by hand outside the session, like التجويد: oral the same day). A manual part has `max_score`, the scale staff mark it out of (default 100; the demo uses 40 for التجويد as in the legacy sheet); its mark enters the engine as `Mark::of(achieved, max_score)`.
- Every exam subject or part asks **easy, medium and hard** questions, at least one of each.
- A subject scored as a whole draws on every question of the subject; a part on its own questions only.
- Publishing checks completeness and the bank: at least one subject and one online question, a duration, counts for every exam item, nothing archived, and enough active questions of each difficulty for each item ("the bank has 3 hard questions, the plan asks 4"). `GET /exam-plans/{id}/readiness` runs the same checks without publishing.

### 10.4 Question bank (D-055)

A question belongs to a subject (optionally a part) and has a difficulty. Its prompt, options and correct option are versions: a correction adds a version and the old one stays (sessions keep the version they drew). Same content again, or only a new difficulty: no new version. Its subject and part never change. Options keep the order they were written in (never shuffled, D-056). Archived questions are hidden and not counted. Only `exam_bank.manage` reads the bank: it contains the answers.

### 10.5 Scoring (`ExamScorer`, D-052)

```php
$outcome = (new ExamScorer())->score(
    $plan->scoringPlan(),                                   // subjects, parts and weights of a version
    [$hadithId => Mark::of(80),                             // whole subject: achieved, max (default 100)
     $quranId  => [$recitationId => Mark::of(47, 60),       // exam part: points from the questions
                   $tajweedId    => Mark::of(38, 40)]],     // manual part: entered by staff, out of max_score
    $plan->passPolicy->rules(),
);
$outcome->passed;            // false when any reason applies
$outcome->reasons;           // [['code' => 'subject_below_floor'|'too_many_below_pass_mark', 'subject_ids' => [...]], ['code' => 'average_not_above']]
$outcome->subjects[$id];     // SubjectResult: exact score, band (passed | in_tolerance | below_floor), part percentages
$outcome->toArray();         // display values rounded half-up to 2 places, plus tolerated subjects and the policy version
```

Marks must cover exactly the plan's subjects and parts (otherwise `InvalidArgumentException`). The doc's five worked examples are unit tests.

### 10.6 API reference

All under `/api/v1/staff`. Subjects and plans: read with `exam_bank.manage`, `candidates.manage` or `reports.view`; change with `exam_bank.manage`. The question bank and plan readiness: `exam_bank.manage` only.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/exam-subjects` | Subjects with their parts; `?include_archived=1` |
| POST | `/exam-subjects` | `name_ar`, optional `parts: [{name_ar, default_weight, default_assessment}]` |
| PUT | `/exam-subjects/{id}` | Rename |
| DELETE / POST | `/exam-subjects/{id}` / `/exam-subjects/{id}/restore` | Archive / restore |
| POST | `/exam-subjects/{id}/parts` | Add a part: `name_ar`, `default_weight`, optional `default_assessment` (`exam`) |
| PATCH | `/exam-subject-parts/{id}` | `name_ar`, `default_weight`, `default_assessment` (published plans keep their copy) |
| DELETE / POST | `/exam-subject-parts/{id}` / `.../restore` | Archive / restore |
| GET | `/exam-questions` | 50 per page; `subject_id`, `part_id` (or `none`), `difficulty`, `q`, `include_archived` |
| GET | `/exam-questions/stats` | Active questions per subject, part and difficulty |
| GET | `/exam-questions/{id}` | A question with every version |
| POST | `/exam-questions` | `subject_id`, optional `part_id`, `difficulty`, `prompt`, `options` (2 to 6), `correct_option` (from 0) |
| PATCH | `/exam-questions/{id}` | `prompt`, `options`, `correct_option` (new version when they change), `difficulty` |
| DELETE / POST | `/exam-questions/{id}` / `.../restore` | Archive / restore |
| GET | `/exam-plans` | Versions, newest first; `job_position_id`, `status` |
| GET | `/exam-plans/{id}` | One version |
| POST | `/exam-plans` | New draft: `job_position_id`, optional `copy_from` (default: the published version). 422 when a draft exists |
| PUT | `/exam-plans/{id}` | Replace a draft (below) |
| GET | `/exam-plans/{id}/readiness` | `{ready, problems}`: what keeps the draft from being published |
| DELETE | `/exam-plans/{id}` | Delete a draft (204) |
| POST | `/exam-plans/{id}/publish` | Publish; retires the previous version. 422 lists every problem |
| GET | `/exam-pass-policies` | Versions of the pass rules, newest (current) first |
| POST | `/exam-pass-policies` | New version (individual `pass_policies.manage`): `subject_pass_mark`, `tolerance_floor`, `max_tolerated_subjects`, `average_must_exceed`, `description`; plans published from then on use it (D-063) |

**Replace a draft** (`PUT /exam-plans/{id}`):

```json
{
  "duration_minutes": 120, "notes": null,
  "subjects": [
    {"subject_id": 1, "questions": {"easy": 8, "medium": 8, "hard": 4}},
    {"subject_id": 6, "parts": [
      {"part_id": 1, "weight": "60", "questions": {"easy": 4, "medium": 4, "hard": 2}},
      {"part_id": 2, "assessment": "manual", "max_score": "40"}
    ]}
  ]
}
```

Order of `subjects` (and of `parts`) is the exam order. Subjects and parts must be active; a part must belong to its subject; a subject scored from parts takes no `questions`; a manual part takes no `questions` and an exam part no `max_score`; `weight` and `assessment` default to the part's defaults.

### 10.7 Seeding

`ExamSubjectsSeeder` (every environment): the legacy exams sheet's subjects (الحديث النبوي، السيرة النبوية، البناء الإيماني، فقه الجهاد، فقه العبادات، القرآن الكريم = التلاوة 60 in the exam + التجويد 40 marked by hand); re-run safe. `ExamsDemoSeeder` (local): 12 placeholder questions per difficulty for every exam item (216), a published plan for the first job position, a retired and a published version for the second, a draft for the third.

`ExamSessionsDemoSeeder` (local, runs last): six people nominated three days ago for the first published plan's job position (national numbers `09000000301` to `09000000306`); a session held yesterday at 09:00 (the first candidate sat it, answered four in five right, submitted, التجويد marked: passed; the second was absent); and a session tomorrow at 09:00 with the other four booked. They log in from 08:00 with their national number and the password `exam-2026`.

### 10.8 Exam sessions and candidate login (D-072, D-073)

| Table | Notes |
| ----- | ----- |
| `exam_sessions` | uuid; `plan_version_id` (published at creation: trigger), `starts_at`, `location`, `duration_minutes` (the plan's, copied), `late_minutes` (< duration), `extra_minutes` (extensions for everyone; only grows), `password` (hashed), `notes`, `created_by`. Once someone started: plan, start, length and late window fixed (trigger) |
| `exam_session_questions` | the draw: `session_id`, `position`, `question_version_id` (unique per session), `plan_subject_id`, `plan_part_id` (null for a subject scored as a whole). Inserted or deleted (a new draw) only before anyone starts, never updated (trigger) |
| `exam_bookings` | uuid; `session_id`, `application_id`, `round` (unique per application), `status` (`booked`, `cancelled`, `absent`, `attended`; only from `booked`), `decided_at` (the application moved because of it), cancellation (`cancelled_at`, `cancelled_by` null = the system, `cancel_reason`). One open booking per application (partial unique index); never deleted (trigger). Cause of the status events (`exam_booking_id`) |
| `exam_session_extensions` | time for everyone: `minutes`, `reason`, `extended_by`, `created_at` (append-only) |
| `personal_access_tokens.exam_booking_id` | a candidate's token belongs to one booking |

Timing: candidates may log in from `exams.login_early_minutes` (60) before the start; may start from `starts_at` until `late_until` = start + `late_minutes`; every attempt ends at `ends_at` = start + duration + `extra_minutes`, unless it was given more on its own. A session's `stage`: `scheduled`, `open` (candidates may start), `running` (the late window closed), `ended`.

`exams:sweep` (scheduler, every minute; also run when staff open a session or its bookings, and on a candidate's own attempt whenever they touch it): a booking not started by `late_until` becomes `absent` and the application moves to `exam_absent` (dated `late_until`, cause the booking); an open attempt past its deadline is closed at the deadline (`timeout`). An application that left `awaiting_exam` in the meantime has its booking cancelled instead. Idempotent.

### 10.9 Attempts, answers, marks and results (D-075)

| Table | Notes |
| ----- | ----- |
| `exam_attempts` | uuid; `booking_id` (unique: one attempt per booking), `session_id` (composite key with the booking), `status` (`in_progress`, `submitted`), `started_at`, `deadline_at` (server time; only moves later), `extra_minutes` (its own), `submitted_at`, `closed_by` (`candidate`, `timeout`), `start_ip`. Never reopened or deleted (trigger) |
| `exam_attempt_items` | the session's questions in this candidate's order: `position`, `session_question_id` (same session: composite key), `selected_option`, `answered_at`, `is_correct` (set once at closing). Answers change only while in progress (trigger) |
| `exam_answer_revisions` | every save: `attempt_item_id`, `selected_option` (null = cleared), `ip`, `saved_at` (append-only) |
| `exam_attempt_extensions` | every move of a deadline: `minutes`, `reason`, `previous_deadline`, `new_deadline`, `session_extension_id` (its share of one for everyone), `extended_by` (append-only) |
| `exam_manual_marks` | hand marks (التجويد): `attempt_id`, `plan_part_id` (a manual part of the session's plan, mark ≤ its `max_score`: trigger), `revision` (2+ need a `reason`), `mark`, `entered_by` (append-only) |
| `exam_results` | `attempt_id`, `revision` (2+ need a `reason`), `pass_policy_id`, `passed`, `average` (display), `outcome` jsonb (`ExamOutcome::toArray()`: subjects, bands, reasons, tolerated subjects, policy version), `decided_by` (null = the system) (append-only) |

Scoring an attempt: an online subject (or exam part) scores its right answers out of the questions drawn for it; a hand-marked part its latest mark out of its scale; `ExamScorer` decides under the plan's pass policy. The result is decided when the attempt is closed and every hand mark is in, whichever comes last. The application moves at once (`awaiting_interview` or `exam_failed`, dated `submitted_at`, cause the booking). Correcting a hand mark: a reason always; after the decision also `exam_results.correct`; the result gets a new revision, and a `correction` event when passed / failed changes (only while it is the application's latest decision).

### 10.10 Exam sessions API

All under `/api/v1/staff`. Reading: `exams.manage`, `candidates.manage`, `reports.view` or `exam_bank.manage`. Changing: `exams.manage`.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/exam-sessions` | 25 per page, soonest first; `from`, `to`, `job_position_id` |
| POST | `/exam-sessions` | `plan_version_id` (published), `starts_at` (future), `location`, `late_minutes` (default 15), `password` (6 to 64), `notes`. Draws the questions (422 on `plan_version_id` when the bank is short). 201 |
| GET | `/exam-sessions/{id}` | With `late_until`, `ends_at`, `stage`, counts, extensions |
| PATCH | `/exam-sessions/{id}` | `starts_at`, `location`, `late_minutes` before it starts; `password`, `notes` at any time |
| DELETE | `/exam-sessions/{id}` | Only one nobody was booked into (204) |
| POST | `/exam-sessions/{id}/draw` | Draw again, before it starts |
| GET | `/exam-sessions/{id}/questions` | The draw with the answers (`exam_bank.manage` only) |
| GET | `/exam-sessions/{id}/bookings` | The candidates live: status, attempt (start, deadline, questions, answered, last save, right answers once closed, extensions), hand marks, result; `status` |
| POST | `/exam-sessions/{id}/bookings` | `application_ids` (awaiting their exam, the session's job position, none open), until the late window closes; all or nothing. 201 |
| POST | `/exam-sessions/{id}/extend` | `minutes`, `reason` (individual `attempts.extend`): everyone still sitting |
| GET | `/exams/queue` | Who waits to be booked, longest wait first; the applications list's filters |
| GET | `/exam-bookings/{id}` | One booking with every hand-mark revision and the result |
| GET | `/applications/{id}/exam-bookings` | An application's rounds |
| POST | `/exam-bookings/{id}/cancel` | `reason`; only while not started |
| POST | `/exam-bookings/{id}/marks` | `marks: [{part_id, mark}]` (plan part ids), `reason` to change a mark entered; after the decision also `exam_results.correct` (403 otherwise) |
| POST | `/exam-attempts/{id}/extend` | `minutes`, `reason` (individual `attempts.extend`): one running attempt |
| POST | `/applications/{id}/exam-excuse` | `reason`, `occurred_at`, optional `session_id` to book the next round (individual `exceptions.approve`) |
| POST | `/applications/{id}/exam-exception` | `reason`, `occurred_at`: a failed exam admitted to the interview (individual `exceptions.approve`) |

Audit events: `exam_session.created|updated|redrawn|deleted|extended`, `exam_booking.created|cancelled|absent`, `exam.candidate_login`, `exam_attempt.started|closed|extended`, `exam_marks.entered|corrected`, `exam_result.decided|revised`, `exam.excuse_accepted`, `exam.exception_approved`.

### 10.11 The candidate's exam page (for the frontend)

Base path `/api/v1/candidate`, `Authorization: Bearer <token>` after login. The server keeps the time: show `deadline_at` and `seconds_left` (computed against `server_time`) as a help only, and expect a 422 when time is over.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| POST | `/auth/login` | `national_number`, `password` (the session's). Returns `token` and `exam`. One generic 422 message for every failure; 422 `too_late` after the late window. Throttled: 120/min per address, 5/min per national number and address |
| POST | `/auth/logout` | Ends the token |
| GET | `/exam` | When, where, `late_until`, `duration_minutes`, `server_time`, `status`, `can_start`, `attempt` (`deadline_at`, `seconds_left`, `questions`, `answered`, `submitted_at`, `closed_by`) |
| POST | `/exam/start` | From the start until the late window closes; again returns the same attempt |
| GET | `/exam/questions` | `[{position, prompt, options, selected_option, answered_at}]` in the candidate's order; never the answer |
| PUT | `/exam/answers/{position}` | `{"option": 2}` or `{"option": null}`; returns `saved_at` and `deadline_at`. Show "saved" only after this answers (AC-14) |
| POST | `/exam/submit` | Closes the exam; again returns the same receipt |

A new login ends the previous token (401 on the old one). A deactivated person is signed out (401).

## 11. Documents module

### 11.1 What it does (D-057)

Files along an application's life: what the candidate brings (ID card image, certificates, CV, official documents) and what the organization issues (contract). A **document type** says whether a file belongs to the **person** (kept for every later application, like the ID card) or to **one application** (the contract), whether it is incoming or outgoing, and from which application status it is required. Each application lists its requirements and what is missing; nothing is blocked (owner's choice).

```
app/Modules/Documents/
├── Actions/       ManageDocuments (upload, archive), ManageDocumentTypes
├── Database/      Migrations (1), Seeders (DocumentTypesSeeder, DocumentsDemoSeeder)
├── Enums/         DocumentScope (candidate | application), DocumentDirection (incoming | outgoing)
├── Http/          Controllers (Document, DocumentType), Requests, Resources
├── Models/        Document, DocumentType
├── Support/       DocumentFile (content checks), DocumentRequirements
└── routes.php
config/documents.php    disk, directory, size limit (10 MB), accepted types
app/Support/SafeFilename.php   shared with the import
```

### 11.2 Database schema

| Table | Notes |
| ----- | ----- |
| `document_types` | `name_ar`, `name_key` unique, `scope`, `direction`, `required_from_status` (an application status, or null: optional), timestamps, `deleted_at` |
| `documents` | uuid; `document_type_id`, `candidate_id`, `application_id` (null for a person's document; composite key `(application_id, candidate_id)` → `applications (id, candidate_id)`: the same person's application), `original_filename` (sanitised), `stored_path` unique, `mime_type` (detected), `size_bytes`, `sha256`, `notes`, `uploaded_by`, `created_at`, `archived_at`, `archived_by`, `archive_reason` |

CHECK: archive fields all empty or all set with a real reason. Triggers: a document's application matches its type's scope; a document never changes except being archived once, and is never deleted.

### 11.3 Files

Stored on the private disk (`config('documents.disk')`) as `documents/{candidate_id}/{document_id}.{ext}`, never under the client's name, never served from a public URL. Accepted: PDF, JPEG, PNG, WebP up to 10 MB. The type is detected from the content (`finfo`) and must match the extension; a PDF must start with `%PDF-`, an image must be readable as one. A PHP file named `.pdf`, a PDF named `.png`, or a `.docx` are refused (422 on `file`). Downloads are attachments with the detected type, `nosniff`, `private, no-store`, and are audited (`document.downloaded`).

### 11.4 Requirements

`DocumentRequirements::of($application)`: every active type with `required_now` (the application's history contains its `required_from_status`), `present` (active documents: the person's for a person type, this application's for an application type) and `missing`.

### 11.5 API reference

All under `/api/v1/staff`. Types: read by any staff user, changed with `candidates.manage`. Documents: read and download with `reports.view`, `candidates.manage` or `contracts.manage`; upload and archive with `candidates.manage` or `contracts.manage`.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/document-types` | `?include_archived=1` |
| POST | `/document-types` | `name_ar`, `scope`, `direction`, `required_from_status` |
| PATCH | `/document-types/{id}` | `name_ar`, `direction`, `required_from_status` (never `scope`) |
| DELETE / POST | `/document-types/{id}` / `.../restore` | Archive / restore |
| GET | `/candidates/{id}/documents` | The person's documents and all their applications' |
| POST | `/candidates/{id}/documents` | multipart `document_type_id`, `file`, `notes`; person types only |
| GET | `/applications/{id}/documents` | `{documents, requirements}` |
| POST | `/applications/{id}/documents` | multipart; a person type is stored on the person, an application type on this application |
| GET | `/documents/{id}/download` | The original file |
| POST | `/documents/{id}/archive` | `reason`; once |

### 11.6 Seeding

`DocumentTypesSeeder` (every environment): the owner's examples (صورة البطاقة الشخصية and الشهادات العلمية required from the nomination, السيرة الذاتية, وثائق رسمية, العقد required from `contract_written`) plus suggestions (صورة شخصية, كتاب الترشيح, قرار الاستثناء). `DocumentsDemoSeeder` (local): placeholder PDFs for half of the demo applications and half of the written contracts.

## 12. Interviews module

### 12.1 What it does (D-059, D-061, D-062, D-067)

The interview step (analysis doc §07, §08; legacy sheet المقابلات). Every interview is booked into a **sitting**: a **committee sitting** (one committee, one place, many candidates, each with their own time) or a **one-off sitting** (one interviewer who sees the candidates one by one: exactly one member). The same sitting (start, place and members) cannot be entered twice. The sitting holds the place, the committee name and the members (a name and a capacity: رئيس اللجنة / عضو / مقرر, optionally a staff account). The **committee enters the result** (ناجح / راسب / غائب) with three grades on the template's scale; nothing computes it. Recording the result moves the application, with the interview as the cause of that history event. Absence excuses bring the application back (a new round); corrections keep every version. Staff choose whom to book first with the **interview queue** (their own priorities) and can book many applications into a sitting at once.

```
app/Modules/Interviews/
├── Actions/       ManageSittings, ManageInterviews (schedule, bookMany, reschedule, cancel, record, correct, acceptExcuse), Committee
├── Database/      Migrations (1), Seeders (InterviewsDemoSeeder)
├── Enums/         InterviewStatus, InterviewResult, InterviewGrade, SittingKind, MemberCapacity
├── Http/          Controllers (InterviewSitting, Interview, InterviewQueue), Requests (AppointmentRules shared), Resources
├── Models/        InterviewSitting, Interview (StatusChangeCause), InterviewMember, InterviewReschedule, InterviewResultRevision
├── Queries/       InterviewQueue
└── routes.php
```

### 12.2 Database schema

| Table | Notes |
| ----- | ----- |
| `interview_sittings` | uuid; `kind` (`committee`, `one_off`), `starts_at`, `location` (place or medium), `committee_name` (nullable), `notes`, `committee_key` (md5 of the members' sorted normalized names, kept at commit), `created_by`; unique (`id`, `kind`); unique (`starts_at`, `interview_text_key(location)`, `committee_key`) |
| `interviews` | uuid; `application_id`, `round` (unique per application), `sitting_id` (NOT NULL), `scheduled_at` (the candidate's time in the sitting), `status` (`scheduled`, `recorded`, `cancelled`), current result: `result` (`passed`, `failed`, `absent`), `held_at`, `scientific_grade`, `general_grade`, `overall_grade` (`excellent` … `weak`), `committee_summary`, `notes`, `recorded_by/at`; `cancelled_at/by`, `cancel_reason`; `created_by` |
| `interview_members` | `sitting_id` + `sitting_kind` (NOT NULL; composite foreign key to the sitting's `(id, kind)`, RESTRICT), `position` (unique per sitting), `name`, `capacity` (`chair`, `member`, `secretary`; one chair at most), `staff_user_id`; one member at most in a one-off sitting (partial unique index) |
| `interview_reschedules` | `from_sitting_id`, `to_sitting_id`, `from_scheduled_at`, `to_scheduled_at` (something changed), `reason`, `rescheduled_by`, `created_at`; append-only |
| `interview_result_revisions` | `revision` (1 as recorded, then corrections; a reason exactly for corrections), the result fields, `entered_by`, `created_at`; append-only |

Status, result, grades, kind and capacity are enum columns (a CHECK each). CHECKs on `interviews`: a result exactly when recorded, with `held_at` (not after `recorded_at`), who and when; an attended result has the three grades, an absence none; a cancellation has who and a reason. One `scheduled` interview per application (partial unique index). `application_status_events.interview_id` is the cause column.

| Trigger | Rule |
| ------- | ---- |
| `interviews_no_delete` | Interviews are never deleted |
| `interviews_within_sitting` | `scheduled_at` and `held_at` not before the sitting's start; share-locks the sitting |
| `interview_sittings_guard` | A sitting keeps its kind and cannot start after an interview booked or held in it |
| `interview_sittings_have_members`, `interview_members_keep_one` (deferred) | At commit, every sitting has at least one member |
| `interview_members_frozen` | No member is added, changed or removed in a sitting with a recorded result |
| `interview_members_key_sitting` (deferred) | At commit, the sitting's `committee_key` is computed from its members (`interview_committee_key`); the unique index then refuses a second sitting with the same start, place and members |
| `interview_members_staff_name` | A member with a staff account carries the account's name (`staff_user_display_name`: each name capitalised, as the application shows it), set on every write (D-076) |
| `staff_users_rename_members` (on `staff_users`) | A renamed account renames its seats on sittings without a recorded result; sittings with a result keep their names |

`interview_text_key(text)` compares place and names without the differences of typing: spaces collapsed, ة→ه, ى→ي, أ/إ/آ/ٱ→ا, tatweel removed, lower case.

### 12.3 Rules

- **Schedule:** the application is `awaiting_interview` (passed the exams, or by exception) and has no waiting interview. The sitting (committee or one-off) gives place and who interviews; the candidate's time defaults to its start and cannot be before it. Round = previous rounds + 1.
- **Book many:** applications in the order given, from `first_at` every `minutes_between` minutes, into a sitting of either kind; one refused application books none.
- **Queue:** applications awaiting their interview with none booked, with the applications list's filters, ordered by `priority` rules: `specialization_id:5,2` puts those entries first (also `job_position_id`, `nominating_entity_id`, `application_category_id`, `academic_qualification_id`, `residence_district_id`, `residence_area_id`); `quran_parts` (desc), `studying_years` (desc), `age` (asc: youngest first), `waiting_since` (asc), `nominated_at` (asc) with optional `:asc`/`:desc`. Ties: longest waiting first. Each row has its `position` and `waiting_since`.
- **Reschedule / cancel:** a waiting interview only, with a reason (reschedules are kept). Another sitting (`sitting_id`; the time defaults to its start), a new time (`scheduled_at`; alone, in the same sitting), or both.
- **Members:** a staff account (`staff_user_id`, active), whose name is the account's, or someone else by `name`; not both (D-076).
- **Delete a sitting:** only one nothing was booked into: no interview (even cancelled) and no reschedule out of it; its members go with it.
- **Sittings:** a committee needs a `committee_name`; a one-off sitting has exactly one member. Members can change until a result is recorded in the sitting; it cannot start after its bookings; the kind never changes. Moving a sitting does not move the interviews booked in it. A sitting with the same start, place and members as another is refused (422 `duplicate_sitting` with the other's id; `duplicate_sitting_now` when another user committed it at the same moment).
- **Record:** the application is still `awaiting_interview`. `held_at` defaults to the appointment and cannot be in the future, before the sitting starts or before the previous decision. passed → `awaiting_contract`, failed → `interview_failed`, absent → `interview_absent` (outcome event, cause = the interview, dated `held_at`). Revision 1 is written.
- **Correct** (individual `interviews.correct`, reason): any result field; a new revision. A changed result appends a `correction` event (D-060), only while the interview's outcome is the application's latest decision; otherwise only grades and notes can be corrected.
- **Absence excuse** (individual `exceptions.approve`, reason, optional date): `interview_absent` → `awaiting_interview` (`excuse_accepted`, cause = the absent interview). The absent result stays; with `appointment`, the next round is booked in the same transaction. "Excused" in reports = an `excuse_accepted` event caused by that interview.

### 12.4 API reference

All under `/api/v1/staff`. Reading: `interviews.manage`, `candidates.manage` or `reports.view`. Changing: `interviews.manage`, except correcting (`interviews.correct`) and excuses (`exceptions.approve`).

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/interview-sittings` | Sittings (`kind` committee or one_off narrows; `member` = a staff user's id: the sittings they sit on), `from`, `to`; soonest first; with members and `interviews_count` |
| GET | `/interview-sittings/{id}` | A sitting with its committee and interviews (the interviews carry `sitting_id`, not the sitting again) |
| POST | `/interview-sittings` | `kind` (committee, default; one_off), `starts_at`, `location`, `committee_name` (committee only), `members` [`capacity` + `staff_user_id` or `name`] (one for one_off), `notes` |
| PATCH | `/interview-sittings/{id}` | Fields that change (`kind` refused); `members` replaces the committee (422 once a result is recorded); a committee keeps its name |
| DELETE | `/interview-sittings/{id}` | A sitting nothing was booked into (204; 422 `sitting_in_use`) |
| POST | `/interview-sittings/{id}/bookings` | `application_ids` (in order), `first_at`, `minutes_between` (30); 201 with the interviews |
| GET | `/interviews/queue` | Applications to book, by `priority[]` rules and the applications list's filters |
| GET | `/interviews` | `status`, `result`, `excused` (with `result=absent`), `sitting_id`, `committee_name`, `job_position_id`, `nominating_entity_id`, `from`, `to` |
| GET | `/interviews/{id}` | With reschedules (from/to sitting and time) and every result revision |
| GET | `/applications/{id}/interviews` | Every round of an application |
| POST | `/applications/{id}/interviews` | Schedule: `sitting_id` + optional `scheduled_at` |
| POST | `/interviews/{id}/reschedule` | `sitting_id` and/or `scheduled_at` (what is not sent is kept) + `reason` |
| POST | `/interviews/{id}/cancel` | `reason` |
| POST | `/interviews/{id}/result` | `result`, grades (attended), `held_at`, `committee_summary`, `notes` |
| POST | `/interviews/{id}/correct` | Fields that change + `reason` (`interviews.correct`) |
| POST | `/applications/{id}/interview-excuse` | `reason`, `occurred_at`, optional `appointment` (201 with the new interview, or 204) (`exceptions.approve`) |

Grades: `excellent` (ممتاز), `very_good` (جيد جداً), `good` (جيد), `acceptable` (مقبول), `average` (وسط), `weak` (ضعيف); capacities `chair`, `member`, `secretary`; both returned as `{value, label}`. An interview returns its `sitting_id` and `sitting` (`id`, `kind`, `starts_at`, `location`, `committee_name`) and repeats `location`, `committee_name` and `members` from it, except inside one sitting's page, where the sitting is shown once.

### 12.5 Seeding

`InterviewsDemoSeeder` (local): a sitting three days ago with four recorded results (passed, passed, failed, absent), a sitting in five days with two bookings, a one-off sitting (one interviewer) in two days with the remaining candidates (one in the demo data).

## 13. Contracts module

### 13.1 What it does (D-064)

The contract step (analysis doc §08). The **first contract** is written after a passed interview and moves the application to `contract_written`. Contracts are **renewed**: a renewal is a new contract that renews the latest one, so an application has a chain 1 → 2 → 3. When the latest contract ends without renewal, staff **record the end** and the application becomes `contract_ended` (the person may be nominated again). "Written" does not mean signed or appointed (§08). Two weeks before a contract ends, staff are reminded in the system (section 14).

```
app/Modules/Contracts/
├── Actions/       ManageContracts (write, renew, correct, end), RemindExpiringContracts
├── Console/       RemindExpiringContractsCommand (contracts:remind-expiring, daily)
├── Database/      Migrations (1), Seeders (ContractTypesSeeder, ContractsDemoSeeder)
├── Enums/         ContractState
├── Http/          Controllers (Contract), Requests, Resources
├── Models/        Contract (StatusChangeCause), ContractType (managed list)
├── Notifications/ ContractEndingSoon
└── routes.php
config/contracts.php   reminder_days (14)
```

### 13.2 Database schema

| Table | Notes |
| ----- | ----- |
| `contract_types` | Managed list like the Recruitment lists (`name_ar`, `name_key` unique, archived); API type `contract-types` |
| `contracts` | uuid; `application_id`, `sequence` (unique per application), `renews_contract_id` (unique; composite key with the application), `contract_type_id`, `reference_number` (unique), `written_on`, `starts_on`, `ends_on` (null: no end), `document_id` (unique; composite key `(document_id, application_id)` → `documents (id, application_id)`), `notes`, `ended_on`, `end_reason`, `ended_by`, `ended_at`, `expiry_reminded_at`, `created_by` |

CHECKs: sequence 1 exactly when it renews nothing; reference not blank; end ≥ start; the end fields all empty or all set with a reason, not before the writing date. One ended contract per application (partial unique index). Triggers: never deleted; a renewal takes the next number, starts at the previous end or later (the previous needs an end), and does not renew an ended contract; a renewed contract does not end after its renewal starts and cannot be ended. `application_status_events.contract_id` is the cause column (first contract, end).

### 13.3 Rules

- **Write:** the application is `awaiting_contract`. `written_on` defaults to today and is not in the future nor before the previous decision; `starts_on` defaults to it. The status event is dated the writing day (the end of that day; now when written today).
- **Renew:** the latest contract (not renewed, not ended) of an application under contract, with an end date. `starts_on` defaults to the day after that end. Late renewals are allowed.
- **Correct** (reason): any field; audited with before and after. A new end date clears `expiry_reminded_at`.
- **End:** the latest contract, `ended_on` (default today, not in the future, not before the writing date), reason. `contract_written` → `contract_ended` (outcome, cause = the contract, with the reason).
- **States** (computed, `state` filter): `upcoming` (starts later), `active`, `ending_soon` (ends within 14 days), `expired` (end passed, neither renewed nor ended), `renewed`, `ended`.

### 13.4 API reference

All under `/api/v1/staff`. Reading: `contracts.manage`, `candidates.manage` or `reports.view`. Changing: `contracts.manage`. Contract types: `/reference/contract-types` (read by any staff user, changed with `candidates.manage`).

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/contracts` | Soonest end first; `state`, `q` (name, nomination or reference number), `contract_type_id`, `job_position_id`, `nominating_entity_id`, `ends_from`, `ends_to`, `latest`, `per_page` |
| GET | `/contracts/{id}` | One contract |
| GET | `/applications/{id}/contracts` | The chain of an application |
| POST | `/applications/{id}/contracts` | First contract: `contract_type_id`, `reference_number`, `written_on`, `starts_on`, `ends_on`, `document_id`, `notes` |
| POST | `/contracts/{id}/renew` | Same fields; 201 with the renewal |
| PATCH | `/contracts/{id}` | Fields that change + `reason` |
| POST | `/contracts/{id}/end` | `ended_on`, `reason` |

A contract returns `sequence`, `renews_contract_id`, `renewed_by_contract_id`, `type`, dates, `state` `{value, label}`, `days_left`, `document`, `ended`, `expiry_reminded_at`.

### 13.5 Seeding

`ContractTypesSeeder` (every environment): suggestions (عقد سنوي، عقد مؤقت، عقد لمدة محددة) until management sends the contract form. `ContractsDemoSeeder` (local), on the demo applications awaiting their contract: one running for months, one ending in 10 days (its reminder is sent), one expired without renewal, one renewed, one ended without renewal, one without an end date; three stay awaiting.

## 14. Notifications module (D-065)

In-system notifications for staff: Laravel database notifications in `notifications` (uuid, `type`, `notifiable_type`/`notifiable_id` uuid, `data` jsonb, `read_at`). A notification returns `id`, `kind`, `title`, `message`, `details`, `read_at`, `created_at`.

The first kind is `contract_ending_soon` (details: `contract_id`, `application_id`, `nomination_number`, `candidate_name`, `reference_number`, `ends_on`, `days_left`). `contracts:remind-expiring` runs every day at 06:00 UTC (`routes/console.php`; the server needs `* * * * * php artisan schedule:run`). Each latest contract under contract ending within 14 days and not reminded yet notifies its writer and every active staff user holding `contracts.manage` (one notification each), then records `expiry_reminded_at`.

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/notifications` | Mine, newest first, 25 per page; `unread=1` |
| GET | `/notifications/unread-count` | `{unread}` |
| POST | `/notifications/{id}/read` | Mark one of mine read (404 for anyone else's) |
| POST | `/notifications/read-all` | `{marked}` |

Any signed-in active staff user; nobody sees another user's notifications.

## 15. Reports module (D-066, D-068, D-069)

The dashboard, the staff's reports after the legacy workbook, the list for شعبة التجنيد, and the results for candidates.

```
app/Modules/Reports/
├── Exports/       ReportHeader (organization lines, logo, title, period, date, issuer, filters), ReportWorkbook (Excel in the
│                  legacy layout), ConscriptionList (the شعبة التجنيد template), Pdf (mPDF, RTL), ResultsSpreadsheet
├── Http/          Controllers (Report, ResultsReport)
├── Queries/       ApplicationStages (nomination and exam decisions from the history), Dashboard, CandidateResults
├── Support/       ReportPeriod, ReportFilters, ReportTable
├── Tables/        RecruitmentTables (applications, exams, interviews, dashboard as tables)
└── routes.php
resources/views/reports/   tables, results-statement, results-list
config/reports.php         organization and department lines, logo, military entity, time zone, row limits
```

### 15.1 Counting (D-068)

Every report takes the **applications list's filters** (which applications: nominating entity, job position, governorate, status, ...) and a **period**: `year` (with `month`), `period_from` / `period_to` (both included), or both (they must overlap). Days and months are those of `reports.timezone` (Damascus): an interview held at 22:30 UTC on 31 March counts on 1 April. Each figure is counted on its own event's date, so history is reported as it happened (AC-13):

| Figure | Date compared |
| ------ | ------------- |
| Nomination | the `created` event of the application |
| Exam decision (passed, failed, absent) | its outcome event; a correction replaces the result and keeps that date |
| Pass by exception | the exception event (counted apart from regular passes); the failure stays a failure, shown with "later admitted" |
| Accepted exam excuse | shown with the absence it excuses |
| Interview result | the day it was held; an excused absence has an `excuse_accepted` event caused by that interview |
| Contract | its writing date; first contracts (`sequence` 1) and renewals apart |

Takers are the attended decisions (passed or failed); pass rates are regular passes over takers (null without takers). Average days run from the nomination to the first exam decision, to the passed interview and to the first contract, for those in the period.

### 15.2 API

All under `/api/v1/staff`. Reading: `reports.view`, `candidates.manage` or `interviews.manage`; the شعبة التجنيد list needs `candidates.manage`. Every export is audited (`report.exported`, `report.workbook`, `report.conscription_list`, `report.results_statement`, `report.results_list`).

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/reports/summary` | Dashboard: `period`, `totals` (`nominations`; `exams`: takers, passed, passed_by_exception, failed, failed_then_excepted, absent, absent_excused, pass_rate_percent; `interviews`: takers, passed, failed, absent, absent_excused, pass_rate_percent; `contracts`: written, renewals; `average_days`: to_exam, to_interview, to_contract), `monthly` (the period's months, or from the first nomination; at most 60), `by_nominating_entity`, `by_job_position`, `filters` (readable) |
| GET | `/reports/{report}` | `applications`, `exams`, `exams-passed`, `exams-failed`, `exams-absent`, `interviews`, `interviews-passed`, `interviews-failed`, `interviews-absent`, `dashboard`; `format` `xlsx` (default) or `pdf`; interviews also take `sitting_id`; sorted by nomination number unless `sort` |
| GET | `/reports/workbook` | All of the above as one Excel workbook: the dashboard first, with links to the other sheets |
| GET | `/reports/conscription-list` | The شعبة التجنيد template (Excel): default status `awaiting_contract`, ordered by name |
| GET | `/applications/{id}/results-statement` | For the candidate (D-066): PDF statement of one application, without notes or reasons |
| GET | `/reports/results` | For candidates (D-066): the same for many, `xlsx` or `pdf` |

Limits: 20,000 rows per table in Excel, 2,000 in PDF (422 on `format` beyond: narrow the filters or the period).

### 15.3 Reports and their columns

| Report | Rows | Columns |
| ------ | ---- | ------- |
| applications (قاعدة بيانات المرشحين) | one per application (with a period: nominated in it) | nomination number, every field of the template (entity, job, category, employee number, name, mother, birth place and date, national number, marital status, health, governorate, residence, qualification, specialization, studying years with "ومنقطع", Quran parts, other government entity, overnight, full commitment, phone, notes), nomination date, status |
| exams (سجل الاختبارات ونتائجها) | one per exam decision in the period | nomination number, name, entity, qualification, residence, round, attendance, date, result (corrected marked), later decision (exception or accepted excuse, dated) and its reason |
| exams-passed / -failed / -absent | the decisions of that result (passed: regular passes and failures admitted by an exception) | as above, with the kind of pass, the exception, or the excuse |
| interviews (سجل المقابلات) | one per interview dated in the period (held, or else booked; cancelled ones too) | nomination number, name, entity, exam date and result, round, committee, members, place, appointment, status, day held, attendance, the three grades, result, committee's note, excuse |
| interviews-passed / -failed / -absent | recorded results by the day held | as above (absent: the note as the absence reason) |
| dashboard | the indicators, months, entities and job positions | as `/reports/summary` |
| conscription-list (قائمة شعبة التجنيد) | one per application | الاسم الثلاثي في الهوية، اسم الام والنسبة (the mother's name)، تاريخ الميلاد (day/month/year)، الحالة الاجتماعية، التحصيل العلمي (qualification)، شعبة التجنيد (place of birth)، مسمى العمل (job position)، الجهة العسكرية (`reports.military_entity`)، ملاحظات (empty) |

Excel files follow the legacy workbook (right to left, green and gold header with the organization, department and title, a line with period, date, issuer and filters, header row, frozen headers and filters, totals, landscape printing with the header rows repeated); text cells are text, so leading zeros survive and nothing becomes a formula. PDF uses the same header and colours (A3 for the wide tables). The legacy exam sheets' subject marks, total, percentage and failure cause are not in the exams report yet: they come with the exam attempts (D-056).

## 16. Testing

`php artisan test` (PostgreSQL database `recruitment_test`, `RefreshDatabase`): 662 tests. The suite needs no OpenSSL configuration: tests use fixed keys from `tests/Support/TestDeviceKeys.php` (D-023). Shared helpers are in `tests/Support/StaffTestHelpers.php` (devices get a unique MAC and an `os_info`), `tests/Support/RecruitmentTestHelpers.php` (one entry of every list, a valid application payload, granting an individual permission, closing an application) and `tests/Support/LegacyWorkbook.php` (synthetic workbooks in the real template's layout: three title rows, headers on row 5 with its exact texts, navigation column A, other sheets, text/number/date/formula cells; no real data, §24).

Tests never commit, so the deferred checks (`applications_have_history`, `interview_sittings_have_members`, `interview_members_keep_one`, `interview_members_key_sitting`) are tested with `SET CONSTRAINTS ... IMMEDIATE`, checking the error message so that another error cannot pass for the rule. `migrate:fresh --seed` commits for real and is part of every delivery check.

| Test file                                        | Covers                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Feature/DatabaseConventionsTest`              | UTC session; timestamps agree with the database clock                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `Feature/Identity/StaffUserTest`               | Username CHECK (blank, short, long, spaces, unsafe characters); trimmed and lowercased                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| `Feature/Identity/StaffAuthTest`               | Login through a device, generic failures, rate limit, one-day token, `me`, logout, deactivated user                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `Feature/Identity/DeviceLoginTest`             | Pending/revoked/unknown device, replay, expiry, wrong key, username binding, device owner, wrong password, challenge bound to device                                                                                                                                                                                                                                                                                                                                                                                        |
| `Feature/Identity/DeviceManagementTest`        | Registration (idempotent, MAC, key validation, pending cap), status, approval (fingerprint, owner, twice), revoke deletes tokens, list filter, mass-assignment guard, database CHECK                                                                                                                                                                                                                                                                                                                                        |
| `Feature/Identity/PermissionResolutionTest`    | Role permissions match the matrix; ✓* never implicit; grant/deny; unknown abilities                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| `Feature/Identity/AccessManagementTest`        | Role and user access management, `individual_only`, last-administrator protection, 404s, seeder does not overwrite; roles added and removed automatically with notes, a role given by hand clearing its overrides, an automatic role taken away with its grants, role changes rechecking everyone (D-070); an administrator's new password ends every session (D-071)                                                                                                                                                                                                                                                                                                                                                                                                        |
| `Unit/ApplicationStatusTest`                   | Legal moves, decision types, terminal and live statuses, labels                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `Unit/ArabicTextTest`                          | Search key and digit normalisation                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `Unit/RecruitmentNormalisationTest`            | National number and phone number normalisation                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `Feature/Recruitment/NominationNumberTest`     | Format, yearly restart, configurable format, no gaps after rollback                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `Feature/Recruitment/ApplicationLifecycleTest` | Moves, refusals, exceptions and excuses, closing, contract end, stale models, chain order and `from_status`, decision dates, cause columns (real FK, at most one, add/remove, safe names); database: append-only, no direct status write, status follows events, no fork, no second first event, no cross-application link, accountable decisions, dates, new application starts at `awaiting_exam`, no application without history                                                                                     |
| `Feature/Recruitment/ReferenceDataTest`        | Marital statuses read only (change endpoints 404), editable lists mirror the seeded rule; all nine lists: read, change, integer keys, spelling-variant duplicates, archive/restore; areas: district required, unique per district, rename stays in district, list per district; seeders                                                                                                                                                                                                                                                                                                                               |
| `Feature/Recruitment/ApplicationSearchTest`    | Yes/no filters on false, ranges with bounds and 0, one or several statuses, live/ended, list entries of the application and the person, ages and birth dates, numbers in any digits, who opened it, inactive people, sorting (name, number, date), page size and links, fixed query count per page |
| `Feature/Identity/StaffUserRegistrationTest`   | Account with its role (hashed password, audit), no role beyond one's own permissions without `users.manage`, digits-only unique personal number, strong confirmed password, `users.add` required |
| `Feature/Recruitment/ApplicationApiTest`       | Opening applications, nomination numbers, national number as text (11 to 20 digits), digit normalisation, person matching, one live application per person (any position; what frees a person), nothing left behind on failure, closing (permission, reason, dates, refusals), area in district (API and database), permissions, validation, database CHECKs, archived entries, search, filters, date range, history                                                                                                                          |
| `Feature/Recruitment/CandidateApiTest`         | People list and filters, search in any spelling or digits, one person's applications, corrections (audit, search key, national number conflict, same rules), deactivation (live application refused, reason, once, blocks nomination until reactivated), database CHECK, permissions |
| `Feature/Recruitment/ApplicationUpdateTest`    | Partial corrections and their audit, fields that never change here, ended application refused, district and area combinations, discontinued studies against stored values, same rules as create, permission |
| `Feature/SeedersTest`                          | Contracts demo (first contracts, a renewal, an end, a reminder, runs once); Interviews demo through the lifecycle (runs once); Document types (once) and demo documents (some missing, runs once); Exam subjects (template's six, Quran parts, re-run safe) and demo plans in every state; reference lists (no nominating entities, re-run safe, archived and respelled entries kept, areas per governorate); demo: every status, dates in the past, accountable decisions, nominated twice, deactivated, three import batches with every row kind, runs once, uses the owner's nominating entities |
| `Unit/Exams/ExamScorerTest`                    | The doc's five worked examples, reasons with subjects, tolerated subject, Quran from weighted parts (482/600), relative weights, exact values at 58/60/70 boundaries, thirds, policy versions, mismatched or impossible marks |
| `Feature/Exams/ExamSubjectApiTest`             | Subjects with parts, names unique in any spelling (parts per subject), archive/restore, part changes, weight format, permissions, audit |
| `Feature/Exams/ExamPlanApiTest`                | Drafts (empty, copied, from an older version of the same position only), whole replacement in exam order, questions per difficulty (each ≥ 1), parts assessed in the exam or by hand (defaults and overrides, scale), content checks, one draft per position, archived job position, delete, publish (freeze, audit, every problem listed, no online question, bank shortages per difficulty, archived questions not counted, whole subject draws on all its questions, archived subjects/parts), readiness, retiring the previous version, part defaults never reach a published plan, manual mark in the engine, list filters, permissions, version numbers |
| `Feature/Exams/ExamDatabaseTest`               | Policy 1 present and append-only; published content frozen; published → retired only; retiring changes nothing else; draft not retired directly; publication CHECKs; one published and one draft per position; part in its subject; every difficulty or none (NULL cannot slip through); manual part has a scale and no questions, exam part the reverse; part defaults checked; draft deletion cascades; question versions append-only and well formed; question part in its subject |
| `Feature/Exams/ExamQuestionApiTest`            | Version 1, part questions, well-formed questions, corrections as versions (none for same content or difficulty only), answer kept among the options, no moving questions, archive and stats, filters (part `none`, search with wildcards escaped), only `exam_bank.manage` |
| `Feature/Documents/DocumentApiTest`            | Person documents stored on the person from any screen, application documents on their application, kept for a new application, requirements by status and missing list, content checks (fake PDF, PDF named .png, docx, size), download (headers, content, audit), archive (reason, once, file kept), archived type, permissions, types management; database: never changed except archived once, right person and scope |
| `Feature/Interviews/InterviewApiTest`          | Members with an account carry its name (database, rename, inactive accounts, D-076); the sittings a staff user sits on; one sitting's interviews without the sitting repeated; deleting unbooked sittings; a committee keeps its name; sitting bookings (place, committee, time from the sitting start, not before it), one-off sittings (one member, many candidates, listed by kind, edited, kind kept), the same sitting refused twice (spaces and letter forms, order and capacities of members, other members / place / time accepted, also on change), at most one chair and known capacities, reschedules to another sitting or time, between committee and one-off sittings, sittings not starting after their bookings, bulk booking (order, times, all or nothing), queue priorities (lists first, values, age, longest wait, filters, refused rules), one waiting interview per awaiting application, reschedule and cancel with reasons, frozen committee, results moving the application with the interview as cause, grades by result, dates, application moved on, corrections (revisions, correction events, twice, only while latest, permission, reason), excuses (back to awaiting, next round, round 1 stays absent, excused filter, permission), lists and filters, rounds, permissions; database: result consistency, grades, cancellation, held after recorded or before the sitting, enums, no delete, one waiting, always in a sitting, time within the sitting, sitting start and kind, members need a sitting, one chair, a committee left at commit, frozen members, one member per one-off sitting (index, kind given wrong), the same sitting refused at commit, append-only revisions and reschedules |
| `Feature/Exams/ExamSessionApiTest`             | Sessions (draw once, answers only for `exam_bank.manage`, redraw and changes before the start, password any time, published plan, late window, bank short, delete only unbooked); bookings (queue, job position, one open, all or nothing, closed window); candidate login (session password, Arabic digits, window, one login at a time, guards apart, deactivated); taking the exam (not before the start, latecomer's shorter time, own order of the same questions, no answer key, saves with history, option and position checks, idempotent submit, closed answers); deadline closing (timeout, unanswered wrong); absence at the late window (cause, date, sweep idempotent); extensions (individual permission, one attempt, everyone still sitting, history, not after the end); results (decided when hand marks are in, dated the exam day, cause, outcome); failure reasons and exception; correcting a hand mark (reason, `exam_results.correct`, revision, correction event); plan without hand marks; excuse booking round 2; reading permissions; database guards (frozen draw and timing, bookings never deleted, deadlines only later, mark scale, one open booking, never reopened, closed answers, append-only history, marked once) |
| `Feature/Exams/PassPolicyApiTest`              | Version 1 current, a new version used by plans published afterwards only, individual permission, consistent rules, versions never changed |
| `Feature/Contracts/ContractApiTest`            | First contract (cause, dated the writing day, defaults, only awaiting contract, once), rules (reference unique in any digits, dates, type, file of the application), renewals (chain, default start, renewed once, not before the end, no end date refused, late renewal), corrections (audit, chain kept, nothing changed, reason), new end date clears the reminder, end (reason, date, contract_ended with cause, no renewal after, nominated again, only the latest), states and filters, permissions, contract types list; database: delete, period, end fields, sequence, renewal start, same application, number 2 renews, file of the application, renewed once, only the latest ends |
| `Feature/Contracts/ContractReminderTest`       | Two weeks before: writer and every active `contracts.manage` holder (role or grant, not denied, not inactive), once, one each; not too early, not without end, not expired, not renewed; message and details; own notifications only (list, unread, read one, read all, 404 for another's), signed in; scheduled daily |
| `Feature/Reports/StaffReportsTest`             | One story of four candidates: dashboard totals (exceptions and excuses apart, pass rates, average days), each event on its date, Damascus days, months, entities and job positions, the list's filters, period checks; exams report (decisions, later exception or excuse, reasons, totals, audit) and its views; interviews report and views (members, appointment, grades, note, excuse); applications report (every field, text national number, "ومنقطع", period); PDF of four reports; dashboard as tables; the whole workbook (sheets, links); row limits; the شعبة التجنيد list (template columns, birth place, military entity, date format, default status); permissions |
| `Feature/Reports/ResultsReportTest`            | PDF statement (headers, number, dates, audit; exam outcome with exception, grades; no notes or reasons), Excel list (RTL, header, text cells, rows, totals), filters printed readable, PDF list, format, row limit, permissions |
| `Unit/HeaderMatcherTest`                       | Header normalisation, exact before contained, similar headers, unknown headers                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `Feature/Imports/ImportUploadTest`             | Real template layout by header names, a row without national number refuses the file, security (unsafe parts, zip bomb, unsafe formulas injected in the sheet XML, content vs extension, filename, cell bounds, nosniff, rate limit), moved/renamed headers, sheet among others, empty rows, missing columns, no header, no rows, not a workbook, extensions, row limit, same file twice and after cancel (AC-10), batch metadata and default entity, permission, list, original download                                   |
| `Feature/Imports/ImportRowsTest`               | Values and raw cells, optional area (place kept as details), study years with "ومنقطع", dates (serial, 1904, text, Arabic digits, AC-17), leading zeros (AC-16), digits, yes/no, list spellings, unknown/archived values, health and residence details, area per district, missing values, shared rules, in-file duplicates, live application, person mismatch and confirmation, corrections (audit, re-check, duplicates elsewhere, malformed), revalidation, exclude/include, filters, scoped rows, cancelled batch |
| `Feature/Imports/ImportApprovalTest`           | Approval permission, uploader cannot approve, approver reads but cannot change, only valid rows imported, consecutive numbers, every value carried, reviews block approval, confirmed person keeps stored data, re-upload in another order (AC-19), all-or-nothing on failure, one reservation without gaps, approved/cancelled batches frozen, cancel reason, audit, report                                                                                                                                                |

API documentation check: `php artisan scramble:analyze` must report no warnings.

## 17. Known limitations and pending work

- The legacy exams sheet decides results differently from the analysis doc (no 58 tolerance; exactly 70% passes); the system follows the doc, as the owner confirmed (OD-15). Results copied from old sheets can differ.
- The interview grade scale is the template's six levels and the member capacities are three (رئيس، عضو، مقرر), fixed in code (`InterviewGrade`, `MemberCapacity`); the committee's evaluation form from management may add items (§22).
- Archiving questions after a plan is published can leave the bank short: creating (or redrawing) a session refuses and says what is missing.
- The session password is shared by its candidates and national numbers are not secret: a candidate of the session who knows a neighbour's number could log in as them. A new login logs the other out and both are logged; supervision in the hall is the guard. Restricting candidate login to the venue's network is not built (D-072).
- Absences and closing at the deadline need the scheduler (`exams:sweep` every minute); without it they still happen whenever staff open the session or the candidate touches their attempt (D-073).
- A candidate's answers are not shown to staff yet (only counts per attempt); an answer sheet per attempt can be added for `exam_bank.manage`.
- Documents are not scanned for viruses; scanning and a retention period belong to the deployment, like import files (D-045, D-057).
- Nominating entities are typed by hand in the template: an entity not in the list (seeded or added through the API) keeps its rows from being approved (OD-13).
- Antivirus scanning of stored originals and a retention period for files with personal data belong to the deployment (D-045).
- Upload and approval run inside the request (max 2,000 rows by default). Larger files need a queued approval (D-041).
- `composer.lock` must be updated on a machine with Packagist access (`composer update phpoffice/phpspreadsheet`).
- Migrations of Recruitment were changed in place again in Step 4: rebuild development databases with `migrate:fresh --seed`. Before the first production deployment, migrations must become append-only.
- Exam outcomes recorded before Step 10 (demo history) have no booking as cause; those of exam sessions do (D-074). The results report still shows the exam outcome from the history, without marks.
- `mpdf/mpdf` is required in `composer.json` (^8.3, the owner's commit).
- Contract types are suggestions until management sends the contract form; contract fields may grow with it (§19).
- Reminders need the scheduler running on the server (`schedule:run` every minute); without it nothing is reminded. Reminders are in the system only (no e-mail or push).
- Interview priorities are chosen per request and not saved; exam results (now recorded) are not a priority rule yet.
- The staff exams report has no subject marks, total, percentage or failure cause yet (the legacy sheets have them). Exam sessions record them now (`exam_results.outcome`); adding them to the report is next.
- The شعبة التجنيد list's "اسم الام والنسبة" holds the mother's name as stored: the system has no separate field for her family name.
- The dashboard and reports load the filtered applications in memory: fine for tens of thousands; beyond that they should move to SQL aggregation or a queued export (analysis doc §20 "توليد تقرير في طابور").
- National numbers are stored in plain text (D-032): protect database backups.
- The legal moves are checked in PHP; the database guarantees the chain, the status copy, the dates and one live application per person, not which moves are legal.
- Date filters of the lists and the nomination-number year use UTC days (the server is UTC; Damascus is UTC+3); reports count Damascus days (`reports.timezone`).
- Staff users are added (D-058) and their password reset by an administrator (D-071); deactivating a staff user through the API is not built yet. `must_change_password` is deferred (D-015).
- No audit table: security, access and import events go to the application log.
- Web fallback for staff is deferred (OD-3).
- A new permission introduced later must be attached to roles explicitly; the seeder only defaults new roles (D-020). `applications.close` is individual-only, so it is never attached to a role.
- Production needs trusted-proxy configuration for IP-based throttling.
- Arabic translations exist for login, recruitment and import messages; framework validation messages do not have an Arabic file yet.

## 18. Changelog

| Step          | Summary                                                                                                                                                                                                                                                                                                                                                                                                           |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1             | Foundation: project, PostgreSQL, module loader, tests on PostgreSQL                                                                                                                                                                                                                                                                                                                                               |
| 2             | Identity & RBAC: separate staff table (D-005), RESTRICT foreign keys (D-007), UTC connection (D-004), username constraint (D-008), `LoginStaff` action (D-010), two-level throttling (D-013), active-account middleware (D-014)                                                                                                                                                                                 |
| 2 (follow-up) | Unescaped Arabic JSON (D-018); Scramble with Bearer scheme (D-019)                                                                                                                                                                                                                                                                                                                                                |
| 2b            | Device-bound staff login (D-017); single device owner, enum status, macaddr (D-022); one-week staff tokens (D-009); Arabic usernames (D-008); access management API (D-020); mass-assignment policy (D-021); fixed test keys (D-023); `must_change_password` deferred (D-015)                                                                                                                                   |
| 3             | Recruitment core: managed lists, candidates, applications, nomination number, status lifecycle with append-only history, encrypted national ID with blind index, sensitive-data permission (D-024 to D-030); one approved device per user and required MAC (D-022)                                                                                                                                                |
| 3 (completed) | Owner's schema redesign finished: nine lists with integer keys, areas inside districts with a composite key (D-031, D-034); person vs application data (D-031); plain national number, sensitive permission removed (D-032); chained history with database-maintained status (D-033); Latin-only usernames (D-035); one-day staff tokens (D-036); recruitment tests restored and extended                         |
| 4             | One live application per person, `contract_ended` and `closed`, closure with `applications.close` (D-037); cause columns instead of `related_type/id` (D-038); `occurred_at` as the real decision date (D-039); Excel import of the legacy template: header detection, cell reading, checks, review, locked all-or-nothing approval, report (D-040, D-041); shared field limits (D-042); PhpSpreadsheet |
| 4 (follow-up) | Real legacy template: its headers, one sheet loaded, 30 MB uploads; residence area optional (D-043); `studies_discontinued` (D-044); upload security (D-045); a row without national number refuses the file (D-046); `imports.approve` with four eyes (D-047); reference API: enum `{type}`, `include_archived=true`, documented `district_id`; Arabic validation messages                             |
| 10 (Exam sessions) | On the owner's commit 3a1fc7e plus step 9. Staff user relations to what they did; members with an account carry its name (database), unused sittings deleted, one sitting's interviews without the sitting repeated, `member` filter, create and update requests apart, `@ignoreParam` for `prohibited` (D-076); roles follow the direct permissions, with notes (D-070); an administrator resets a forgotten password and ends the sessions (D-071); exam sessions: candidate login with the national number and the session's password (D-072), late window, one end, extensions for one or everyone, absences, `exams:sweep` (D-073), bookings, queue, excuses and exceptions (D-074), attempts in the candidate's own order with saved answers, server deadline, hand marks, automatic results and corrections (D-075); `exams.manage`, `exam_results.correct`; demo sessions |
| 9 (Reports) | On the owner's commit 3a1fc7e (docs of step 8 restored). One-off sittings as one interviewer holding many candidates, duplicate sittings refused by start, place and members (D-067); dashboard indicators and staff reports after the legacy workbook in Excel / PDF, the whole workbook (D-068); the شعبة التجنيد list (D-069) |
| 8 (Contracts) | On the owner's commit 8f789d8 (after step 7). Every interview in a sitting, one-off appointments as sittings of one, enums, committee guaranteed and frozen by the database, times within the sitting, no cascade (D-061); interview queue by staff priorities and bulk booking (D-062); pass rules versions through the API, `pass_policies.manage` (D-063); national number 11 to 20 digits (D-032); Contracts module: first contract, renewals, corrections, end without renewal (D-064); staff notifications and contract expiry reminders (D-065); results report for candidates, PDF and Excel, mPDF (D-066) |
| 7 (Interviews) | On the owner's commit 8f789d8. Interviews module: sittings and one-off appointments, committee results moving the application, reschedules, cancellations, revisions and corrections, absence excuses (D-059); correction events in the lifecycle (D-060); `interviews.correct` |
| 6 (follow-up) | On the owner's commit d247aa4. Complete application search (`ApplicationSearch`, sorting, page size); list rows load all their relations; areas with their district without a query each; deactivation CHECK requires all or nothing (D-049); adding staff users through `RegisterStaffUser` with a role-escalation guard (D-058); route files merge `api` and `auth` middleware where every route is authenticated |
| 6 | On the owner's commit 77f2408. Null `studies_discontinued` means false (D-044); marital statuses unknown to the change endpoints (D-048); nominating entities seeded by the owner (OD-13); the doc's pass rules confirmed (OD-15); plans: one session, parts marked by hand, easy/medium/hard (D-054); question bank (D-055); sessions decided (D-056); documents (D-057) |
| 5 (Exams part 1) | Subjects and parts; exam plans per job position, versioned and frozen when published (D-053); pass policy as append-only data and the exact scoring engine (D-052); open questions OD-15 to OD-17 |
| 4 (follow-up 2) | Marital statuses read-only through the API (D-048); people and live applications corrected, people deactivated (D-049); seeders for every list and local demo data (D-050); hexadecimal fingerprint rule (D-051); `studies_discontinued` defaults to false in the model |
