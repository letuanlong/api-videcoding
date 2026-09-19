# vibecoding-api — User Management CMS & RBAC

REST API (Laravel 12, PHP 8.2) with a Vue 3 admin CMS for managing users by role: **SUPERADMIN / ADMIN / USER**.
Authentication uses Bearer tokens (Laravel Sanctum). Besides user management there is a pre-existing orders module (`/api/orders`).

Contents: [Setup](#1-setup) · [Environment](#2-environment-variables) · [Database and seeders](#3-database-and-seeders) · [Authentication](#4-authentication) · [Authorization](#5-authorization) · [API](#6-api) · [Frontend](#7-frontend-cms) · [Testing](#8-testing) · [Architecture](#9-code-architecture)

---

## 1. Setup

Requirements: PHP ≥ 8.2, Composer, Node.js ≥ 20, MySQL/MariaDB (or SQLite).

```bash
composer install
cp .env.example .env            # Windows PowerShell: Copy-Item .env.example .env
php artisan key:generate
# edit DB_* in .env, then:
php artisan migrate
php artisan db:seed             # creates 3 sample accounts (see section 3)
php artisan storage:link        # makes avatars reachable through /storage

npm install
npm run build                   # builds the CMS into public/build
```

Run:

```bash
php artisan serve               # API: http://127.0.0.1:8000/api   CMS: http://127.0.0.1:8000/cms
npm run dev                     # (optional) Vite dev server, hot reload while editing the frontend
```

### Running with XAMPP (Apache) inside `htdocs`

1. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
2. Do the steps in section 1 once (`composer install`, `.env`, `migrate`, `db:seed`, `storage:link`, `npm install`, `npm run build`).
3. Open your browser:

| What | URL |
|---|---|
| CMS (UI) | `http://localhost/vibecoding-api/public/cms` |
| Shortcut (redirects to the address above) | `http://localhost/vibecoding-api/cms` |
| API | `http://localhost/vibecoding-api/public/api/...` |

Sign in with a sample account from section 3 (e.g. `superadmin@example.com` / `Password@123`).

- **Run `npm run build` after every frontend change**, otherwise Apache will not see it (Apache only serves the files in `public/build`). To rebuild automatically while you edit, also run `npm run dev` (Vite dev server).
- The frontend reads its base path from Laravel, so the same build works at `/vibecoding-api/public/cms` and at `/cms` without any configuration.
- **The `.htaccess` file at the project root is required when the project lives in `htdocs`.** Without it, Apache serves `http://localhost/vibecoding-api/.env` (which contains `APP_KEY` and the database configuration), `.git/`, `vendor/` and `storage/logs/` directly to anyone who can reach your machine (XAMPP listens on all network interfaces by default). Do not delete this file.
- **Better option (optional): a VirtualHost pointing straight at `public/`.** Only the `public/` directory is then exposed, and you get a clean URL such as `http://vibecoding.test/cms`. Add this to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

  ```apache
  <VirtualHost *:80>
      ServerName vibecoding.test
      DocumentRoot "C:/xampp/htdocs/vibecoding-api/public"
      <Directory "C:/xampp/htdocs/vibecoding-api/public">
          AllowOverride All
          Require all granted
      </Directory>
  </VirtualHost>
  ```

  Add the line `127.0.0.1 vibecoding.test` to `C:\Windows\System32\drivers\etc\hosts` (requires Administrator rights), then restart Apache.
- **Troubleshooting:** blank page or `/build/...` returning 404 → you have not run `npm run build`; API returning 404 → check that Apache has `mod_rewrite` enabled (it is by default in XAMPP); avatars not showing → you have not run `php artisan storage:link`.

## 2. Environment variables

| Variable | Meaning | Notes |
|---|---|---|
| `DB_*` | Database connection | Tests use SQLite `:memory:` (configured in `phpunit.xml`) |
| `SANCTUM_EXPIRATION` | Bearer token lifetime (minutes) | Default **480**. Expired tokens are rejected with 401 |
| `APP_DEBUG` | Show error details | **Must be `false` in production.** When `true`, a 500 response includes the exception message |
| `APP_URL` | Application base URL | Used by console commands. Avatar URLs are built from the request host, so they do not depend on this variable |

## 3. Database and seeders

Migrations (`database/migrations`):

- `users`: adds `phone`, `role` (default `user`), `status` (default `active`), `avatar`, `last_login_at` and `deleted_at` (soft delete). `email` is unique, **including for soft-deleted accounts**.
- `audit_logs`: `user_id`, `action`, `target_type`, `target_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`. Append-only.
- Migrations run on top of existing data (existing users automatically get `role=user`, `status=active`) and can be rolled back.

Seeder (`php artisan db:seed`; safe to run repeatedly: it never creates duplicates and never overwrites a password that was changed):

| Email | Role | Password (development only) |
|---|---|---|
| `superadmin@example.com` | superadmin | `Password@123` |
| `admin@example.com` | admin | `Password@123` |
| `user@example.com` | user | `Password@123` |

## 4. Authentication

- `POST /api/login` returns a `token`. Send it with every later request: `Authorization: Bearer <token>`.
- Each login revokes the previous token (one device at a time). Login is limited to 5 attempts per minute.
- **A token is revoked immediately** when the user logs out, is switched to `inactive`/`blocked`, is deleted, has their password reset by an admin, or changes their own password.
- An account that is not `active` is rejected with 403 on every API (even if it still holds a token). Only `logout` keeps working.
- A wrong email and a wrong password return the same 401 error (the API does not reveal whether an email exists). The account status is disclosed only **after** the password has been verified.

### Response format

```jsonc
// Success
{ "success": true, "message": "Login successful.", "data": { ... } }
// List
{ "success": true, "data": [ ... ], "meta": { "current_page": 1, "per_page": 20, "total": 100, "last_page": 5 } }
// Error
{ "success": false, "message": "Validation failed.", "errors": { "email": ["The email has already been taken."] } }
```

| Code | Meaning | `message` |
|---|---|---|
| 401 | Not logged in / invalid or expired token / wrong credentials | `Unauthenticated.` / `Invalid credentials.` |
| 403 | Not allowed, or account is inactive/blocked | `You do not have permission to perform this action.` |
| 404 | Does not exist or was soft-deleted | `User not found.` / `Resource not found.` |
| 422 | Invalid input (includes `errors`) | `Validation failed.` |
| 429 | Too many attempts | `Too Many Attempts.` (with a `Retry-After` header) |
| 500 | Server error; details are hidden when `APP_DEBUG=false` | `Server error.` |

## 5. Authorization

The backend decides access (`UserPolicy` + the `role`/`active` middleware). The frontend only hides or shows things for convenience.

| Capability | SUPERADMIN | ADMIN | USER |
|---|:-:|:-:|:-:|
| Log in, log out, view dashboard | ✓ | ✓ | ✓ |
| View/edit own profile, change own password | ✓ | ✓ | ✓ |
| View the user list | ✓ | ✓ | ✗ |
| Create ADMIN | ✓ | ✗ | ✗ |
| Create USER | ✓ | ✓ | ✗ |
| View / edit / delete / change status / reset password of an **ADMIN** | ✓ | ✗ | ✗ |
| View / edit / delete / change status / reset password of a **USER** | ✓ | ✓ | ✗ |
| View another SUPERADMIN (read-only) | ✓ | ✗ | ✗ |
| View audit logs | ✓ | ✗ | ✗ |
| Delete / block / change the role of oneself through the admin API | ✗ | ✗ | ✗ |
| Create or promote anyone to SUPERADMIN | ✗ | ✗ | ✗ |

Notable details:

- SUPERADMIN has **no** privilege beyond the policy: it cannot edit, delete or block another SUPERADMIN, or itself. This makes it impossible to end up with no top-level administrator. SUPERADMIN accounts are created only by the seeder or a command.
- The default SUPERADMIN list contains ADMIN + USER accounts. Filter with `role=superadmin` to view (read-only) the SUPERADMIN accounts. An ADMIN filtering by `role=admin|superadmin` receives an empty list.
- Role/status checks run **before** route-model-binding, so someone without permission cannot probe for existing ids (always 403, never distinguishable from 404).
- Accessing a record outside your permission returns **403** (not 404). `role` is not in `$fillable`; it is only assigned explicitly after passing the policy (mass-assignment protection).
- Passwords: 8–72 characters with upper and lower case letters, a number and a symbol (applies when creating, resetting and changing a password).

## 6. API

Prefix `/api`. Legend: 🔓 public · 🔑 token required · 👑 SUPERADMIN only · 🛡 SUPERADMIN + ADMIN.

| Method | Endpoint | Access | Description |
|---|---|---|---|
| POST | `/login` | 🔓 | Log in, returns `user` + `token` |
| POST | `/logout` | 🔑 | Revoke the current token |
| GET | `/dashboard` | 🔑 | Role-based statistics (`stats` only for SUPERADMIN/ADMIN) |
| GET | `/profile` | 🔑 | Own profile |
| PUT | `/profile` | 🔑 | Update `name`, `phone`, `avatar` (see below) |
| POST | `/profile/change-password` | 🔑 | Change password, revokes all tokens |
| GET | `/admin/users` | 🛡 | List: `search`, `role`, `status`, `page`, `per_page` |
| POST | `/admin/users` | 🛡 | Create a user |
| GET | `/admin/users/{id}` | 🛡 | Details |
| PUT | `/admin/users/{id}` | 🛡 | Update `name`, `email`, `phone`, `role`, `status` |
| PATCH | `/admin/users/{id}/status` | 🛡 | Change status |
| DELETE | `/admin/users/{id}` | 🛡 | Soft delete |
| POST | `/admin/users/{id}/reset-password` | 🛡 | Reset the password, revokes that user's tokens |
| GET | `/admin/audit-logs` | 👑 | `user`, `action`, `date_from`, `date_to`, `page`, `per_page` |
| GET/POST | `/orders` | 🔑 | Place an order / list own orders (pre-existing module) |

### Examples

```http
POST /api/login
{ "email": "user@example.com", "password": "Password@123" }
→ 200 { "success": true, "message": "Login successful.",
        "data": { "user": { "id": 1, "name": "John", "email": "user@example.com", "role": "user" }, "token": "1|abc..." } }
```

```http
POST /api/admin/users          (Authorization: Bearer <ADMIN/SUPERADMIN token>)
{ "name": "Nguyen Van A", "email": "a@example.com", "phone": "0900000000",
  "password": "Password@123", "password_confirmation": "Password@123",
  "role": "user", "status": "active" }
→ 201 { "success": true, "message": "User created successfully.", "data": { "id": 12, "role": "user", ... } }
```

Each user in `data` carries `abilities` (`update`, `delete`, `change_status`, `reset_password`) describing what the current viewer may do with that record, so the frontend can show or hide buttons without duplicating the permission matrix.

**Pagination:** `per_page` accepts only `10`, `20` (default), `50` and `100`. Any other value falls back to 20 without an error.
**Search:** by `name`, `email` and `phone` at the database level (`LIKE`, case-insensitive), combinable with `role`, `status` and pagination.

**Avatar:** send `multipart/form-data` with a JPG/PNG/WebP image up to 2 MB. It is validated by **file content** (the name/extension is not trusted; SVG is rejected). PHP does not parse multipart bodies for `PUT`, so send `POST /api/profile` with the field `_method=PUT`. Send JSON `{"avatar": null}` to remove the avatar.

**Ignored fields:** `PUT /api/profile` accepts only `name`, `phone` and `avatar`; `email`, `role`, `status` and anything else you send is ignored.

### Audit log

Records `LOGIN`, `LOGOUT`, `CREATE_USER`, `UPDATE_USER`, `DELETE_USER`, `CHANGE_STATUS`, `RESET_PASSWORD`, `UPDATE_PROFILE` and `CHANGE_PASSWORD`, together with the actor, IP address, user agent and old/new values (changed fields only). It is written in the same transaction as the business operation. **Passwords and tokens are never stored** (every key containing `password`, `token` or `secret` is dropped), and passwords are also hidden from stack traces in the application log (`#[\SensitiveParameter]`).

## 7. Frontend (CMS)

Vue 3 + Vue Router + Pinia + Tailwind 4, located in `resources/js/cms` and served at **`/cms`** (every sub-path returns the same SPA page). It works both at the domain root (`/cms`) and inside an XAMPP sub-folder (`/vibecoding-api/public/cms`): Laravel writes the real base paths into the `data-cms-base` / `data-api-base` attributes of the `#app` element (the CSP blocks inline scripts, so they are not passed as JavaScript variables), the build uses relative asset URLs, and only internal paths are accepted, so data in the HTML cannot point the API at another origin.

- Pages: Login, Dashboard, User Management (list / create / detail / edit plus delete, change-status and reset-password modals), Audit Logs, Profile.
- Role-based menu: SUPERADMIN (Dashboard, User Management, Audit Logs, Profile), ADMIN (no Audit Logs), USER (Dashboard, Profile). Opening a forbidden URL directly shows a 403 page.
- The list filters, page and page size live in the URL (reload and the Back button keep them).
- **Token storage:** in `sessionStorage` (survives a reload, is lost when the tab closes, and `localStorage` is never used). Passwords are never stored. A 401 from any API (expired/revoked token) clears the session and returns to the login page, remembering the page being viewed.
- **Page security:** CSP (`script-src 'self'`, `frame-ancestors 'none'`, ...), `X-Frame-Options`, `X-Content-Type-Options`; user-entered content is always rendered as text (no `v-html`); only internal `redirect` targets are accepted after login (open-redirect protection). The CSP is switched off automatically while `npm run dev` is running.
- The token lives in `sessionStorage`, so XSS is the main risk: keep the CSP, do not add third-party scripts, and never use `v-html` with user data.

## 8. Testing

```bash
php artisan test        # backend (Pest): 413 tests
npm test                # frontend (Vitest + Vue Test Utils): 372 tests
npm run test:e2e        # real E2E: Laravel + the build + a real Chrome/Edge, temporary SQLite DB (34 steps)
```

- **Backend:** authentication, the policy (a 3 roles × 3 roles × permissions matrix), CRUD, IDOR, mass assignment, passwords never leaking (responses, audit logs, application logs), pagination/search, and content-based upload validation. Tests use SQLite `:memory:`, so they **cannot exercise `lockForUpdate` or some MySQL collation behaviour**. To run against real MySQL, create a temporary database and run `DB_CONNECTION=mysql DB_DATABASE=<temp_db> php artisan test` (environment variables override `phpunit.xml`), then drop the database. **Never run the tests against your development database.**
- **Frontend:** utilities, stores, router guards, every component and page, plus role-based QA scenarios that run on the whole application (only the network layer is mocked).
- **E2E:** requires PHP in your PATH and an installed Chrome or Edge (no browser is downloaded). The script builds the frontend, creates a temporary database, seeds it, starts a server and cleans up afterwards. Screenshots of failing steps are written to `e2e/artifacts`. Optional variables: `E2E_PORT`, `E2E_HEADED=1`. The E2E suite runs on PHP's built-in server at the **domain root**; running inside an XAMPP sub-folder (Apache, `.htaccess`) was verified manually with Chrome but **has no automated test yet**.

## 9. Code architecture

Every endpoint is a thin layer following this pattern:

```
routes/api.php → Controller (invokable) → FormRequest (validate + authorize)
               → Action::execute() (all business logic, inside DB::transaction for writes)
               → Resource (JSON formatting)   |   domain Exception that renders its own response
```

| Directory | Role |
|---|---|
| `app/Actions/{Auth,Users,Profile,AuditLogs,Dashboard,Orders}` | Business logic |
| `app/DTOs` | Input data as `readonly class` |
| `app/Enums` | `UserRole` (single source of truth for the permission matrix), `UserStatus`, `AuditAction` |
| `app/Policies/UserPolicy.php` | Decides who may do what to another user |
| `app/Http/Middleware` | `EnsureUserHasRole` (`role:`), `EnsureUserIsActive` (`active`), `CmsSecurityHeaders` |
| `app/Support` | `ApiResponse` (standard format), `PerPage` |
| `bootstrap/app.php` | Middleware aliases, middleware priority, standardised handling of every API error |
| `resources/js/cms` | CMS frontend (api, stores, router, components, pages, utils, test) |
| `e2e/run.mjs` | Real-browser E2E suite |
| `.htaccess` (project root) | XAMPP/Apache only, when the project lives in `htdocs`: stops Apache from serving `.env`, `.git`, `vendor`... directly (see section 1) |

Conventions: API messages of the User Management module are in English, as specified; code comments are written in Vietnamese.
