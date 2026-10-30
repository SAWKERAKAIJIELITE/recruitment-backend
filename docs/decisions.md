# Design Decisions Log: Recruitment System Backend

A living record of the design decisions made while building the backend and database, with the reasoning behind each one. Written so a new team member can see not just *what* was chosen but *why*, and what was rejected.

**Sources:** the Analysis & Design Document v1.2 ("analysis doc"), the owner's login and device diagrams, and decisions taken while building.

**Status values:** Accepted (in force) · Proposed (agreed in principle, details still to confirm) · Deferred (accepted, built later) · Superseded (replaced, kept for history).

**Rule:** never rewrite history. If a decision changes, add a new entry and mark the old one Superseded.

---

## 1. Foundation

### D-001 Modular monolith in one Laravel app

- **Status:** Accepted (analysis doc §12)
- **Decision:** one Laravel application; one folder per business area under `app/Modules/*`; a single `ModuleServiceProvider` discovers each module's migrations, routes and console commands.
- **Why:** the team knows Laravel; one deployable unit is far cheaper to run than distributed services; module folders keep boundaries clear and leave the option to split later.
- **Rejected:** microservices (operational cost, no stated need); one service provider per module (boilerplate until a module needs its own bindings).
- **Consequence:** Laravel sorts migrations from all modules by filename timestamp, so a referenced table's migration must carry an earlier timestamp than the migration that references it.

### D-002 PostgreSQL everywhere, tests included

- **Status:** Accepted
- **Decision:** development, test and production all use PostgreSQL; the test suite runs against a dedicated `recruitment_test` database.
- **Why:** the schema relies on PostgreSQL behaviour (UUID columns, CHECK constraints, macaddr, partial unique indexes, NUMERIC). SQLite tests would pass while hiding constraint bugs.
- **Rejected:** SQLite in-memory tests (faster, but different semantics).

### D-003 UUID primary keys; human-readable nomination number kept separate

- **Status:** Accepted (analysis doc §10). Exception for the managed lists: D-034.
- **Why:** identifiers are not guessable or sequential, which removes a class of ID-guessing (IDOR) attacks; the readable number is for people, not for joins.
- **Cost:** larger indexes. Laravel's ordered UUIDs reduce the penalty.

### D-004 Time is stored as timestamptz, and the database connection is forced to UTC

- **Status:** Accepted
- **Context:** seeded rows showed incorrect times even though `timestampsTz()` was used.
- **Cause:** Laravel sends timestamps as text such as `2026-10-13 10:00:00` with no offset. PostgreSQL interprets offset-less text in the *session* time zone. When that zone is not UTC (typical for a local install on a UTC+3 machine) the stored instant is shifted. The `timestamptz` column type alone does not protect against this.
- **Decision:** set `'timezone' => 'UTC'` on the `pgsql` connection. Tests assert that the session zone is UTC and that stored timestamps agree with the database clock.
- **Note:** database GUI tools may display `timestamptz` values in the client's own zone. That is display only.
- **Rejected:** converting in PHP; storing local time.

---

## 2. Identity and access

### D-005 Staff and candidates are separate actors with separate tables, models and guards

- **Status:** Accepted. Supersedes the first draft, which used one `users` table with a `type` column.
- **Context:** staff and candidates have different base data and different login pipelines (owner's diagrams).
- **Decision:** `staff_users` (employees; RBAC; device-bound login) and `candidate_accounts` (applicants; built after the Recruitment core) are distinct tables with distinct Eloquent models. Each actor has its own Sanctum guard: `auth:staff` now, `auth:candidate` later.
- **Why:** each table holds only the columns its actor needs; each actor gets its own security policy; a token issued to one actor type is structurally rejected on the other's routes, instead of relying on `if ($user->type === ...)` checks scattered through the code.
- **Rejected:** one table plus a `type` column (shared credential and token surface); one table plus per-type profile tables (same shared surface, more joins).
- **Cost:** two login endpoints and two rate limiters. Sanctum's token table is polymorphic, so a single `personal_access_tokens` table serves both.
- **To verify:** the "token of the other actor is rejected" behaviour relies on Sanctum's guard-provider check. A cross-guard test is mandatory when candidate accounts are built.

### D-006 RBAC applies to staff only

- **Status:** Accepted (analysis doc §02)
- **Decision:** roles map to permissions; per-user grants and denials live in `permission_staff_user`. Effective permissions = (role permissions + direct grants) − direct denials. Cells marked ✓* in the analysis doc matrix are permissions flagged `individual_only`: they can never be attached to a role and are reachable only through a per-user grant. There is no super-admin bypass: Admin holds exactly what the matrix gives it. Candidates have no roles; their access is the `auth:candidate` guard plus policies restricting them to their own data.
- **Why:** it matches the matrix literally ("a separate permission that can be granted or withheld, not an implicit right of everyone in the role"), keeps the rule enforceable in one place, and avoids a bypass that would defeat the audit requirements.
- **Choice made while implementing:** the matrix rows "import/edit data" and "enter interview and contract" were each split into two permissions (`imports.manage` / `candidates.manage`, `interviews.manage` / `contracts.manage`). Merge them if finer control is not wanted.

### D-007 No cascading deletes; no ON UPDATE actions

- **Status:** Accepted. Supersedes the first draft, which used `ON DELETE CASCADE` on link tables by habit.
- **Decision:** every foreign key uses `ON DELETE RESTRICT`. People are deactivated (`is_active = false`), never deleted. Reference data is archived or soft-deleted. No `ON UPDATE` action is declared.
- **Why (delete):** the analysis doc requires that history cannot be erased. Cascade deletes silently: removing a role would strip access from everyone holding it, with no event recorded. `RESTRICT` makes a dangerous delete fail loudly. Removing a *link* row (revoking a role from a user) remains possible, but it is an explicit operation that is logged.
- **Why (update):** `ON UPDATE` only matters when the *referenced key itself changes*. Our keys are immutable UUIDs that nothing ever updates, so the PostgreSQL default (`NO ACTION`, which already refuses a key change) is correct. Auditability comes from immutable ids, `RESTRICT` on delete, and append-only history tables, not from update actions.
- **Exception rule:** cascade is acceptable only for purely derived or ephemeral children (for example expired login challenges). Each use must be recorded here.

### D-008 Username format

- **Status:** Superseded by D-035 (staff usernames are Latin only). The Arabic-letters revision below was never adopted in the code; kept for history.
- **Decision:** 3 to 50 characters from `a-z . _ -` plus canonical Arabic letters. No digits (owner's rule), no whitespace. Stored in a **normalised form** produced by `Username::normalize()`: trimmed; lowercase Latin; Arabic harakat, superscript alef and tatweel removed; alef variants (أ إ آ ٱ) collapsed to ا; alef maqsura ى collapsed to ي. Enforced in three places: a PostgreSQL CHECK constraint (which accepts only canonical Arabic letters), the model (normalises on assignment), and the login lookup (normalises what was typed).
- **Why:** staff can type their name the way they naturally write it, and always reach the same account. Without normalisation أحمد and احمد would be two look-alike accounts (confusion and impersonation risk). The database check protects code paths that bypass the model (seeders, tinker, raw SQL, imports).
- **Limitations:** ة and ه are *not* merged. Mixed Latin/Arabic names are allowed. Look-alike characters between scripts (for example Latin and Arabic dots) are not detected.
- **Related:** the device-login signature covers the username exactly as sent (D-017), so clients never need to know the normalisation rules.

### D-009 Authentication with Sanctum personal access tokens

- **Status:** Accepted
- **Decision:** Bearer tokens issued at login, stored hashed in `personal_access_tokens`. Lifetime is set per token at login (staff: one day since D-036, `staff_auth.token_lifetime_minutes`); Sanctum's global `expiration` is disabled so each actor type can have its own lifetime.
- **Why:** the Electron app has no cookie origin; opaque database-backed tokens can be revoked instantly (logout, deactivation, device revocation); native to Laravel.
- **Rejected:** Sanctum cookie mode (CORS, CSRF and domain constraints with Electron); JWT (revocation needs a blocklist, and with a single backend it adds nothing).
- **Consequence:** clients must store the token safely (Electron: OS-protected storage).

### D-010 Thin controllers, Actions for use cases, no repository layer

- **Status:** Accepted (analysis doc §12)
- **Decision:** Controller → FormRequest → Action → Eloquent. Business rules live in Action/Service classes. Complex read queries (reports, search) get dedicated query classes. No generic repositories wrapping Eloquent.
- **Why:** Eloquent already is the data-access abstraction; tests run against real PostgreSQL, so there is nothing to mock away; a repository mirroring the ORM's own API adds a layer to maintain without adding protection.
- **Revisit if:** we ever need a second persistence backend, or a query becomes complex enough to deserve a named, tested class (the query-class route covers this).

### D-011 API Resources are an output whitelist

- **Status:** Accepted
- **Decision:** response bodies are shaped by Resource classes that name the exposed fields. Model `$hidden` is only a second safety net.
- **Why:** a whitelist cannot leak a field added to the model later; the Resource is the single place where the response shape (which clients depend on) is defined.

### D-012 Permission checks through Gate::before and `can:` middleware

- **Status:** Accepted
- **Decision:** `Gate::before` answers any ability whose name equals a permission slug from the staff user's effective permissions, returning `true` or `null`, never `false`. Routes use `->middleware('can:slug')`. Resource-level rules ("only your own record") belong in Policies.
- **Why:** one rule, no per-permission gate registration (which would query the database at boot), and policies keep their say.

### D-013 Login throttling

- **Status:** Accepted
- **Decision:** staff login: 5 attempts per minute per username and IP, plus 30 per minute per IP. Candidate login (planned): per identifier and IP only, no per-IP cap, because candidates share networks (analysis doc §17).
- **Why:** keying only on username+IP lets one IP try unlimited different usernames; the added per-IP cap closes that.
- **Deployment note:** behind a reverse proxy, configure trusted proxies; otherwise every client appears to share the proxy's IP.

### D-014 Generic login failure, equalised timing, active check on every request

- **Status:** Accepted
- **Decision:** every failure reason returns the same message. When the user does not exist, a throw-away password hash is computed so the response time is comparable. `EnsureStaffIsActive` rejects tokens whose owner has been deactivated.
- **Why:** prevents account enumeration. The active check closes a gap found in review: without it, deactivating a user would not stop tokens already issued.

### D-015 `must_change_password`

- **Status:** Deferred (owner's decision: not needed now)
- **Decision:** the column, model field, resource field and enforcement middleware are all left out until the feature is scheduled.
- **When revisited:** temporary credentials (analysis doc §17) will need a flag that the server enforces, or an invite link instead.

### D-020 Access management API; the database owns RBAC data after first run

- **Status:** Accepted
- **Decision:** administrators (permission `users.manage`) manage access through the API: add or remove permissions of a role; assign or remove roles of a staff user; grant, deny or clear a personal permission override. Rules enforced in the services: `individual_only` permissions are refused for roles; every change runs in a transaction and is rolled back if it would leave **no active staff user able to manage users**; removing something that is not attached returns 404; every change is logged through `AccessLog` (audit table to follow). `RolesAndPermissionsSeeder` creates the catalogue and gives a role its defaults **only when the role is first created**; it never overwrites changes made later.
- **Why:** once access is editable at runtime, a seeder that re-syncs roles would silently undo administrators' work on every deploy. The "someone must remain able to manage users" rule prevents lock-out that could only be repaired by editing the database.
- **Consequence:** a permission introduced in a later step must be attached to roles explicitly (one-off migration or API call), not by re-running the seeder.
- **Limitation:** the invariant check loads every active staff user; fine for dozens of staff, revisit if the staff table grows large.

### D-021 Mass assignment policy

- **Status:** Accepted
- **Decision:** `$fillable` lists only what is supplied by the caller or derived from it (for a device: label, public key, fingerprint, reported MAC, OS info). Columns decided by business rules (`status`, `staff_user_id`, `approved_by/at`, `revoked_by/at/reason`) are not fillable and are set explicitly with `forceFill()` inside the Actions that own those rules. Outside production, `Model::preventSilentlyDiscardingAttributes()` makes assigning a non-fillable attribute throw instead of being dropped silently.
- **Why:** the identity of the approver comes from the authenticated user, never from the request. Keeping those columns out of `$fillable` makes it impossible to set them by passing request data to `create()`/`update()`.

---

## 3. Login pipelines and devices

### D-016 Candidate login: nomination number + printed password or code, no device binding

- **Status:** Accepted (owner's diagram and answer); build Deferred until the Recruitment core exists
- **Decision:** one account per person. The nomination number identifies an application, which identifies the person. The nomination number is an identifier, never the secret (analysis doc §17). The initial password or code is generated by the system and **printed and handed to the candidate at the company's premises, where the exam takes place**. No SMS or messaging channel.
- **Why deferred:** login depends on the candidates and applications tables.
- **Idea:** since exams happen on site, candidate login can optionally be restricted to the venue's network (IP allowlist).

### D-017 Staff login bound to a trusted device

- **Status:** Accepted; built
- **Source:** owner's diagram (staff portal: trusted device, user identity + digital signature + MAC).
- **Decision:**
  1. Each EXE installation generates an ECDSA P-256 key pair on first run. The private key never leaves the device and is stored encrypted with the operating system's per-user protection. The public key is registered with the server.
  2. Registration creates a `pending` device record (public key, device label, reported MAC, OS details). A staff member holding `devices.approve` approves it after comparing a short fingerprint of the public key shown on the employee's screen, typing the fingerprint back, and choosing the **one staff user who owns the device**. States: pending → approved → revoked.
  3. Login is two calls: `POST /staff/auth/challenge {device_id}` returns a random, single-use challenge (stored server-side, bound to that device, short lifetime); then `POST /staff/auth/login {device_id, challenge_id, signature, username, password}`.
  4. Verification order: challenge valid and unused (consumed atomically) → device approved → signature valid over (prefix + challenge id + challenge + device id + username as sent) → user exists and is active → device belongs to that user → password. Every failure returns the same generic message; the real reason goes to the log.
  5. The issued token is linked to the device. Revoking a device deletes its tokens.
- **Why this differs from the diagram:**
  - *The MAC address is a label, not the anchor.* The client reports it itself, so a modified client can send anything; it is easy to spoof; and "the physical adapter" is ambiguous (VPNs, docking stations, USB adapters, randomised Wi-Fi addresses). It is kept for the administrator to recognise the machine and for audit. The key pair is the proof.
  - *Device first, password second.* A machine without an approved device cannot even test password guesses, and the server needs no half-authenticated state between two steps.
  - *Fingerprint comparison at approval* stops an administrator from approving the wrong request.
  - *Lifecycle added:* revocation (lost or stolen machine), re-enrolment after reinstall (a new key means a new pending device), tokens tied to devices.
- **Limitation (important):** in Electron the private key can be encrypted at rest with Windows per-user protection, but it is not hardware-bound. Anyone running as that Windows user on that machine can use it. Hardware-bound (TPM) keys need native Windows code. This design stops credential sharing and unknown machines; it does not stop a malicious user on their own approved machine.
- **Bootstrap:** the first administrator has no approved device, so `php artisan staff:approve-device <device-id> <username> --fingerprint="..."` approves one from the server shell (also the break-glass path). Console approvals have no `approved_by`.
- **Rejected:** MAC address as the credential; mutual-TLS client certificates (strong, but needs a certificate authority and proxy configuration; revisit if the institution already runs one); WebAuthn (platform-authenticator support inside Electron is not something to depend on without testing).

### D-022 Device data model

- **Status:** Accepted
- **Decision:** `staff_devices.status` is a Laravel `enum` column (varchar + CHECK in PostgreSQL) mapped to the PHP backed enum `DeviceStatus`; `reported_mac` is a PostgreSQL `macaddr` column (validated by Laravel's `mac_address` rule, normalised by PostgreSQL); a device has one owner (`staff_user_id`, set at approval) and **each user has at most one approved device** (owner's rule), enforced by a partial unique index on approved devices, so revoking a lost device frees the slot for a new one while the revoked row stays as history; `reported_mac` is required (owner's decision) but remains informational; CHECK constraints require an approved device to have an approval time and an owner, and a revoked one a revocation time.
- **Why:** the database refuses invalid states even if application code is wrong; the enum documents allowed values in the API docs; `macaddr` rejects malformed addresses.
- **Note:** a native PostgreSQL ENUM type would make removing a value hard and Laravel does not create one; CHECK is the conventional route.

### D-023 Fixed test keys instead of generating keys in tests

- **Status:** Accepted
- **Context:** generating keys with `openssl_pkey_new` needs PHP to find an `openssl.cnf`, which fails on typical Windows PHP installs (XAMPP) with "No such file or directory".
- **Decision:** the test suite uses four fixed P-256 key pairs (`tests/Support/TestDeviceKeys.php`). Loading, signing and verifying with existing keys needs no configuration file. The keys protect nothing and exist only for tests.
- **Why:** tests run identically on every machine without environment setup.

---

## 4. API conventions

### D-018 JSON responses keep non-ASCII text unescaped

- **Status:** Accepted
- **Context:** Arabic values appeared as `\u0625\u062f...` in the API docs "Try it" panel.
- **Decision:** a middleware on the `api` group sets `JSON_UNESCAPED_UNICODE` on every JSON response.
- **Why:** escaped JSON is valid and decodes correctly, but it is unreadable in raw views and larger on the wire (6 bytes per Arabic character escaped, 2 in UTF-8).

### D-019 API documentation is generated by Scramble from the code

- **Status:** Accepted
- **Decision:** Scramble generates the OpenAPI document from routes, FormRequests and Resources; a Bearer security scheme is declared in `AppServiceProvider`; public endpoints carry the `@unauthenticated` tag. To keep the generated schema accurate: return Resource classes rather than hand-built arrays where possible; use literal status codes (no `cond ? 201 : 200`); give methods return types; put `@mixin Model` on its own docblock line in Resources. `php artisan scramble:analyze` must report no warnings.
- **Why:** the generator reads the code statically. A ternary status code or an untyped array made the register endpoint appear as a bare `string`.
- **Known quirk:** a *paginated* collection of a Resource with a custom `$wrap` is documented under the item's wrapper key although the runtime key is `data`; avoid custom `$wrap` on resources returned as paginated lists.

---

## 5. Recruitment core

### D-024 One person, many applications; sensitive data in its own table

- **Status:** Partly superseded by D-031. Still in force: one person, many applications; the application is the main entity; the lists are separate typed tables, archived by soft delete. Replaced: the sensitive-details table and which facts live on the person.
- **Decision:** `candidates` is the person; `applications` is one nomination of that person for one job title and is the main business entity. Facts that can differ between two applications of the same person (availability declarations, category, notes, the employee number if any) live on the application. Marital status and health data live in `candidate_sensitive_details`. The managed lists (source offices, job titles, governorates, application categories) are four tables of the same shape, archived by soft delete, and served by one controller.
- **Why:** it matches "one person, several nominations" in the analysis doc; availability is a declaration made at a moment in time, so it belongs to the application; sensitive data needs access control and auditing of its own. The analysis doc's eight logical groups (profile, identity, residence, education, qualifications, availability, contacts, sensitive) are *not* eight tables: splitting only where access or time requires it avoids a chain of one-to-one joins.
- **Rejected:** one wide candidates table (cannot restrict sensitive columns separately); one table per logical group (join sprawl for no gain); one generic "reference items" table with a type column (no typed foreign keys).

### D-025 Nomination number

- **Status:** Accepted (analysis doc §10). Amended by D-041: an import approval reserves all its numbers with one statement; still consecutive, still no gaps.
- **Decision:** digits only, format `{YYYY}{SEQ}` by default (changeable in `config/recruitment.php`), with a six-digit counter that restarts each year. The counter is a table row incremented by one atomic statement inside the transaction that creates the application. A unique index is the last guard. The number is an identifier, never a secret.
- **Why:** digits only, so candidates can type it on any keyboard layout; a counter table rolls back with the transaction, so committed numbers have no gaps (an official numbering that skips numbers invites questions), unlike a PostgreSQL sequence.
- **Cost:** concurrent creators queue briefly on the counter row; acceptable at this scale. A long import holds the lock for its duration.
- **Limitations:** the year changes at UTC midnight; numbers are sequential, so they reveal volume (the password protects access, not the number).

### D-026 National ID protection

- **Status:** Superseded by D-032 (owner's decision: the national number is stored as given, no encryption, no blind index, no masking, no sensitive permission).
- **Decision:** the national ID is stored **encrypted** (application-level encryption) together with a **blind index** (keyed HMAC-SHA256) in a unique column. The blind index finds "the same person" without keeping the number readable. Responses mask it (`********890`) unless the viewer holds `candidates.view_sensitive`, an *individual-only* permission (never given by a role). Marital and health data need the same permission to write and to read, a request that sends them without it is refused with 403 (nothing is created), and opening a file with sensitive access is written to the audit trail. Arabic-Indic digits are converted to ordinary digits before anything is stored, hashed or compared; phone numbers are stored as text.
- **Why:** a database leak or backup no longer exposes national IDs; duplicates are still caught; Arabic data entry produces Arabic-Indic digits routinely.
- **Limitations:** exact-match search only (no partial search on the national ID); the keys must be backed up (OD-9); if the blind-index key changes, every hash must be recomputed.
- **Rejected:** plaintext (exposed to anyone with database access); encryption without a blind index (no duplicate detection).

### D-027 Application lifecycle: legal moves in code, history append-only in the database

- **Status:** Superseded by D-033, which keeps the legal moves in code and the append-only history, and replaces `from_status` and the PHP-maintained `current_status` with a chained history and a database-maintained status.
- **Decision:** the allowed statuses and moves live in one enum (`ApplicationStatus`); each move names the type of decision it needs (`outcome`, `exception`, `excuse_accepted`; `created` for the first row). `ApplicationLifecycle` is the only code that changes `current_status`: it locks the application row, checks the move and the decision type, requires a reason and an actor for person-made decisions, writes the history row and updates the status in one transaction. History rows (`application_status_events`) cannot be updated, deleted or truncated: PostgreSQL triggers refuse it. CHECK constraints require the first row to be the only one without a previous status and person-made decisions to carry a reason and an actor. Each row can point to the exam attempt, interview or contract that caused it (plain reference, no foreign key until those tables exist).
- **Why:** the analysis doc says illegal jumps must be refused even for direct API calls and history must not be erasable; the triggers make that true for every code path, not just the application's own.
- **Cost:** the current status is stored on the application as well as in the history; keeping them in step relies on the lifecycle class being the only writer (`current_status` is not mass assignable).
- **Idea kept for later:** a trigger that also updates `current_status` from each inserted history row would make divergence impossible.

### D-028 Duplicate applications

- **Status:** Superseded by D-037 (one live application per person, whatever the job position). Kept for history. Was: accepted conservatively; policy open (OD-7). Amended by D-032: the national number is now required, so every person is recognised by it and "people without a national ID are always new records" no longer applies. The rule is per job position (the table formerly called job titles).
- **Decision:** a person cannot have two live applications for the same job title: a friendly 422 plus a partial unique index. An application counts as live until it reaches a final status (failed interview, contract written). A person is recognised only by national ID, never by name, so two people with the same name are never merged; people without a national ID are always new records. A matched person keeps the data already on file: a new application never overwrites it.
- **Why:** the analysis doc leaves repeat applications to policy; the conservative rule prevents accidental duplicates and is a one-line change to relax.

### D-029 Shared Arabic text handling

- **Status:** Accepted
- **Decision:** `ArabicText::searchKey()` (no harakat or tatweel; alef variants, ى/ي and ة/ه merged; whitespace collapsed; lowercase) is stored next to names and in the lists, and used for search and duplicate-name checks. `ArabicText::digitsToAscii()` normalises digits. It differs from the username rule (D-008) on purpose: usernames keep ة and ه apart, names must find each other.
- **Why:** staff type the same name many ways; searching "احمد" must find "أحمد". Display values are never altered.
- **Limitation:** search is a contains-match on a normalised column, fine for thousands of rows; very large volumes would need a trigram index.

### D-030 Audit seam

- **Status:** Accepted
- **Decision:** `AuditTrail::record()` (Audit module) is the single call for "who did what"; `AccessLog` delegates to it. Today it writes to the application log. (The `candidate.sensitive_viewed` event was removed with D-032.)
- **Why:** modules record events now; the audit table can be added later by changing one class.

---

## 6. Recruitment core, final shape (Step 3 completed)

### D-031 Recruitment schema: what lives on the person, what lives on the application

- **Status:** Accepted (owner's redesign after ERD diagram 07; supersedes part of D-024). Amended by D-043 (the residence area is optional) and D-044 (`studies_discontinued`).
- **Context:** the owner reshaped the migrations to follow the ERD: every value that comes from a list is a foreign key to a managed list, and there is no sensitive data (marital status and health are ordinary list values).
- **Decision:**
  - **Nine managed lists**, all the same shape (`name_ar`, `name_key`, soft delete): `districts` (governorates), `areas`, `nominating_entities`, `job_positions`, `application_categories`, `academic_qualifications`, `specializations`, `marital_statuses`, `health_conditions`. Tables are plural, foreign keys singular (`job_position_id`), so Eloquent needs no custom key names.
  - **Areas live inside districts:** `areas.district_id`; an area name is unique within its district only (the same neighbourhood name can exist in two governorates). An application's `(residence_district_id, residence_area_id)` is a composite foreign key to `areas (district_id, id)`, so a mismatched district and area cannot be stored, by any code path. An area's district never changes (renaming does not move it).
  - **`candidates` (the person)** holds only facts that do not change between applications: national number, full name, mother's name, birth date, birth place (district), notes, `is_active`.
  - **`applications`** holds everything declared at application time: nominating entity, job position, category, existing employee number, academic qualification, specialization, study years (≤ 15), Quran parts (≤ 30), marital status, health condition plus free-text `health_details`, residence district / area / details, phone number, the three availability answers, notes. Every list reference and the availability answers are required (as in the owner's migration).
  - `health_details` moved from the `health_conditions` list to the application: details belong to one person, not to a list entry.
  - `availability_declared_at` was dropped: the answers are required at creation, so it always equalled `created_at`. When editing arrives, changes are recorded by the audit trail.
  - Default lists seeded: the 14 districts and four marital statuses (أعزب، متزوج، مطلق، أرمل). Everything else is entered through the API.
- **Why:** facts about the person must not be able to disagree between two applications (birth place is on the person for that reason); declarations can change between applications, so they belong to the application; database-level pairing of area and district protects the coming Excel import as much as the API.
- **Rejected:** areas as free text (no reporting by neighbourhood); residence on the person (a second application could not record a move); a check of area/district in PHP only.

### D-032 National number stored as given

- **Status:** Accepted (owner's decision; supersedes D-026)
- **Decision:** `candidates.national_number` is plain text, exactly 11 ASCII digits (CHECK), unique and required. Arabic-Indic digits and separators are converted before validation and in the model mutator. It is shown in full to anyone who can read the application; search is exact. The `candidates.view_sensitive` permission no longer exists (removed from the catalogue).
- **Why:** the owner does not treat it as sensitive; plain storage keeps search, reporting and the import simple. Text, not a number, so leading zeros survive.
- **Consequences:** anyone with database or backup access can read national numbers; protect backups accordingly. Because it is required, a person without a national number cannot be entered (the import will reject such rows). OD-8 is resolved (11 digits).

### D-033 Status history as a chain; the database writes the current status

- **Status:** Accepted (owner's idea from the Step 3 review, refined; supersedes D-027). Amended in Step 4: `related_type`/`related_id` replaced by cause columns (D-038); `occurred_at` is the real decision date, checked by the database (D-039); new statuses `contract_ended` and `closed` (D-037).
- **Context:** the owner proposed making the current status a reference into the history so the two can never disagree, and linking each event to the one before it to guarantee the order.
- **Decision:**
  - `application_status_events` keeps `application_id` and gains `previous_event_id`: a composite foreign key `(application_id, previous_event_id) → (application_id, id)`, so the previous event is always of the same application. `previous_event_id` is unique (the chain cannot fork) and a partial unique index allows one first event per application. `from_status` is gone: it is the previous event's `to_status`, so it cannot be wrong.
  - Only `created` has no previous event, and it always leads to `awaiting_exam` (CHECK). Exceptions and accepted excuses still need a non-blank reason and an actor (CHECK).
  - Triggers: a new event must follow the application's latest event (the application row is locked first); after insert, the event's status is copied into `applications.current_status`; a new application must start at `awaiting_exam`; any other write to `current_status` is refused; and a deferred constraint trigger refuses, at commit, an application that has no first event. History rows still refuse UPDATE, DELETE and TRUNCATE.
  - The legal moves and the decision type each needs stay in `ApplicationStatus` (PHP). `ApplicationLifecycle` checks them, appends the event and reloads the application.
  - Responses list the history in chain order, each event with `from_status` derived from the previous one.
- **Why not a foreign key from `applications.current_status` to the latest event:** filtering by current status is the most frequent query (lists, dashboards, counts) and should stay one indexed column, not a join. Events must keep `application_id` anyway: without it an application's history can only be found by walking the chain, it cannot be indexed, and period reports cannot tell which application an event belongs to. A plain self foreign key also would not stop forks or links to another application's events.
- **Consequences:** `current_status` can never disagree with the history, whatever code writes to the database. `pg_trigger_depth()` distinguishes the sync trigger from direct writes; a future trigger that writes `current_status` itself would be allowed through, so none should. The deferred check runs at commit, which tests never reach: tests use `SET CONSTRAINTS applications_have_history IMMEDIATE`.

### D-034 Managed lists use integer keys

- **Status:** Accepted (owner's question during the Step 3 review; exception to D-003)
- **Decision:** the nine managed lists use `bigint` auto-increment keys. People, applications and history keep UUIDs.
- **Why:** UUIDs protect against guessing record ids; the lists are readable by every staff user, so there is nothing to protect. Integers are smaller, faster to index and readable in the database and in Excel mappings.

### D-035 Staff usernames are Latin only

- **Status:** Accepted (owner's decision; supersedes D-008's Arabic revision)
- **Decision:** usernames are 3 to 50 characters from `a-z . _ -`, stored trimmed and lowercase (CHECK in PostgreSQL, mutator in the model, same normalisation at login). Arabic names go in `first_name` / `last_name`, which PostgreSQL stores and displays correctly.
- **Why:** one keyboard layout for logging in; no look-alike Arabic spellings of one account.

### D-036 Staff token lifetime: one day

- **Status:** Accepted (owner's change in the code; replaces "one week" in D-009 and OD-5)
- **Decision:** `staff_auth.token_lifetime_minutes` is 24 hours.
- **Why:** shorter exposure if a token leaks; staff log in from their approved device each working day.

---

## 7. Step 4: lifecycle refinements and Excel import

### D-037 One live application per person; only a contract end or a closure ends one

- **Status:** Accepted (owner's rule and answer, Step 4; supersedes [D-028](#d-028-duplicate-applications))
- **Context:** the owner: "a candidate can't have different applications with different job positions at the same application lifecycle", and the person may be nominated again only after "the contract ended and was not renewed" or "the application was finished by authorized staff".
- **Decision:**
  - Two new statuses: `contract_ended` (انتهى العقد ولم يُجدَّد), reached from `contract_written` by an `outcome` (the Contracts module will record it), and `closed` (أُغلق الطلب), reached by a new decision type `closure` that needs a reason and an actor.
  - Every status before a written contract can be closed: awaiting exam, exam failed/absent, awaiting interview, interview failed/absent, awaiting contract. A written contract cannot be closed; it ends only through `contract_ended`.
  - Only `contract_ended` and `closed` are terminal. Everything else, including failed, absent and contract written, is **live**.
  - At most one live application per person, whatever the job position: a partial unique index on `applications (candidate_id) WHERE current_status NOT IN ('contract_ended', 'closed')`, plus a readable 422 naming the live application's nomination number.
  - Closing needs the new permission `applications.close`, **individual-only** like `exceptions.approve` (granted per user, never by a role). Endpoint: `POST /applications/{id}/close {reason, occurred_at?}`.
- **Why:** follows the owner's rule literally. Because terminal statuses can never change, an ended application can never come back to life, so the "one live" rule cannot be broken by reopening an old application.
- **Consequence:** a person who failed or was absent stays blocked until authorised staff close the application. That is intentional: someone decides, and the reason is recorded.
- **Rejected:** treating failed and absent as "ended" (the owner wants a person's decision); one live application per person *and* position (the old D-028).

### D-038 What caused a status change: one real foreign-key column per cause

- **Status:** Accepted (owner's question "is it better to make related_type/related_id polymorphic?", Step 4)
- **Decision:** `related_type`/`related_id` are removed. Each kind of cause (exam attempt, interview, first contract, later absence case or exception record) gets its own nullable column on `application_status_events` (`exam_attempt_id`, …): a real foreign key with `ON DELETE RESTRICT` and an index. A CHECK `num_nonnulls(...) <= 1` allows at most one cause per event. The module that owns the cause's table adds its column in its own migration with `StatusEventCauseColumns::add('exam_attempt_id', 'exam_attempts')`, which also rebuilds the CHECK. Cause models implement `Recruitment\Contracts\StatusChangeCause` (they name their column), and `ApplicationLifecycle::move(..., cause: $attempt)` stores the key.
- **Why:** a Laravel polymorphic relation (`morphTo`) is the same two untyped columns: it cannot have a foreign key, so the database could not check that the attempt exists, nor stop someone deleting an attempt the history points to (D-007). The set of causes is small and known, which is exactly when separate foreign keys are the better design.
- **Rejected:** polymorphic `morphTo` with a morph map (no referential integrity); a generic `causes` table (an extra join for every history read).
- **Cost:** one column per cause type, added by later migrations.

### D-039 `occurred_at` is when the decision happened, `created_at` when it was recorded

- **Status:** Accepted (owner's question "what is occurred_at for?", Step 4)
- **Decision:** `ApplicationLifecycle::move(..., occurredAt: $date)` takes the real date of the decision (for example an interview held on Monday, entered on Wednesday); it defaults to now. Refused in PHP (readable error) and in the database: a date in the future (CHECK `occurred_at <= created_at`) or earlier than the previous event's date (in the trigger that already guards the chain). The close endpoint accepts `occurred_at`. History order is still the chain, never the dates.
- **Why:** reports by period ("absent in October") need the date things happened, not the date someone typed them. Before this, the column always equalled `created_at`, which made it redundant.

### D-040 The Excel import reads the legacy template by header text, and never guesses silently

- **Status:** Accepted (owner chose the legacy template; analysis doc §09, §23, §24)
- **Decision:**
  - The import reads the "بيانات المرشحين" sheet of the legacy workbook (.xlsx or .xls). It picks the sheet by name, otherwise the best match, and finds the header row in the first 20 rows (titles may sit above it). Each column is mapped to a field by **header text**, after Arabic normalisation (أ/ا, ى/ي, ة/ه, harakat and punctuation ignored); an exact match beats a contained one. Header texts live in `config/imports.php`, so a renamed header is one config line. Unused columns (A, X, Y, Z in §23) are kept in the row's raw cells but not imported. Formulas are never evaluated; their saved value is used.
  - The person's identity is the 11-digit national number (D-032). One row per person per file: later rows with the same number are duplicates.
  - Reading cells: Excel date serials in the workbook's own date system (1900 or 1904, AC-17) and day/month/year text; Arabic-Indic digits converted; yes/no in common words (نعم/لا, ملتزم/غير ملتزم, yes/no, 1/0, ✓). List values are matched by normalised name; archived entries are named as such. Two legacy cells hold a list value plus free text: the health cell ("مريض - سكري" → condition + details) and the residence cell ("المزة، شارع الجلاء" → area of the residence district + details). Unmatched text is kept as details so nothing is lost.
  - **Nothing is guessed:** a national number or phone that Excel stored as a number has probably lost its leading zero. The row is invalid, with a suggestion (AC-16). An unknown list value makes the row invalid: staff pick the right entry for that row, or add it to the list and correct the row. Lists never grow silently (owner's choice).
  - A national number that belongs to a person on file with a different name, mother's name or birth date makes the row **needs review**: staff confirm it is the same person (the stored data is kept, as in the API) or exclude the row (owner's choice). The confirmation is tied to that national number; correcting the number cancels it.
  - The same rules as `POST /applications`: limits live in `Recruitment\Support\ApplicationRules`, used by both the API request and the import checker (D-042).
- **Rejected:** fixed column letters (one moved column would shift every field); auto-adding unknown list values (typos become permanent entries); padding numeric national numbers with zeros (a wrong guess would merge two people).
- **Confirmed (OD-11):** the header aliases were checked against the real template; its exact headers are now the first alias of each field.

### D-041 Import batches: staged rows, review, then an all-or-nothing approval

- **Status:** Accepted (analysis doc §09 steps 1 to 7, AC-10, AC-19)
- **Decision:**
  - **Upload** keeps the original file unchanged (`storage`, path in the batch), records the sheet, header row and column map, and stores every non-empty row with its raw cells, the values understood (`data`, dotted application keys), what could not be read (`parse_issues`) and the result of the last check (`issues`, each an error or a review point). Nothing becomes an application.
  - **The same file** (sha256) cannot be uploaded again while its batch is not cancelled: a partial unique index plus a readable 422 (AC-10).
  - **Before approval** staff correct values (`PATCH rows/{row}` with dotted keys; the raw cells stay as read), confirm people, exclude rows with a reason, or bring them back. Every change is audited, and the **whole batch** is checked again, since a corrected national number can create or remove a duplicate elsewhere.
  - **Approval** locks the batch, takes a transaction-level advisory lock per national number in the batch (`CreateApplication::lockPeople`; the API create takes the same lock), then checks the batch again. Under those locks nothing can overtake the check. Rows that need review block the approval (422), and so does a row that staff last saw as valid but that fails this final check (someone opened an application for that person meanwhile): a row is never left out silently. Every valid row is then created through `CreateApplication::createLocked()` in one transaction, numbered in sheet order from one `NominationNumberGenerator::reserve()`. If anything still fails, nothing is imported and the batch stays pending. Invalid and excluded rows stay, with their reasons, in the batch and the downloadable report.
  - **Re-uploading** the same people in another order (AC-19): each row finds a person with a live application and is invalid; no number changes, nothing is created twice.
  - Batches and rows are never deleted; a pending batch can be cancelled with a reason. Every change to a batch, including revalidation, takes the same batch lock as approval.
  - The report writes every text cell as text, so a value such as `=HYPERLINK(...)` typed as a name never runs as a formula when the report is opened. An `.xlsx` whose unpacked size exceeds `imports.max_unpacked_megabytes` is refused before it is loaded.
- **Why one transaction without savepoints:** the first version created each row in its own savepoint so one failure would not stop the others. Measured, approval time grew quadratically (2,000 rows: 88 s). PostgreSQL slows down once one transaction holds more than 64 subtransactions, and the counter row was updated once per row. With the locks there is no expected failure left to isolate, so approval is now linear (2,000 rows: 12 s; 5,000: 32 s).
- **Limit:** upload and approval run inside the request; `imports.max_rows` defaults to 2,000. Larger files need a queued approval first.

### D-042 One source for the limits of an application's fields

- **Status:** Accepted
- **Decision:** `Recruitment\Support\ApplicationRules` holds the limits (name length and forbidden digits, phone pattern, birth date lower bound, study years, Quran parts, text lengths) and the list each list field points to. `StoreApplicationRequest` and the import's `ImportChecker` both read them. The database CHECKs repeat the ranges as the last guard.
- **Why:** the import cannot use the request's validator row by row (it needs Arabic messages per cell, and it must check thousands of rows with the lists loaded once). Sharing constants keeps the two from drifting apart.

---

## 8. Step 4 follow-up: the real template

### D-043 The residence area is optional

- **Status:** Accepted (owner's answer after the real template was examined; amends D-031)
- **Context:** in the real legacy template, "مكان الإقامة الحالية" is free text (town or place names), not an entry of our areas list.
- **Decision:** `applications.residence_area_id` is nullable. When given, it must still be an active area of the residence district (request rule and composite foreign key; a null area skips the pair check). The import uses an area only when the text starts with an area of the residence district; otherwise the whole text becomes `residence_details`, without an error. `POST /applications` accepts a null area.
- **Why:** forcing an area would have made every legacy row need a manual correction.

### D-044 Discontinued studies

- **Status:** Accepted (owner's answer)
- **Context:** the template's study-years list offers "1" … "6" and "1 ومنقطع" … "6 ومنقطع" (studied N years, then stopped).
- **Decision:** new boolean `applications.studies_discontinued` (NOT NULL, default false). CHECK: discontinued needs `studying_years`. The import reads "3 ومنقطع" (also "3 منقطع", Arabic-Indic digits) as 3 years + discontinued; the API accepts `studies_discontinued` and then requires `studying_years`.
- **Update (owner, Step 6):** the API accepts `studies_discontinued: null` (or no key) as false, so the frontend can always send the field; opening and correcting an application both turn null into false.

### D-045 Security of uploaded workbooks

- **Status:** Accepted (owner: "you don't handle the security concerns when importing file")
- **Context:** before this, the import checked the extension, used Excel-only readers, never evaluated formulas, wrote the report as text, and refused unpacked sizes over a limit. It did not look at what the file contains before parsing it, and it loaded every sheet.
- **Decision:** `FileInspector` runs before any spreadsheet library touches the file, and `WorkbookReader` follows it:
  - **Format by content:** `.xlsx` must be a zip (`PK\x03\x04`), `.xls` an OLE file; anything else (PDF, HTML, CSV renamed) is refused. The reader is chosen from the content, never sniffed.
  - **Forbidden parts:** macros (`vbaProject.bin`, VBA in `.xls`), external workbook links, embedded objects, ActiveX, external data connections, executable names, and part names with `..` or a leading `/`. The original is kept and staff may open it later, so it must be safe to open.
  - **Zip bombs:** any part over 1 MB that expands more than 200 times is refused. Only the parts actually read (the chosen sheet, shared strings, styles) are decompressed, and their unpacked size is limited (`max_unpacked_megabytes`). The real template (217 MB unpacked, 6 MB for the candidates sheet) loads in about 5 s with 44 MB of memory, because its other 11 sheets are never opened.
  - **Unsafe formulas** in the candidates sheet refuse the file, with the cell named: DDE (`cmd|…`), external workbooks (`[1]`, `[file.xlsx]`, drive and UNC paths), `WEBSERVICE`, `FILTERXML`, `CALL`, `REGISTER`, `RTD`, `EXEC`, `HYPERLINK`, `IMPORTDATA`, `IMPORTXML`. Text that merely starts with `=` is a string and is accepted. Formulas are still never evaluated.
  - **Bounded data:** cell text is cut at 4,000 characters when read (longer values remain too long for the checker, so nothing is silently accepted); raw cells are stored at 1,000 characters.
  - **Filename:** the client's name loses path parts, control characters and `"<>|:*?`, and is cut at 255 characters. The stored file is always `{batch-id}.xlsx|xls` on the private disk.
  - **Requests:** upload is limited to 10 per minute per user; downloads are always attachments with a fixed content type and `X-Content-Type-Options: nosniff`; the report writes every text cell as text.
  - **Already in place:** authentication, permissions, private storage, no evaluation of formulas, XML parsed without network access or entities, audit of every step.
- **Not done here (deployment):** antivirus scanning of stored originals, and a retention period for files that contain personal data. Both belong to the hosting setup.

### D-046 A row without a national number refuses the whole file

- **Status:** Accepted (owner's rule)
- **Decision:** at upload, if any data row has an empty national number cell, the whole file is refused (422), naming the rows (first 20) and the count. Nothing is stored. A national number that is present but malformed (wrong length, leading zero lost) stays a correctable row error.
- **Why:** the national number is the person's identity (D-032); a file that omits it is incomplete at the source and goes back to the entity.

### D-047 Approving an import is a separate, individual permission (four eyes)

- **Status:** Accepted (owner's rule: "a staff can import an excel file but it needs approval before it can be adopted")
- **Decision:** new permission `imports.approve`, individual-only (granted per user, never by a role). `imports.manage` uploads, corrects, reviews, excludes and cancels; `imports.approve` approves; either may read batches, rows, the original and the report. Whoever uploaded a batch can never approve it, even holding `imports.approve` (403).
- **Why:** an import creates many people and applications at once; a second person checking the batch before it takes effect is the control the owner asked for.

---

### D-048 Marital statuses are seeded by the owner and read-only through the API

- **Status:** Accepted (owner: "I don't want marital_status reference data to be added from api, I will seed it to the database")
- **Decision:** `ReferenceType::isEditableThroughApi()` is false for `marital-statuses`. Reading works as for every list; adding, renaming, archiving and restoring answer 403 `recruitment.list_is_seeded`. `MaritalStatusesSeeder` is the only way to change the list.
- **Why:** the list is fixed by law and small; letting staff add spellings or values only creates noise in reports.
- **Consequences:** a new list that should also be fixed is one more case in `isEditableThroughApi()`. The import still reads the values (its lookup is unchanged).
- **Update (owner, Step 6: "don't show marital_status in the api also"; the owner chose to keep reading):** the change endpoints bind `{type}` to `EditableReferenceType` (every list but marital statuses), so `marital-statuses` there is an unknown list (404) and is absent from the API documentation. `GET /reference/marital-statuses` stays for the application form's dropdown. The 403 message `list_is_seeded` is gone.

### D-049 Correcting people and applications; deactivating a person

- **Status:** Accepted (closes OD-10)
- **Context:** staff must fix typing mistakes after an application is opened, and the owner needs a way to stop a person from being nominated again without deleting anything.
- **Decision:**
  - `PATCH /candidates/{id}` corrects the person (`national_number`, `full_name`, `mother_name`, `birth_date`, `birth_place_id`, `notes`); `PATCH /applications/{id}` corrects what was declared in a **live** application. Send only what changes; each field follows the same rule as when the application is opened (`StoreApplicationRequest::personRules()` / `applicationRules()`, and through them `ApplicationRules`, D-042). Combined rules are checked on the result: the area must belong to the (possibly new) district, discontinued studies need the years.
  - Not editable there: the person of an application, its nomination number, its status (only `ApplicationLifecycle`, D-033), and anything of an ended application (422 `recruitment.application_ended`: an ended file is evidence).
  - A national number may be corrected, but never to another person's (422 `recruitment.national_number_taken`); the change takes the advisory lock of both numbers (D-041).
  - `POST /candidates/{id}/deactivate` (body `reason`) and `/activate`. A person with a live application cannot be deactivated (end the application first). An inactive person cannot be nominated, by the API or by an import (`candidate_inactive`). The columns `deactivated_at`, `deactivated_by`, `deactivation_reason` say when, who and why; a CHECK requires all three (with a real reason) whenever `is_active` is false and none of them when it is true (the owner added `deactivated_by`; the symmetric form followed).
  - Every change is audited with its before and after values (`candidate.updated`, `application.updated`, `candidate.deactivated`, `candidate.activated`).
- **Why:** correcting in place keeps one record per person (the national number stays the identity); deactivating instead of deleting keeps every past application and its history intact.
- **Rejected:** editing an ended application (its data is what the decisions were based on); deleting people.
- **Permissions:** reading people needs `reports.view` or `candidates.manage`; changing them needs `candidates.manage`.

### D-050 Seeders: reference lists everywhere, demo data locally

- **Status:** Accepted
- **Decision:**
  - `ReferenceDataSeeder` runs in every environment and seeds every managed list except the nominating entities, which the owner seeds (OD-13): districts, areas, marital statuses, job positions, categories, academic qualifications, specializations, health conditions. Each list is the legacy template's values (its hidden `_Lists` sheet, or the values its rows use) plus clearly separated suggestions (`FROM_TEMPLATE` / `SUGGESTED` constants). Entries are matched by normalized name, archived ones included (`SeedsReferenceList`), so running again never duplicates, never restores what staff archived and never overwrites a respelling.
  - `RecruitmentDemoSeeder` and `ImportsDemoSeeder` run only with `APP_ENV=local`. They create invented people (national numbers starting with `09`), applications in every status with dated histories, a person nominated twice, a deactivated person, and three import batches (approved, cancelled, pending with every kind of row). They go through the real actions: the triggers accept history only that way, so the demo follows the same rules as real data. Dates are produced by moving the clock back while seeding. Each runs once.
  - When no nominating entity exists yet, the demo adds the five names used in the template, matched by normalized name so the owner's seeder (if it also matches by name) adds nothing twice.
- **Why:** an empty database shows nothing on screens or reports; a demo that bypassed the actions would be data the real system can never produce.
- **Update (Step 6):** the owner added `NominatingEntitiesSeeder` to `ReferenceDataSeeder`, so every list is seeded and the demo no longer adds entities. `ExamSubjectsSeeder` and `DocumentTypesSeeder` also run in every environment; `ExamsDemoSeeder` and `DocumentsDemoSeeder` locally.

### D-051 Fingerprints are checked as hexadecimal

- **Status:** Accepted
- **Decision:** `App\Rules\HexString` validates hexadecimal text, optionally grouped with spaces or dashes, with an exact number of digits (32 for the device fingerprint shown on screen). Laravel has no general hexadecimal rule (`hex_color` accepts colours only). `ApproveDevice` compares `HexString::digits()` (separators removed, uppercase) with the stored fingerprint.
- **Why:** a mistyped letter is reported as a format error before the comparison, and stripping every non-hex character (the earlier code) could turn garbage into a valid-looking value.

---

### D-052 The pass rules are versioned data; the engine decides on exact fractions

- **Status:** Accepted (analysis doc §04: settled rules, formula, worked examples, "the pass policy version is stored with every result"; the owner confirmed the doc's rules over the legacy sheet's, OD-15)
- **Decision:**
  - `exam_pass_policies` holds the rules as versions: pass mark, tolerance floor, number of tolerated subjects, the average to exceed. Version 1 (60, 58, 1, 70) is inserted by the migration, because every environment needs it. Rows are append-only (trigger); a new rule is a new version. A plan is published under the current version and keeps it; results (later) will store it too.
  - `ExamScorer` is pure PHP (no database, no clock). Part score = achieved / max × 100; subject score = Σ(part score × weight) / Σ weight; average = Σ subject scores / number of subjects (subjects weigh equally; a subject with parts counts once). Failed when any subject is below the floor, or more subjects than tolerated are below the pass mark, or the average is not strictly above the threshold. Every reason that applies is returned, with the subjects concerned.
  - Every value is an exact fraction (`brick/math` `BigRational`, already installed with Laravel); nothing is rounded before a comparison. 59.995 is in the tolerance band although it displays as 60.00, and an average of 70.0003 passes although it displays as 70.00. Marks are integers or decimal strings, never floats.
  - The engine only gives the computed result. An exception is a separate administrative decision (D-037 `exception`); it never rewrites the computed result (§07).
- **Why:** reproducible results (an old result can be recomputed under its own policy) and no rounding disagreements at the boundaries the rules care about (58, 60, 70).
- **Rejected:** PHP floats (59.99 is not exact); bcmath/gmp (not installed here); rules as constants in code (past results would change with them).

### D-053 Exam plans: one per job position, versioned, frozen when published

- **Status:** Accepted (analysis doc §05 "Exam Plan Version: frozen when an attempt is scheduled or started")
- **Decision:**
  - Subjects (`exam_subjects`) and their parts (`exam_subject_parts`, with a default weight) are managed like the lists: integer keys, unique normalized names (parts per subject), archived instead of deleted.
  - A plan version belongs to a job position and lists its subjects in exam order. A subject is scored as a whole (its question count) or from parts (each part: weight and question count). The version stores its own copy of every weight, so editing a part's default never changes a published plan.
  - Life: `draft` → `published` → `retired`. At most one draft and one published version per job position (partial unique indexes). Publishing checks completeness (at least one subject, question counts, durations matching the session mode, nothing archived), then retires the previous published version in the same transaction. Every operation locks the job position row, so version numbers and the one-draft/one-published rules never race.
  - The database refuses any change to a published or retired version and to its subjects and parts, except retiring (triggers), and only drafts can be deleted. A plan part must be a part of its plan subject's subject (composite foreign keys, like an area in its district).
  - ~~`session_mode`~~ (Step 6): replaced by one session per plan, see D-054.
- **Why:** bookings and results will point at a version; what a candidate was examined on must never change afterwards.
- **Consequences:** the question-bank check at publishing ("not enough questions: refuse to publish instead of silently asking fewer", §05) comes with the question bank (Exams part 2).

---

### D-054 One session; parts marked by hand; easy, medium and hard questions

- **Status:** Accepted (owner, Step 6: "all in one session except the تجويد, the candidate in the same day will take it oral not in Exam page, so the result of it will be entered manually"; "the exam must contain spread across all difficulties (easy, medium and hard)")
- **Decision:**
  - A plan has one session and one duration. `session_mode` and per-subject durations are gone.
  - A part is assessed `exam` or `manual` (analysis doc §05: a part has "a weight inside its subject and an assessment pattern"). The part has a default (`default_assessment`; التجويد is `manual` in the seeder), and the plan stores its own copy. A manual part has no questions and has `max_score`, the scale staff mark it out of (100 by default, 40 in the demo as in the legacy sheet). The engine takes the entered mark as `Mark::of(achieved, max_score)`, so التجويد still weighs 40 inside القرآن الكريم.
  - Every exam subject or part asks easy, medium and hard questions, each at least once (CHECKs on the plan rows; all null only in an unfinished draft or for a subject scored from parts). The counts are frozen with the version.
  - Publishing requires at least one online question and enough active questions of each difficulty in the bank for every exam item (§05: "when the bank lacks questions, publishing is refused and the shortage shown instead of silently asking fewer"). A subject scored as a whole draws on all its questions; a part on its own.
- **Why:** the owner's exam day: one online session, التجويد oral and recorded by staff.
- **Consequences:** recording manual marks belongs to attempts/results (next step). The analysis doc §04 said "no oral grade inside the exam"; التجويد is outside the online exam, as the owner described.

### D-055 The question bank

- **Status:** Accepted (analysis doc §05 "Question", "Question Version": "the version used in an attempt is never modified")
- **Decision:** a question belongs to a subject (optionally to one of its parts; composite key) and has a difficulty. Its prompt, 2 to 6 options and single correct option are versions, append-only in the database; a correction adds a version, same content adds none. The difficulty may be reclassified in place; the subject and part never change. Archived questions are not counted or drawn. Only `exam_bank.manage` reads the bank (it holds the answers); a candidate will never receive `correct_option`.
- **Rejected:** editing a question in place (a session that drew it would change); options in their own table (a fixed small list read and written together: a CHECKed jsonb array is simpler).

### D-056 Exam sessions (decided; built next)

- **Status:** Accepted (owner, Step 6: "the questions of the exam are randomized in order only (same questions) between candidates"; "the manager can add extended time to all applicants at the time for an exam session"; options are not shuffled)
- **Decision (to build with sessions and attempts):**
  - An exam session is a sitting of one published plan version at a time and place, with its candidates (applications at `awaiting_exam`).
  - The questions are drawn **once per session** from the bank by the plan's counts, and the drawn question versions are frozen for the session: every candidate of the session gets the **same questions**, each in a **different order** (a per-attempt permutation, stored). The options keep their written order.
  - The session's end can be **extended for everyone** by a manager (`attempts.extend`, individual permission), with a reason; it moves every running attempt's server deadline and is audited (§07 "extending exam time": duration, reason, attempt). Extending one attempt stays possible too.
  - Manual parts (التجويد) are marked by staff for each candidate of the session.
- **Why:** fairness between candidates of a sitting, while making copying from a neighbour harder.

### D-057 Documents of people and applications

- **Status:** Accepted (owner, Step 6: "a section to upload required/output documents for each candidate/application along application lifecycle, for example: ID card image, certifications, resumes, official documents, contracts"; missing documents are shown, never blocking)
- **Decision:**
  - Document types: `scope` `candidate` (the person's: kept for every later application) or `application` (one application's: the contract); `direction` `incoming` (brought by the candidate) or `outgoing` (issued); `required_from_status` (an application status, or null: optional). The scope never changes.
  - A document records the upload (sanitised original name, detected type, size, SHA-256, who, when, notes). It is never changed or deleted (trigger); it is archived once with a reason and stays as evidence. The application, when there is one, is the same person's (composite key); the type's scope decides whether there is one (trigger).
  - Files go to the private disk under generated names, accepted only as PDF, JPEG, PNG or WebP (10 MB), checked by content, never by extension alone. Downloads are attachments, `nosniff`, `no-store`, audited.
  - Requirements per application: a type is required once the application's history contains its status; present means an active document (the person's or this application's, by scope); missing ones are listed.
  - Reading needs `reports.view`, `candidates.manage` or `contracts.manage`; uploading and archiving `candidates.manage` or `contracts.manage`; types are managed with `candidates.manage`.
- **Rejected:** blocking status moves on missing documents (owner's choice); deleting files (evidence of the file's history).
- **Consequences:** virus scanning and a retention period are for the deployment, as for import files (D-045).

---

### D-058 Adding staff users

- **Status:** Accepted (the owner added `users.add` and `POST /api/v1/staff/new`)
- **Decision:** `RegisterStaffUser` creates the account and attaches its first role in one transaction and logs `access.user_created`. Whoever holds `users.add` without `users.manage` may only give a role whose permissions they all hold: otherwise `users.add` could create a system administrator and so reach every permission. The personal number is 4 to 50 digits (`numeric` would also accept `-12`, `1.5`, `1e5`). The password must be strong and is checked against known leaks (`uncompromised()`).
- **Consequences:** `uncompromised()` calls the Have I Been Pwned range API over the internet (only a hash prefix leaves the server). Without internet access a registration can wait up to 30 seconds (its timeout) and then the password is let through unchecked; drop it if the server has no internet access by policy. A new account still needs an approved device to log in (D-017).

---

## Resolved and deferred questions

| ID    | Question                                                                                            | Outcome                                                                                                                                                                                                                                                  |
| ----- | --------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-1  | One user per device, or shared devices?                                                             | **Resolved:** one device belongs to one staff user, and each staff user has one (approved) device (D-022).                                                                                                                                         |
| OD-2  | Registration spam on the unauthenticated endpoint                                                   | **Resolved:** throttle by IP and a cap on pending devices (built). Admin-issued enrolment codes remain a fallback if abuse appears.                                                                                                                |
| OD-3  | Emergency web portal vs device binding (analysis doc §11)                                          | **Deferred:** the owner wants a web fallback *after the main work*. Until then there is none. A browser holds no installed key, so it would have to register as its own, weaker kind of device (for example a non-extractable WebCrypto key).    |
| OD-4  | How are candidate codes delivered?                                                                  | **Resolved:** printed and handed out at the exam venue (D-016).                                                                                                                                                                                    |
| OD-5  | Token lifetime                                                                                      | **Resolved for staff:** one day (D-036; was one week). Candidates: to be decided with candidate login (must outlive the longest exam).                                                                                                             |
| OD-6  | May a user own several devices?                                                                     | **Resolved:** no. One approved device per user.                                                                                                                                                                                                    |
| OD-7  | Repeat applications: may a person apply again for a job title they already applied for, and when?   | **Resolved:** one live application per person; a new one only after the contract ended without renewal or authorised staff closed the application (D-037).                                                                                         |
| OD-8  | Format rule for the national ID (length, check digits)                                              | **Resolved:** exactly 11 digits, stored as text (D-032). No check digit is validated.                                                                                                                                                              |
| OD-9  | Operational: who keeps `APP_KEY` (and the optional blind-index key) safe?                         | **No longer applies to recruitment data:** nothing in it is encrypted since D-032. `APP_KEY` still signs and encrypts framework data; keep it backed up.                                                                                         |
| OD-10 | Editing a candidate's file, and Excel import | **Resolved:** import (D-040, D-041); correcting people and live applications, and deactivating people (D-049). |
| OD-11 | The real header row of the legacy "بيانات المرشحين" sheet                             | **Resolved:** checked on the owner's anonymised template: header row 5, data from row 6, dates as serials, national numbers and phones as text, yes/no as نعم/لا, residence place free text (D-043), study years with "ومنقطع" (D-044). |
| OD-12 | Phone numbers stored as numbers in the legacy file                                                  | **Resolved for the template:** its phone and national number columns are text-formatted, so leading zeros survive. A file that stores them as numbers still gets each row flagged.                                                                 |
| OD-13 | Nominating entities are typed by hand in the template ("تُكتب يدويًا") | **Resolved:** the owner's `NominatingEntitiesSeeder` (in `ReferenceDataSeeder`) seeds the template's five; spelling variants are matched by normalisation. |
| OD-14 | The template's governorate list contains "تدمر", which is not one of the 14 seeded governorates | **Resolved:** the owner added تدمر to `DistrictsSeeder` (15 entries). |
| OD-15 | The legacy exams sheet computes results differently from the analysis doc | **Resolved (owner):** the doc's rules are right (D-052): one subject from 58 tolerated, average strictly above 70. Results copied from old sheets can differ. |
| OD-16 | One exam session for all subjects, or a session per subject? | **Resolved (owner):** one session; التجويد is oral the same day and marked by hand (D-054). |
| OD-17 | Difficulty levels in the question distribution | **Resolved (owner):** easy, medium and hard, every exam subject or part asks all three (D-054). |

---

## Ideas from reviewing the analysis doc (to raise at the relevant step)

| Step            | Idea                                                                                                                                  | Why                                                                                                     |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Audit           | Reject UPDATE and DELETE on `audit_events` and `answer_revisions` with database triggers (done for `application_status_events`) | The analysis doc says history cannot be erased from the UI; triggers make that true for every code path |
| Attempts        | Partial unique index: one in-progress attempt per booking                                                                             | Analysis doc §17 requires one active attempt; the database can guarantee it                            |
| Import          | ~~Store the national ID encrypted, plus an HMAC "blind index"~~ Not adopted: the national number is stored as given (D-032)          | Duplicates are found by the unique national number                                                      |
| Scoring         | Keep the pass policy as versioned data and store the policy id with every result                                                      | The analysis doc already requires versioning; this makes recomputation reproducible                     |
| Scoring         | Pull the scoring engine forward (pure PHP, rules already final)                                                                       | Early, fully testable win                                                                               |
| Candidate login | Optional restriction to the exam venue's network                                                                                      | Exams are taken on site                                                                                 |

---

## Template

### D-0xx Title

- **Status:**
- **Context:**
- **Decision:**
- **Why:**
- **Rejected:**
- **Consequences:**
