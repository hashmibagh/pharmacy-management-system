# Pharmacy Management System — Backend (PHP 8.1+ REST API)

Pure PHP + MySQL/MariaDB via PDO. **No Supabase, no Firebase backend services.**
The only Firebase package used is `firebase/php-jwt` — a JWT *signing library*,
not a backend service.

Runs on: KSWEB (Android), XAMPP, WAMP, LAMP, shared PHP hosting, Apache,
Nginx + PHP-FPM. No Apache-only features in core code.

## Requirements

- PHP >= 8.1 with extensions: `pdo_mysql`, `json`, `mbstring`, `openssl`, `fileinfo`
  (`gd` optional — enables extra image validation; `curl` optional — needed for Web Push)
- MySQL >= 5.7 / MariaDB >= 10.3
- Composer

## Setup

```bash
cd backend
composer install
cp .env.example .env
# edit .env: DB_*, JWT_SECRET (min 32 chars), APP_URL, CORS_ORIGINS ...

# 1. Import the full domain schema (project root, maintained by DB agent):
mysql -u root -p pharmacy_db < ../database/schema.sql
# 2. Import backend auth/RBAC support tables:
mysql -u root -p pharmacy_db < database/migrations.sql
```

Point your web server's document root at `backend/` (or keep it in a
subfolder — the front controller detects its own base path).

### Apache

The bundled `backend/.htaccess` rewrites `/api/*` → `index.php`. Requires
`mod_rewrite` + `AllowOverride All` for the backend directory.

### Nginx (no .htaccess support)

```nginx
server {
    listen 80;
    server_name pharmacy.local;
    root /var/www/pharmacy-management/backend;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    # Optional: tighter rule that only rewrites API calls
    # location /api/ { try_files $uri /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Never serve these directly
    location ~ /(\.env|composer\.(json|lock)|\.htaccess)$ { deny all; }
    location ~ ^/(config|database|logs)/ { deny all; }
}
```

If rewrites are impossible on a host, the API also accepts
`/index.php?_route=/api/medicines` as a fallback.

### KSWEB (Android)

1. Copy the project to KSWEB's `htdocs/` (e.g. `htdocs/pharmacy/backend`).
2. In KSWEB start Lighttpd/Apache + PHP + MySQL.
3. Run `composer install` — easiest via KSWEB's built-in terminal, or copy a
   `vendor/` prepared on a PC (same PHP major version).
4. Create the DB with KSWEB's phpMyAdmin and import the two SQL files above.
5. Browse to `http://127.0.0.1:8080/pharmacy/backend/api/health`.

## API conventions

- Base path: `/api/*`. Health check: `GET /api/health`.
- Success: `{"success":true,"message":"...","data":{...}}`
- Error: `{"success":false,"message":"...","errors":{...}}`
- Validation failures → `422` with field errors in `errors`.
- Lists are paginated: `?page=1&per_page=20` → `data[]` + `meta{current_page,per_page,total,last_page}`.
- Dates: `YYYY-MM-DD`; datetimes: `YYYY-MM-DD HH:MM:SS`.
- Raw DB errors are never exposed — they are logged to `backend/logs/app.log`.

## Auth

- `POST /api/auth/login` → `{access_token (15 min), refresh_token (7 days)}`.
  Send `Authorization: Bearer <access_token>`. Refresh token is also set as an
  `HttpOnly` cookie (`/api/auth` path); `POST /api/auth/refresh` rotates it
  (old token revoked, reuse = invalid).
- Login is rate-limited: 5 attempts / minute / IP (file-based, `logs/ratelimit/`).
- Logout / password change revoke refresh tokens (logout revokes the current
  one; password change/reset revokes **all**).
- `AUTH_MODE=jwt|session|both` in `.env`. Session mode uses secure PHP
  sessions + CSRF token (`X-CSRF-TOKEN` header, returned by login/me).
- RBAC: `PermissionMiddleware` checks strings like `medicines.create` against
  `role_permissions` + `user_permissions` (per-user overrides win). The
  Super Admin role (`roles.is_super_admin = 1`) bypasses all checks.

## File uploads

- Stored under the upload root (`UPLOAD_PATH` in `.env`, default
  `backend/uploads/`), in subfolders `medicines/`, `prescriptions/`,
  `employees/`, `users/`, `logos/`, `attachments/`.
- Filenames are randomized (`prescription_8f31a2c9e4b7a1d0.pdf`); the original
  client filename is never used. Extension + MIME (finfo) + size validated.
- `backend/uploads/.htaccess` disables PHP execution in the upload dir.
- Files are served **only** through the authenticated endpoint
  `GET /api/files/{type}/{name}` (`FileController`) with path-traversal guards.
- For production, prefer moving the upload root **outside** the web root via
  `UPLOAD_PATH=/var/www/storage` and add this `storage/.htaccess` if it must
  stay web-accessible:
  ```apache
  php_flag engine off
  RemoveHandler .php .phtml .phar
  <FilesMatch "\.(php|phtml|phar)$">Require all denied</FilesMatch>
  Options -Indexes
  ```

## Optional export libraries

```bash
composer require dompdf/dompdf phpoffice/phpspreadsheet
```

Without them, `/api/export/*` and `/api/medicines/import|export` return a
clear `501` JSON error telling you exactly what to install. The thin wrappers
live in `helpers/Pdf.php` / `helpers/Excel.php`.

## Web Push (no Firebase)

`services/WebPushService.php` is a pure-PHP VAPID push sender (openssl + curl
only). Set `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` / `VAPID_SUBJECT` in `.env`,
store browser `PushSubscription` JSON per user, and call
`WebPushService::send($subscription, ['title'=>..., 'body'=>...])`.

## Scheduled checks (optional)

Low-stock / expiry notifications can be generated on demand inside sale &
inventory flows, or via cron hitting a small CLI (not included — wire
`NotificationService::checkLowStock()` / `checkExpiry()` into your scheduler).

## Layout

```
backend/
  index.php            front controller (routing, CORS, middleware chain)
  api/routes.php       METHOD+path → controller@method (+ auth/permissions)
  config/              Config (.env loader), Database (PDO singleton)
  helpers/             Response, Validator, Auth (JWT), FileUpload, Pdf, Excel, Logger
  middleware/          AuthMiddleware, PermissionMiddleware, RateLimit, Csrf
  models/              thin PDO models (BaseModel + 20 entities)
  controllers/         21 controllers (see routes.php)
  services/            StockService, ReportService, NotificationService, WebPushService
  database/            migrations.sql (refresh_tokens, password_resets, role_permissions, user_permissions)
  uploads/             runtime upload dir (git-kept, PHP execution disabled)
  logs/                app.log + rate-limit counters (not in git)
```
