# Recruitment System: Backend Technical Documentation

Status: end of Step 3, completed (Foundation, staff identity, RBAC, device-bound login, Recruitment). Updated at the end of every step.

Audience: developers joining or reviewing the backend.
Companion file: `decisions.md` explains *why* things are the way they are. Decision ids (D-0xx) refer to it.

---

## 1. Overview

Backend and database for the recruitment, exams and contracts management system described in the Analysis & Design Document v1.2. A Laravel REST API on PostgreSQL. Two kinds of client use it:

- **Staff** (hiring managers, administrators, general manager, system admin): a Windows EXE built with React + Electron. Staff log in only from approved devices.
- **Candidates**: a React web portal, no device binding. Exams are taken at the company's premises.

Staff and candidates are separate actors with separate tables, login pipelines and guards (D-005).

## 2. Module status

| Module                                                         | Status  | Notes                                                                                                                                                        |
| -------------------------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Foundation                                                     | Done    | Project, PostgreSQL, module loader, tests on PostgreSQL                                                                                                      |
| Identity: staff accounts, RBAC, access management              | Done    | Roles, per-user overrides, management API (D-020)                                                                                                            |
| Identity: device-bound staff login                             | Done    | Registration, approval, challenge/signature login (D-017)                                                                                                    |
| Identity: user management (create, deactivate, reset password) | Planned |                                                                                                                                                              |
| Identity: candidate accounts and login                         | Planned | After the Recruitment core (D-016)                                                                                                                           |
| Recruitment                                                    | Done    | Nine managed lists (areas inside districts), candidates, applications, nomination number, chained status history with database-maintained status (section 8) |
| Imports                                                        | Planned | Excel batches, validation, duplicate detection, approval                                                                                                     |
| Exams                                                          | Planned | Subjects, parts, question bank, exam plans, scheduling, attempts, scoring                                                                                    |
| Interviews, Contracts, Reports                                 | Planned |                                                                                                                                                              |
| Audit                                                          | Started | `AuditTrail` seam only; events go to the application log until the table exists                                                                            |
| Integration                                                    | Planned | Outbox for the future "Mawarid" integration                                                                                                                  |

## 3. Tech stack

- PHP 8.2+ with the `pdo_pgsql` and `mbstring` extensions
- Laravel, PostgreSQL 16/17, Laravel Sanctum (personal access tokens)
- Scramble (API documentation, Scalar renderer)
- PostgreSQL features used: UUID, `macaddr`, CHECK constraints, partial unique indexes, composite foreign keys, triggers and a deferred constraint trigger (PL/pgSQL)
- PHPUnit through `php artisan test`
- Node 18+ only for the device test client (`tools/device-client.mjs`)

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
```

Framework files changed from the Laravel defaults:

| File                                                     | Change                                                                                                              |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `bootstrap/providers.php`                              | Registers `ModuleServiceProvider`                                                                                 |
| `bootstrap/app.php`                                    | Errors under `api/*` rendered as JSON; `UnescapedUnicodeJson` appended to the `api` group (D-018)             |
| `config/database.php`                                  | `pgsql` connection has `'timezone' => 'UTC'` (D-004)                                                            |
| `config/auth.php`                                      | Guards `web` (kept for Sanctum) and `staff`; provider `staff_users`                                           |
| `config/sanctum.php`                                   | Global `expiration` is `null`: lifetime is set per token (D-009)                                                |
| `config/staff_auth.php`                                | Challenge lifetime, maximum pending devices, staff token lifetime (one day, D-036)                                  |
| `config/recruitment.php`                               | Nomination number format                                                                                            |
| `config/scramble.php`                                  | API documentation settings                                                                                          |
| `app/Providers/AppServiceProvider.php`                 | Gate hook (D-012), rate limiters (D-013), custom token model, Scramble Bearer scheme, mass-assignment guard (D-021) |
| `phpunit.xml`                                          | Tests use PostgreSQL database `recruitment_test`                                                                  |
| `lang/ar/auth.php`, `lang/ar/pagination.php`         | Arabic login failure, throttle and pagination messages                                                              |
| `lang/ar/recruitment.php`, `lang/en/recruitment.php` | Recruitment business-rule messages                                                                                  |

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
| `interviews.manage`  |       —       |        ✓        |      ✓      |
| `contracts.manage`   |       —       |        ✓        |      ✓      |
| `users.manage`       |       —       |        —        |      ✓      |
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

1. **Sign what you send.** The username is signed exactly as sent: trim it, do not change its case. The server lowercases it only for the lookup (D-035).
2. **DER, not raw.** Node's `crypto.sign` produces DER by default. WebCrypto's `subtle.sign` returns raw `r||s`, which must be converted to DER first.
3. **Challenges** are single use and short lived (`staff_auth.challenge_ttl_seconds`). Any failure returns the same generic 422; request a new challenge and retry.
4. The private key never leaves the device; store it encrypted with the OS per-user protection. See the limitation in D-017.

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
├── Actions/       CreateApplication, ApplicationLifecycle, ManageReferenceData
├── Database/      Migrations (3), Seeders (DistrictsSeeder, MaritalStatusesSeeder)
├── Enums/         ApplicationStatus, DecisionType, ReferenceType
├── Exceptions/    IllegalStatusTransition
├── Http/          Controllers (ReferenceData, Application), Requests, Resources (+ ReferenceSummary helper)
├── Models/        Candidate, Application, ApplicationStatusEvent, Concerns/IsReferenceData,
│                  District, Area, NominatingEntity, JobPosition, ApplicationCategory,
│                  AcademicQualification, Specialization, MaritalStatus, HealthCondition
├── Support/       NominationNumberGenerator, NationalNumber, PhoneNumber
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
| national_number        | varchar(11), unique, CHECK exactly 11 digits; stored as given (D-032)        |
| full_name, mother_name | varchar(150), CHECK no digits (ASCII or Arabic-Indic), at least 3 characters |
| name_search_key        | `ArabicText::searchKey(full_name)`, indexed                                |
| birth_date             | date, CHECK after 1900-01-01                                                 |
| birth_place_id         | FK `districts`                                                             |
| is_active, notes       |                                                                              |

**`nomination_counters`**: `year` PK, `last_value`.

**`applications`** (one nomination; everything declared at application time):

| Column                                                             | Notes                                                               |
| ------------------------------------------------------------------ | ------------------------------------------------------------------- |
| id                                                                 | uuid PK                                                             |
| nomination_number                                                  | unique                                                              |
| candidate_id                                                       | FK `candidates`                                                   |
| nominating_entity_id, job_position_id, application_category_id     | FKs, required, indexed                                              |
| existing_employee_number                                           | optional                                                            |
| academic_qualification_id, specialization_id                       | FKs, required                                                       |
| studying_years                                                     | optional, CHECK ≤ 15                                               |
| quran_parts_memorized                                              | optional, CHECK ≤ 30                                               |
| marital_status_id, health_condition_id                             | FKs, required                                                       |
| health_details                                                     | optional free text                                                  |
| residence_district_id, residence_area_id                           | required; the pair is a composite FK to `areas (district_id, id)` |
| residence_details                                                  | optional free text                                                  |
| phone_number                                                       | text, CHECK `^\+?[0-9]{6,15}$`                                    |
| works_for_other_government_entity, full_commitment, overnight_work | required booleans                                                   |
| notes                                                              | optional                                                            |
| current_status                                                     | enum + CHECK; written only by the database (8.3)                    |
| created_by                                                         | FK `staff_users`                                                  |

Partial unique index: one live application per person and job position (live = not `interview_failed` / `contract_written`).

**`application_status_events`** (the history; append-only chain):

| Column                   | Notes                                                                                                                                      |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| id                       | uuid PK                                                                                                                                    |
| application_id           | FK `applications`                                                                                                                        |
| previous_event_id        | unique; `(application_id, previous_event_id)` is a composite FK to `(application_id, id)` of this table; null only for the first event |
| to_status                | enum + CHECK                                                                                                                               |
| decision_type            | `created` / `outcome` / `exception` / `excuse_accepted`                                                                            |
| reason, actor_id         | required (non-blank reason) for `exception` and `excuse_accepted`                                                                      |
| related_type, related_id | the attempt / interview / contract that caused the move (no FK yet)                                                                        |
| occurred_at, created_at  |                                                                                                                                            |

Constraints: one first event per application (partial unique index); only `created` has no previous event, and `created` always leads to `awaiting_exam`.

### 8.3 Database triggers (D-033)

| Trigger                                                     | On                    | Effect                                                                                        |
| ----------------------------------------------------------- | --------------------- | --------------------------------------------------------------------------------------------- |
| `application_status_events_append_only`, `_no_truncate` | events                | UPDATE, DELETE, TRUNCATE refused                                                              |
| `application_status_events_follow_tail`                   | events, before insert | Locks the application; refuses an event that does not follow the latest event                 |
| `application_status_events_set_current_status`            | events, after insert  | Copies `to_status` into `applications.current_status`                                     |
| `applications_current_status_guard`                       | applications          | A new application must be `awaiting_exam`; any other write to `current_status` is refused |
| `applications_have_history` (deferred)                    | applications          | At commit, refuses an application that has no first event                                     |

So `current_status` always equals the status of the last event, whatever code writes to the database. Other application columns can still be updated normally.

### 8.4 Status lifecycle

```
awaiting_exam ──outcome──► awaiting_interview ──outcome──► awaiting_contract ──outcome──► contract_written
     │  │                        │  │
     │  └─outcome─► exam_absent   │  └─outcome─► interview_absent
     │                  │         │                    │
     │          excuse_accepted   │            excuse_accepted
     │                  ▼         │                    ▼
     │           awaiting_exam    │             awaiting_interview
     └─outcome─► exam_failed ─exception─► awaiting_interview
                                  └ interview_failed (final) ◄─outcome─ awaiting_interview
```

Final statuses: `interview_failed`, `contract_written`. `ApplicationLifecycle::move($application, $to, $type, $actor, $reason, $relatedType, $relatedId)` locks the application row, reads its status from the database, refuses any move not in `ApplicationStatus::transitions()`, any wrong decision type, and a person-made decision without reason and actor (`IllegalStatusTransition`). It then appends the event after the latest one and reloads the caller's model (the database has changed `current_status`). `Application::history()` returns the events in chain order with each event's previous event attached, so `fromStatus()` costs no extra query.

### 8.5 Nomination number

`{YYYY}{SEQ}` by default, six-digit counter restarting each year (`config/recruitment.php`). Generated by `NominationNumberGenerator` inside the transaction that creates the application, so a refused request uses up no number.

### 8.6 People and duplicates

- A person is recognised by national number only, never by name. A matched person's stored data is kept: the `candidate` fields of a new application are ignored (`candidate_matched: true`). Application data (phone, residence, education, ...) is per application.
- One live application per person and job position: 422 with `recruitment.live_application_exists`, and the partial unique index as the last guard (OD-7).
- The national number is required, so every person can be matched (D-032).

### 8.7 API reference

All under `/api/v1/staff`, staff token required.

| Method | Path                               | Permission                                | Purpose                                                                   |
| ------ | ---------------------------------- | ----------------------------------------- | ------------------------------------------------------------------------- |
| GET    | `/reference/{type}`              | any staff user                            | List a managed list; `?include_archived=1`; for areas `?district_id=` |
| POST   | `/reference/{type}`              | `candidates.manage`                     | Add an entry (`name_ar`; areas also `district_id`)                    |
| PUT    | `/reference/{type}/{id}`         | `candidates.manage`                     | Rename (an area never changes district)                                   |
| DELETE | `/reference/{type}/{id}`         | `candidates.manage`                     | Archive                                                                   |
| POST   | `/reference/{type}/{id}/restore` | `candidates.manage`                     | Restore                                                                   |
| POST   | `/applications`                  | `candidates.manage`                     | Open an application (finds or creates the person)                         |
| GET    | `/applications`                  | `reports.view` or `candidates.manage` | Search and list                                                           |
| GET    | `/applications/{id}`             | `reports.view` or `candidates.manage` | File with person and full history                                         |

`{type}` is `districts`, `areas`, `nominating-entities`, `job-positions`, `application-categories`, `academic-qualifications`, `specializations`, `marital-statuses` or `health-conditions`; `{id}` is an integer. A name that already exists in any spelling is refused (422), also when the existing entry is archived (restore it instead). Archived entries cannot be chosen for new applications but still show (with `archived: true`) in existing files.

**Open an application** (`POST /applications`), all ids are integers:

```json
{
  "nominating_entity_id": 1, "job_position_id": 1, "application_category_id": 1,
  "existing_employee_number": null,
  "academic_qualification_id": 1, "specialization_id": 1, "studying_years": 12, "quran_parts_memorized": 5,
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

Names may not contain digits. Arabic-Indic digits and separators in `phone_number` and `national_number` are converted. `birth_date` is `YYYY-MM-DD`. The area must belong to the district (422 `recruitment.area_not_in_district`). Response 201 with `data` (the file) and `candidate_matched`.

**Search** (`GET /applications`): `q` (nomination number exactly, or part of a name in any spelling), `national_number` (exact, any digit style), `status`, `job_position_id`, `nominating_entity_id`, `application_category_id`, `residence_district_id`, `from`, `to` (`YYYY-MM-DD`, both days included, UTC); 25 per page, newest first.

**File** (`GET /applications/{id}`): the application with each list value as `{id, name_ar, archived}`, grouped `education`, `health`, `residence`, `availability`; the `candidate`; and `history` in chain order, each event with `from_status`, `to_status`, `decision_type`, `reason`, `actor`, `related_type`, `related_id`, `occurred_at`.

### 8.8 Seeding

`DistrictsSeeder` (14 governorates) and `MaritalStatusesSeeder` (أعزب، متزوج، مطلق، أرمل) run with `db:seed` in every environment. Both are idempotent and never bring back an archived entry. The other lists are entered through the API.

## 9. Testing

`php artisan test` (PostgreSQL database `recruitment_test`, `RefreshDatabase`): 258 tests. The suite needs no OpenSSL configuration: tests use fixed keys from `tests/Support/TestDeviceKeys.php` (D-023). Shared helpers are in `tests/Support/StaffTestHelpers.php` (devices get a unique MAC and an `os_info`) and `tests/Support/RecruitmentTestHelpers.php` (one entry of every list and a valid application payload).

Tests never commit, so the deferred `applications_have_history` check is tested with `SET CONSTRAINTS applications_have_history IMMEDIATE`.

| Test file                                        | Covers                                                                                                                                                                                                                                                                                                                      |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Feature/DatabaseConventionsTest`              | UTC session; timestamps agree with the database clock                                                                                                                                                                                                                                                                       |
| `Feature/Identity/StaffUserTest`               | Username CHECK (blank, short, long, spaces, unsafe characters); trimmed and lowercased                                                                                                                                                                                                                                      |
| `Feature/Identity/StaffAuthTest`               | Login through a device, username case, Arabic usernames refused, generic failures, rate limit, one-day token, `me`, logout, deactivated user                                                                                                                                                                              |
| `Feature/Identity/DeviceLoginTest`             | Pending/revoked/unknown device, replay, expiry, wrong key, username binding, device owner, wrong password, challenge bound to device                                                                                                                                                                                        |
| `Feature/Identity/DeviceManagementTest`        | Registration (idempotent, MAC, key validation, pending cap), status, approval (fingerprint, owner, twice), revoke deletes tokens, list filter, mass-assignment guard, database CHECK                                                                                                                                        |
| `Feature/Identity/PermissionResolutionTest`    | Role permissions match the matrix; ✓* never implicit; grant/deny; unknown abilities                                                                                                                                                                                                                                        |
| `Feature/Identity/AccessManagementTest`        | Role and user access management, `individual_only`, last-administrator protection, 404s, seeder does not overwrite                                                                                                                                                                                                        |
| `Unit/ApplicationStatusTest`                   | Legal moves, decision types, final statuses, labels                                                                                                                                                                                                                                                                         |
| `Unit/ArabicTextTest`                          | Search key and digit normalisation                                                                                                                                                                                                                                                                                          |
| `Unit/RecruitmentNormalisationTest`            | National number and phone number normalisation                                                                                                                                                                                                                                                                              |
| `Feature/Recruitment/NominationNumberTest`     | Format, yearly restart, configurable format, no gaps after rollback                                                                                                                                                                                                                                                         |
| `Feature/Recruitment/ApplicationLifecycleTest` | Moves, refusals, exceptions and excuses, stale models, chain order and `from_status`; database: append-only, no direct status write, status follows events, no fork, no second first event, no cross-application link, accountable decisions, new application starts at `awaiting_exam`, no application without history |
| `Feature/Recruitment/ReferenceDataTest`        | All nine lists: read, change, integer keys, spelling-variant duplicates, archive/restore; areas: district required, unique per district, rename stays in district, list per district; seeders                                                                                                                               |
| `Feature/Recruitment/ApplicationApiTest`       | Opening applications, nomination numbers, national number as text, digit normalisation, person matching, duplicate rule, nothing left behind on failure, area in district (API and database), permissions, validation, database CHECKs, archived entries, search, filters, date range, history                              |

API documentation check: `php artisan scramble:analyze` must report no warnings.

## 10. Known limitations and pending work

- Candidate accounts and login are not built (D-016); candidate printed codes are a design only.
- No endpoint edits a candidate or an application yet, and nothing moves an application's status through the API: exams, interviews and contracts will call `ApplicationLifecycle`. Excel import and editing are the next step (OD-10).
- Repeat applications are refused while an earlier one is live (OD-7).
- National numbers are stored in plain text (D-032): protect database backups.
- The legal moves are checked in PHP; the database guarantees the chain and the status copy, not which moves are legal.
- Date filters and the nomination-number year use UTC days (the server is UTC; Damascus is UTC+3).
- No user-management endpoints yet (create staff, deactivate, reset password). `must_change_password` is deferred (D-015).
- No audit table: security and access events go to the application log (`staff_login_failed`, `access.*`).
- Web fallback for staff is deferred (OD-3).
- A new permission introduced later must be attached to roles explicitly; the seeder only defaults new roles (D-020). `candidates.view_sensitive` was removed from the catalogue: a database seeded before this change keeps the row until it is deleted or the database is rebuilt.
- Production needs trusted-proxy configuration for IP-based throttling.
- Arabic translations exist for login and recruitment messages; framework validation messages fall back to English.
- `staff_users.first_name` / `last_name` are `varchar(15)`: compound Arabic names may not fit (50 suggested).

## 11. Changelog

| Step          | Summary                                                                                                                                                                                                                                                                                                                                                                                   |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1             | Foundation: project, PostgreSQL, module loader, tests on PostgreSQL                                                                                                                                                                                                                                                                                                                       |
| 2             | Identity & RBAC: separate staff table (D-005), RESTRICT foreign keys (D-007), UTC connection (D-004), username constraint (D-008), `LoginStaff` action (D-010), two-level throttling (D-013), active-account middleware (D-014)                                                                                                                                                         |
| 2 (follow-up) | Unescaped Arabic JSON (D-018); Scramble with Bearer scheme (D-019)                                                                                                                                                                                                                                                                                                                        |
| 2b            | Device-bound staff login (D-017); single device owner, enum status, macaddr (D-022); one-week staff tokens (D-009); Arabic usernames (D-008); access management API (D-020); mass-assignment policy (D-021); fixed test keys (D-023); `must_change_password` deferred (D-015)                                                                                                           |
| 3             | Recruitment core: managed lists, candidates, applications, nomination number, status lifecycle with append-only history, encrypted national ID with blind index, sensitive-data permission (D-024 to D-030); one approved device per user and required MAC (D-022)                                                                                                                        |
| 3 (completed) | Owner's schema redesign finished: nine lists with integer keys, areas inside districts with a composite key (D-031, D-034); person vs application data (D-031); plain national number, sensitive permission removed (D-032); chained history with database-maintained status (D-033); Latin-only usernames (D-035); one-day staff tokens (D-036); recruitment tests restored and extended |
