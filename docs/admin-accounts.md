# Adding administrator accounts

A system administrator (`system_admin`, «مسؤول النظام») holds every permission: users, roles, devices and all the
offices' sections. There are two ways to make one:

| Way                                         | When                                                                               |
| ------------------------------------------- | ---------------------------------------------------------------------------------- |
| [From the server's shell](#1-from-the-servers-shell) | The **first** administrator of a new installation, or when every administrator is locked out. |
| [From the application](#2-from-the-application)       | Every administrator after the first, by an administrator who is logged in.         |

An account alone cannot log in: staff log in only **from an approved device** (a key pair the EXE makes on the PC).
So every new administrator is two steps: the account, then their PC.

**Where to run the commands.** They are the same everywhere; only the prefix changes:

| Installation                                             | Prefix                                                              |
| -------------------------------------------------------- | ------------------------------------------------------------------- |
| Docker ([docker-deployment.md](docker-deployment.md))    | `cd /srv/recruitment`, then `docker compose exec app php artisan …` |
| Without Docker ([deployment.md](deployment.md))          | `sudo -iu recruitment`, `cd /var/www/recruitment`, then `php artisan …` |

The examples below use the Docker form, on `https://rec.1005.date`.

## 1. From the server's shell

### 1.1 Create the account

```bash
cd /srv/recruitment
docker compose exec app php artisan staff:create-admin it.admin \
    --first-name="مسؤول" --last-name="النظام" --personal-number=100001
# Password (8 or more: upper and lower case letters, a digit, a symbol): ********
# The password again: ********
# Administrator it.admin created (0195…). Register a device from the frontend, then approve it with: …
```

| Value               | Rule                                                                                             |
| ------------------- | ------------------------------------------------------------------------------------------------ |
| the username        | 3 to 50 characters: Latin lower-case letters, dots, dashes, underscores (no digits). Unique.     |
| `--first-name`, `--last-name` | The person's name. Asked for when left out.                                            |
| `--personal-number` | 4 to 50 digits. Unique. Asked for when left out.                                                 |
| the password        | 8 characters or more, with upper and lower case letters, a digit and a symbol. Asked twice, never written on the command line (so it is not in the shell's history). |

What the command does and refuses:

- It refuses to run before the catalogue is seeded («The roles are not seeded»): under Docker the first start seeds
  it; otherwise `php artisan db:seed --force`.
- When an active administrator exists already it asks «Create another one?»: answer `yes` to add a second one
  this way.
- It is recorded in the audit log (`access.user_created`, `via: console`).
- It needs a terminal for the password. Run it from an SSH session, not from a script (no `-T`).

### 1.2 Register the administrator's PC

On that PC, open the EXE, point it at `https://rec.1005.date/api/v1` and register. It shows a **device id** (a
UUID) and a **fingerprint** (8 groups of 4 characters). Without the EXE, the test client does the same from any PC
with Node 18+:

```bash
API_URL=https://rec.1005.date/api/v1 node tools/device-client.mjs register "IT office PC"
```

### 1.3 Approve the PC

On the server, with the fingerprint **read from the PC's screen** (that comparison is what makes the approval
safe; never copy it from the database):

```bash
docker compose exec app php artisan staff:approve-device <device-id> it.admin \
    --fingerprint="31E2 565D 78CD 9B06 CA51 B19F E7B2 80B0"
# Device … approved for: it.admin
```

### 1.4 Log in

On that PC, with the username and the password. The token lasts until the next midnight in Damascus
(`REPORTS_TIMEZONE`); logging in again every morning is expected.

## 2. From the application

An administrator who is logged in adds the others from the application's users screen. What the screen does, as
API calls (all under `https://rec.1005.date/api/v1`, with the administrator's token; permission `users.manage`):

1. **Add the user** with the role `system_admin`:

   ```
   POST /staff/new
   {
     "username": "second.admin",
     "first_name": "…", "last_name": "…",
     "personal_number": "100002",
     "password": "…", "password_confirmation": "…",
     "is_active": true,
     "role": "system_admin"
   }
   ```

   The rules are those of the table in 1.1. Give the new administrator the password in person; they are personal.

2. **The new administrator registers their own PC** from the EXE (as in 1.2) and reads the fingerprint out to the
   administrator.

3. **Approve the device** in the application (devices screen; permission `devices.approve`), comparing the
   fingerprint:

   ```
   GET  /staff/devices                       the registered devices
   POST /staff/devices/{device}/approve      { "fingerprint": "31E2 565D …", "staff_user_id": "<the new user's id>" }
   ```

To make an **existing** staff user an administrator instead, change their role: `PUT /staff/users/{user}/role`
with `"role": "system_admin"`. The other roles are `recruitment`, `exams_interviews`, `exams`, `interviews`,
`contracts`, `documenting` and `general_manager`; `GET /staff/roles` lists them with their permissions.

## 3. Looking after administrator accounts

| To                                  | Do                                                                                                    |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------- |
| List the users                      | `GET /staff/users` (the users screen).                                                              |
| Reset a forgotten password          | `PUT /staff/users/{user}/password`, by another administrator.                                       |
| Remove an administrator who leaves  | `POST /staff/users/{user}/deactivate`: their tokens stop working at once. `…/activate` undoes it. |
| Retire a PC that is replaced        | `POST /staff/devices/{device}/revoke`, then register and approve the new PC.                        |
| See who did what                    | The audit log in the application (`access.user_created`, role changes, device approvals).           |

Keep **at least two** administrators, each with their own account and PC, so that one can reset the other's
password. Do not share an account: the audit log names the account.

## 4. Locked out

When no administrator can log in (passwords forgotten, the only approved PC lost), the shell is the way back in:

```bash
cd /srv/recruitment
docker compose exec app php artisan staff:create-admin recovery.admin       # answer «yes» to «Create another one?»
# register a PC (1.2), then:
docker compose exec app php artisan staff:approve-device <device-id> recovery.admin --fingerprint="…"
```

Log in as the new administrator, reset the others' passwords or revoke and approve their devices from the
application, then deactivate `recovery.admin` if it is no longer needed.

## 5. When it does not work

| What you see                                                    | Why                                                                                                                                              |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| «The roles are not seeded»                                     | The catalogue is missing: `docker compose exec app php artisan db:seed --force`.                                                               |
| A validation message in Arabic after the password              | A rule of the table in 1.1 (most often the password's strength, or a username or personal number already used). Run the command again.          |
| «The device argument must be a device id (UUID)», «No such device» | The id is not the one the PC showed, or the PC registered against another address than `https://rec.1005.date/api/v1`.                    |
| The login always says «اسم المستخدم أو كلمة المرور غير صحيحة»   | One message for every failure, on purpose: a wrong password, but also a device not approved (or approved for another user), a revoked device, an inactive user. The real reason: `docker compose exec app sh -c 'grep staff_login_failed storage/logs/laravel-*.log \| tail'`. |
| `the input device is not a TTY`                               | The command was run without a terminal; run it in an interactive SSH session.                                                                    |
