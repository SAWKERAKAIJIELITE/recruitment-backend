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
| Identity: user management (create, deactivate, reset password) | Planned |                                                                                                                                                                                                        |
| Identity: candidate accounts and login                         | Planned | After the Recruitment core (D-016)                                                                                                                                                                     |
| Recruitment                                                    | Done    | Nine managed lists (areas inside districts), candidates, applications, nomination number, chained status history with database-maintained status, one live application per person, closing, corrections, deactivating people (section 8) |
| Imports                                                        | Done    | Legacy Excel template: upload, column detection, row checks, corrections and review, atomic approval, report (section 9)                                                                               |
| Exams                                                          | Started | Subjects and parts, versioned exam plans (one session, parts marked by hand, easy/medium/hard), question bank, pass policy as data, exact scoring engine (section 10). Next: sessions and attempts (D-056) |
| Documents                                                      | Done    | Document types, uploads checked by content, download, archive, missing documents per application (section 11)                                                                                       |
| Interviews, Contracts, Reports                                 | Planned |                                                                                                                                                                                                        |
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
| `config/auth.php`                                      | Guards `web` (kept for Sanctum) and `staff`; provider `staff_users`                                                 |
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
| Candidate | `candidate_accounts` | `CandidateAccount` | `auth:candidate` | Planned |

Both use Sanctum tokens stored in the polymorphic `personal_access_tokens` table (custom model `AccessToken`, which adds `staff_device_id`).

### 7.2 Database schema

**`staff_users`**: `id` uuid PK; `personal_number` unique; `username` varchar(50) unique, CHECK allows only `a-z . _ -`, 3 to 50 characters (D-035); `first_name`, `last_name` (CHECK: at least 3 characters, no digits); `password`; `is_active` (default true); `last_login_at` timestamptz; timestamps.

**`roles`**: `id`, `slug` unique, `label_ar`, timestamps.

**`permissions`**: `id`, `slug` unique, `label_ar`, `individual_only` boolean (true = can never be attached to a role), timestamps.

**`permission_role`**, **`role_staff_user`**: composite-key link tables, all foreign keys `RESTRICT`.

**`permission_staff_user`**: per-user override. `is_granted` true = grant, false = deny; `granted_by` = the administrator who made the change.

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
| `attempts.extend`    |       ✓*       |        ✓*        |     ✓*     |
| `exceptions.approve` |       ✓*       |        ✓*        |     ✓*     |
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

The seeder gives a role its defaults only when the role is first created; afterwards administrators own the data through the access-management API (D-020).

### 7.4 Effective permissions

```
effective = (permissions from all of the user's roles + direct grants) − direct denials
```

`StaffUser::permissionSlugs()` computes it (cached per instance for the request). `Gate::before` answers any ability named like a permission slug with `true` when the staff user holds it, otherwise `null` (never `false`), so Policies still apply. There is no super-admin bypass.

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
| GET    | `/users`, `/users/{user}`              | List; detail with roles, overrides, effective permissions                   |
| POST   | `/users/{user}/roles`                    | Body `role`                                                               |
| DELETE | `/users/{user}/roles/{role}`             | 404 if the user does not hold it                                            |
| POST   | `/users/{user}/permissions`              | Body `permission`, `effect` = `grant` or `deny`                     |
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
| national_number        | varchar(20) column, unique, CHECK exactly 11 digits; stored as given (D-032) |
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
| *cause columns* | added by later modules, one nullable FK per cause (`exam_attempt_id`, …), at most one set per event (D-038, section 8.5)                |

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

Terminal statuses: `contract_ended`, `closed`; everything else is live. `ApplicationLifecycle::move($application, $to, $type, actor:, reason:, cause:, occurredAt:)` locks the application row, reads its status from the database, refuses any move not in `ApplicationStatus::transitions()`, any wrong decision type, a person-made decision without reason and actor, and a date in the future or before the previous decision (`IllegalStatusTransition`). It then appends the event after the latest one and reloads the caller's model (the database has changed `current_status`). `open()` writes the first event inside the transaction that creates the application, without a savepoint of its own. `Application::history()` returns the events in chain order with each event's previous event attached, so `fromStatus()` costs no extra query.

### 8.5 Causes of a status change (D-038)

A module whose records cause moves (Exams, Interviews, Contracts) adds its column in its own migration and makes its model implement `StatusChangeCause`:

```php
// migration of the Exams module
StatusEventCauseColumns::add('exam_attempt_id', 'exam_attempts');   // up(): nullable uuid FK, RESTRICT, index
StatusEventCauseColumns::remove('exam_attempt_id');                 // down()

// model
class ExamAttempt extends Model implements StatusChangeCause
{
    public static function statusEventColumn(): string { return 'exam_attempt_id'; }
}

// when the result is final
$lifecycle->move($application, ApplicationStatus::ExamFailed, DecisionType::Outcome, cause: $attempt, occurredAt: $attempt->finished_at);
```

Every `add`/`remove` rebuilds the CHECK `application_status_events_at_most_one_cause` over all cause columns. The helper accepts only safe identifiers.

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
| `RecruitmentDemoSeeder` | `APP_ENV=local` | 38 invented people (national numbers `09…`), 39 applications in every status with dated histories (outcomes, an exception, accepted excuses, closures), a person nominated twice, a deactivated person |
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
| National number         | 11 digits as text; separators dropped                                                                                                                                                                        | a number cell with fewer digits (its leading zero is gone): error with the suggested value; letters |
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

What each job position is examined on, the question bank, and how a result is decided (analysis doc §04, §05). Sessions, candidate accounts and attempts come next (D-056); they will use the plan version, the bank and the engine built here.

```
app/Modules/Exams/
├── Actions/       ManageExamSubjects, ManageExamPlans (drafts, replace, readiness, publish, delete), ManageQuestions
├── Database/      Migrations (4), Seeders (ExamSubjectsSeeder, ExamsDemoSeeder)
├── Enums/         ExamPlanStatus, Assessment (exam | manual), Difficulty (easy | medium | hard)
├── Http/          Controllers (ExamSubject, ExamPlan, ExamQuestion), Requests, Resources
├── Models/        ExamSubject, ExamSubjectPart, PassPolicy, ExamPlanVersion, ExamPlanSubject, ExamPlanSubjectPart,
│                  ExamQuestion, ExamQuestionVersion, Concerns/HasDifficultyCounts
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

## 12. Testing

`php artisan test` (PostgreSQL database `recruitment_test`, `RefreshDatabase`): 552 tests. The suite needs no OpenSSL configuration: tests use fixed keys from `tests/Support/TestDeviceKeys.php` (D-023). Shared helpers are in `tests/Support/StaffTestHelpers.php` (devices get a unique MAC and an `os_info`), `tests/Support/RecruitmentTestHelpers.php` (one entry of every list, a valid application payload, granting an individual permission, closing an application) and `tests/Support/LegacyWorkbook.php` (synthetic workbooks in the real template's layout: three title rows, headers on row 5 with its exact texts, navigation column A, other sheets, text/number/date/formula cells; no real data, §24).

Tests never commit, so the deferred `applications_have_history` check is tested with `SET CONSTRAINTS applications_have_history IMMEDIATE`.

| Test file                                        | Covers                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Feature/DatabaseConventionsTest`              | UTC session; timestamps agree with the database clock                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `Feature/Identity/StaffUserTest`               | Username CHECK (blank, short, long, spaces, unsafe characters); trimmed and lowercased                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| `Feature/Identity/StaffAuthTest`               | Login through a device, generic failures, rate limit, one-day token, `me`, logout, deactivated user                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `Feature/Identity/DeviceLoginTest`             | Pending/revoked/unknown device, replay, expiry, wrong key, username binding, device owner, wrong password, challenge bound to device                                                                                                                                                                                                                                                                                                                                                                                        |
| `Feature/Identity/DeviceManagementTest`        | Registration (idempotent, MAC, key validation, pending cap), status, approval (fingerprint, owner, twice), revoke deletes tokens, list filter, mass-assignment guard, database CHECK                                                                                                                                                                                                                                                                                                                                        |
| `Feature/Identity/PermissionResolutionTest`    | Role permissions match the matrix; ✓* never implicit; grant/deny; unknown abilities                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| `Feature/Identity/AccessManagementTest`        | Role and user access management, `individual_only`, last-administrator protection, 404s, seeder does not overwrite                                                                                                                                                                                                                                                                                                                                                                                                        |
| `Unit/ApplicationStatusTest`                   | Legal moves, decision types, terminal and live statuses, labels                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `Unit/ArabicTextTest`                          | Search key and digit normalisation                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| `Unit/RecruitmentNormalisationTest`            | National number and phone number normalisation                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `Feature/Recruitment/NominationNumberTest`     | Format, yearly restart, configurable format, no gaps after rollback                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| `Feature/Recruitment/ApplicationLifecycleTest` | Moves, refusals, exceptions and excuses, closing, contract end, stale models, chain order and `from_status`, decision dates, cause columns (real FK, at most one, add/remove, safe names); database: append-only, no direct status write, status follows events, no fork, no second first event, no cross-application link, accountable decisions, dates, new application starts at `awaiting_exam`, no application without history                                                                                     |
| `Feature/Recruitment/ReferenceDataTest`        | Marital statuses read only (change endpoints 404), editable lists mirror the seeded rule; all nine lists: read, change, integer keys, spelling-variant duplicates, archive/restore; areas: district required, unique per district, rename stays in district, list per district; seeders                                                                                                                                                                                                                                                                                                                               |
| `Feature/Recruitment/ApplicationSearchTest`    | Yes/no filters on false, ranges with bounds and 0, one or several statuses, live/ended, list entries of the application and the person, ages and birth dates, numbers in any digits, who opened it, inactive people, sorting (name, number, date), page size and links, fixed query count per page |
| `Feature/Identity/StaffUserRegistrationTest`   | Account with its role (hashed password, audit), no role beyond one's own permissions without `users.manage`, digits-only unique personal number, strong confirmed password, `users.add` required |
| `Feature/Recruitment/ApplicationApiTest`       | Opening applications, nomination numbers, national number as text, digit normalisation, person matching, one live application per person (any position; what frees a person), nothing left behind on failure, closing (permission, reason, dates, refusals), area in district (API and database), permissions, validation, database CHECKs, archived entries, search, filters, date range, history                                                                                                                          |
| `Feature/Recruitment/CandidateApiTest`         | People list and filters, search in any spelling or digits, one person's applications, corrections (audit, search key, national number conflict, same rules), deactivation (live application refused, reason, once, blocks nomination until reactivated), database CHECK, permissions |
| `Feature/Recruitment/ApplicationUpdateTest`    | Partial corrections and their audit, fields that never change here, ended application refused, district and area combinations, discontinued studies against stored values, same rules as create, permission |
| `Feature/SeedersTest`                          | Document types (once) and demo documents (some missing, runs once); Exam subjects (template's six, Quran parts, re-run safe) and demo plans in every state; reference lists (no nominating entities, re-run safe, archived and respelled entries kept, areas per governorate); demo: every status, dates in the past, accountable decisions, nominated twice, deactivated, three import batches with every row kind, runs once, uses the owner's nominating entities |
| `Unit/Exams/ExamScorerTest`                    | The doc's five worked examples, reasons with subjects, tolerated subject, Quran from weighted parts (482/600), relative weights, exact values at 58/60/70 boundaries, thirds, policy versions, mismatched or impossible marks |
| `Feature/Exams/ExamSubjectApiTest`             | Subjects with parts, names unique in any spelling (parts per subject), archive/restore, part changes, weight format, permissions, audit |
| `Feature/Exams/ExamPlanApiTest`                | Drafts (empty, copied, from an older version of the same position only), whole replacement in exam order, questions per difficulty (each ≥ 1), parts assessed in the exam or by hand (defaults and overrides, scale), content checks, one draft per position, archived job position, delete, publish (freeze, audit, every problem listed, no online question, bank shortages per difficulty, archived questions not counted, whole subject draws on all its questions, archived subjects/parts), readiness, retiring the previous version, part defaults never reach a published plan, manual mark in the engine, list filters, permissions, version numbers |
| `Feature/Exams/ExamDatabaseTest`               | Policy 1 present and append-only; published content frozen; published → retired only; retiring changes nothing else; draft not retired directly; publication CHECKs; one published and one draft per position; part in its subject; every difficulty or none (NULL cannot slip through); manual part has a scale and no questions, exam part the reverse; part defaults checked; draft deletion cascades; question versions append-only and well formed; question part in its subject |
| `Feature/Exams/ExamQuestionApiTest`            | Version 1, part questions, well-formed questions, corrections as versions (none for same content or difficulty only), answer kept among the options, no moving questions, archive and stats, filters (part `none`, search with wildcards escaped), only `exam_bank.manage` |
| `Feature/Documents/DocumentApiTest`            | Person documents stored on the person from any screen, application documents on their application, kept for a new application, requirements by status and missing list, content checks (fake PDF, PDF named .png, docx, size), download (headers, content, audit), archive (reason, once, file kept), archived type, permissions, types management; database: never changed except archived once, right person and scope |
| `Unit/HeaderMatcherTest`                       | Header normalisation, exact before contained, similar headers, unknown headers                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| `Feature/Imports/ImportUploadTest`             | Real template layout by header names, a row without national number refuses the file, security (unsafe parts, zip bomb, unsafe formulas injected in the sheet XML, content vs extension, filename, cell bounds, nosniff, rate limit), moved/renamed headers, sheet among others, empty rows, missing columns, no header, no rows, not a workbook, extensions, row limit, same file twice and after cancel (AC-10), batch metadata and default entity, permission, list, original download                                   |
| `Feature/Imports/ImportRowsTest`               | Values and raw cells, optional area (place kept as details), study years with "ومنقطع", dates (serial, 1904, text, Arabic digits, AC-17), leading zeros (AC-16), digits, yes/no, list spellings, unknown/archived values, health and residence details, area per district, missing values, shared rules, in-file duplicates, live application, person mismatch and confirmation, corrections (audit, re-check, duplicates elsewhere, malformed), revalidation, exclude/include, filters, scoped rows, cancelled batch |
| `Feature/Imports/ImportApprovalTest`           | Approval permission, uploader cannot approve, approver reads but cannot change, only valid rows imported, consecutive numbers, every value carried, reviews block approval, confirmed person keeps stored data, re-upload in another order (AC-19), all-or-nothing on failure, one reservation without gaps, approved/cancelled batches frozen, cancel reason, audit, report                                                                                                                                                |

API documentation check: `php artisan scramble:analyze` must report no warnings.

## 13. Known limitations and pending work

- The legacy exams sheet decides results differently from the analysis doc (no 58 tolerance; exactly 70% passes); the system follows the doc, as the owner confirmed (OD-15). Results copied from old sheets can differ.
- Exam sessions, candidate accounts and attempts are not built yet; their rules are decided (D-056). Archiving questions after a plan is published can leave the bank short: the session draw will refuse and say what is missing.
- Documents are not scanned for viruses; scanning and a retention period belong to the deployment, like import files (D-045, D-057).
- Nominating entities are typed by hand in the template: an entity not in the list (seeded or added through the API) keeps its rows from being approved (OD-13).
- Antivirus scanning of stored originals and a retention period for files with personal data belong to the deployment (D-045).
- Upload and approval run inside the request (max 2,000 rows by default). Larger files need a queued approval (D-041).
- `composer.lock` must be updated on a machine with Packagist access (`composer update phpoffice/phpspreadsheet`).
- Migrations of Recruitment were changed in place again in Step 4: rebuild development databases with `migrate:fresh --seed`. Before the first production deployment, migrations must become append-only.
- Candidate accounts and login are not built (D-016); candidate printed codes are a design only.
- Exams, interviews and contracts will move statuses through `ApplicationLifecycle` and add their cause columns (D-038); until then the demo's outcomes have no cause record.
- National numbers are stored in plain text (D-032): protect database backups.
- The legal moves are checked in PHP; the database guarantees the chain, the status copy, the dates and one live application per person, not which moves are legal.
- Date filters and the nomination-number year use UTC days (the server is UTC; Damascus is UTC+3).
- No user-management endpoints yet (create staff, deactivate, reset password). `must_change_password` is deferred (D-015).
- No audit table: security, access and import events go to the application log.
- Web fallback for staff is deferred (OD-3).
- A new permission introduced later must be attached to roles explicitly; the seeder only defaults new roles (D-020). `applications.close` is individual-only, so it is never attached to a role.
- Production needs trusted-proxy configuration for IP-based throttling.
- Arabic translations exist for login, recruitment and import messages; framework validation messages do not have an Arabic file yet.

## 14. Changelog

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
| 6 (follow-up) | On the owner's commit d247aa4. Complete application search (`ApplicationSearch`, sorting, page size); list rows load all their relations; areas with their district without a query each; deactivation CHECK requires all or nothing (D-049); adding staff users through `RegisterStaffUser` with a role-escalation guard (D-058); route files merge `api` and `auth` middleware where every route is authenticated |
| 6 | On the owner's commit 77f2408. Null `studies_discontinued` means false (D-044); marital statuses unknown to the change endpoints (D-048); nominating entities seeded by the owner (OD-13); the doc's pass rules confirmed (OD-15); plans: one session, parts marked by hand, easy/medium/hard (D-054); question bank (D-055); sessions decided (D-056); documents (D-057) |
| 5 (Exams part 1) | Subjects and parts; exam plans per job position, versioned and frozen when published (D-053); pass policy as append-only data and the exact scoring engine (D-052); open questions OD-15 to OD-17 |
| 4 (follow-up 2) | Marital statuses read-only through the API (D-048); people and live applications corrected, people deactivated (D-049); seeders for every list and local demo data (D-050); hexadecimal fingerprint rule (D-051); `studies_discontinued` defaults to false in the model |
