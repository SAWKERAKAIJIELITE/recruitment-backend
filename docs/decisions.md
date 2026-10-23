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
- **Status:** Accepted (analysis doc §10)
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
- **Status:** Accepted conservatively; policy open (OD-7). Amended by D-032: the national number is now required, so every person is recognised by it and "people without a national ID are always new records" no longer applies. The rule is per job position (the table formerly called job titles).
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
- **Status:** Accepted (owner's redesign after ERD diagram 07; supersedes part of D-024)
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
- **Status:** Accepted (owner's idea from the Step 3 review, refined; supersedes D-027)
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

## Resolved and deferred questions

| ID | Question | Outcome |
|----|----------|---------|
| OD-1 | One user per device, or shared devices? | **Resolved:** one device belongs to one staff user, and each staff user has one (approved) device (D-022). |
| OD-2 | Registration spam on the unauthenticated endpoint | **Resolved:** throttle by IP and a cap on pending devices (built). Admin-issued enrolment codes remain a fallback if abuse appears. |
| OD-3 | Emergency web portal vs device binding (analysis doc §11) | **Deferred:** the owner wants a web fallback *after the main work*. Until then there is none. A browser holds no installed key, so it would have to register as its own, weaker kind of device (for example a non-extractable WebCrypto key). |
| OD-4 | How are candidate codes delivered? | **Resolved:** printed and handed out at the exam venue (D-016). |
| OD-5 | Token lifetime | **Resolved for staff:** one day (D-036; was one week). Candidates: to be decided with candidate login (must outlive the longest exam). |
| OD-6 | May a user own several devices? | **Resolved:** no. One approved device per user. |
| OD-7 | Repeat applications: may a person apply again for a job title they already applied for, and when? | **Open:** currently refused while the earlier application is not final (D-028). Revisit when recruitment cycles are defined. |
| OD-8 | Format rule for the national ID (length, check digits) | **Resolved:** exactly 11 digits, stored as text (D-032). No check digit is validated. |
| OD-9 | Operational: who keeps `APP_KEY` (and the optional blind-index key) safe? | **No longer applies to recruitment data:** nothing in it is encrypted since D-032. `APP_KEY` still signs and encrypts framework data; keep it backed up. |
| OD-10 | Editing a candidate's file, and Excel import | **Planned:** both come in the next step. Editing must respect D-031 (an area's district) and D-033 (status changes only through the lifecycle). |

---

## Ideas from reviewing the analysis doc (to raise at the relevant step)

| Step | Idea | Why |
|------|------|-----|
| Audit | Reject UPDATE and DELETE on `audit_events` and `answer_revisions` with database triggers (done for `application_status_events`) | The analysis doc says history cannot be erased from the UI; triggers make that true for every code path |
| Attempts | Partial unique index: one in-progress attempt per booking | Analysis doc §17 requires one active attempt; the database can guarantee it |
| Import | ~~Store the national ID encrypted, plus an HMAC "blind index"~~ Not adopted: the national number is stored as given (D-032) | Duplicates are found by the unique national number |
| Scoring | Keep the pass policy as versioned data and store the policy id with every result | The analysis doc already requires versioning; this makes recomputation reproducible |
| Scoring | Pull the scoring engine forward (pure PHP, rules already final) | Early, fully testable win |
| Candidate login | Optional restriction to the exam venue's network | Exams are taken on site |

---

## Template

### D-0xx Title
- **Status:**
- **Context:**
- **Decision:**
- **Why:**
- **Rejected:**
- **Consequences:**
