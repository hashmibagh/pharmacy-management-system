# Installation Guide

General installation for **XAMPP / WAMP / LAMP / shared PHP hosting** and any Apache or Nginx + PHP-FPM server. For running the whole system on an Android phone, see [KSWEB_INSTALLATION.md](KSWEB_INSTALLATION.md) instead. For production hardening, see [DEPLOYMENT.md](DEPLOYMENT.md).

> There is **no Firebase, no Supabase, and no external backend service** in this project. Everything runs on your own PHP + MySQL server.

---

## 1. Requirements

| Requirement | Version / note |
|---|---|
| PHP | **8.1+** (8.2/8.3 fine) with extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo` (`gd` optional, for image thumbnails) |
| MySQL | **8+** or MariaDB 10.6+ |
| Composer | 2.x (for `firebase/php-jwt`; optional `dompdf/dompdf`, `phpoffice/phpspreadsheet`) |
| Node.js | **18+** with npm (only needed to build the frontend once) |
| Web server | Apache (with `mod_rewrite`) **or** Nginx + PHP-FPM |

Check PHP quickly:

```bash
php -v
php -m | grep -Ei 'pdo_mysql|mbstring|openssl|fileinfo'
```

---

## 2. Get the code

```bash
git clone <repo-url> pharmacy-management
cd pharmacy-management
```

---

## 3. Create the database and import schema + seed

Create an empty database (via phpMyAdmin, Adminer, or the MySQL shell), e.g. named `pharmacy`:

```sql
CREATE DATABASE pharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then import **schema first, seed second**:

```bash
mysql -u root -p pharmacy < database/schema.sql
mysql -u root -p pharmacy < database/seed.sql
```

The seed creates roles, the permission catalog, demo users, and sample data. Default login after seeding:

- Email: `admin@pharmacy.local`
- Password: `password123`

Change this password immediately after first login (Profile → Change password).

---

## 4. Configure the backend `.env`

Copy the example file to the backend directory (the front controller loads `backend/.env`):

```bash
cp .env.example backend/.env
```

Then edit `backend/.env` and set every variable (all of them are documented in [.env.example](../.env.example)):

| Variable | Example | Purpose |
|---|---|---|
| `APP_ENV` | `production` | `production` / `development` (controls error detail) |
| `APP_URL` | `http://localhost:8080` | Public base URL of the app |
| `DB_HOST` | `localhost` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `pharmacy` | Database name from step 3 |
| `DB_USER` | `root` | MySQL user |
| `DB_PASSWORD` | `` (empty) or secret | MySQL password — **never commit** |
| `JWT_SECRET` | long random string | Signing key for JWTs — **generate a fresh one**, e.g. `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` |
| `UPLOAD_PATH` | `storage/uploads` | Relative upload dir on the server |
| `JWT_ACCESS_TTL` | `900` | Access token lifetime in seconds (15 min) |
| `JWT_REFRESH_TTL` | `604800` | Refresh token lifetime in seconds (7 days) |
| `CORS_ORIGINS` | `*` | Allowed origins; in production list your frontend domain(s), e.g. `https://pharmacy.example.com` |
| `AUTH_MODE` | `jwt` | Auth driver (`jwt`) |
| `APP_TIMEZONE` | `Asia/Karachi` | PHP default timezone |

---

## 5. Install PHP dependencies

```bash
cd backend
composer install --no-dev --optimize-autoloader
cd ..
```

This installs `firebase/php-jwt` (JWT signing/verification only — not Firebase backend services). Optional PDF/Excel support:

```bash
cd backend
composer require dompdf/dompdf phpoffice/phpspreadsheet
```

---

## 6. Point the web server at the backend

All API requests go through the front controller **`backend/index.php`**. Two options:

**Option A — document root at project root (Apache):**
Point the vhost/document root at the project root and make sure the included `.htaccess` rewrite sends `/api/*` to `backend/index.php`. (Apache vhost example in [DEPLOYMENT.md](DEPLOYMENT.md).)

**Option B — document root at `backend/` (simplest on shared hosting):**
Point the document root directly at `backend/` so `/api/...` resolves to `backend/index.php` via rewrite.

Quick dev check with PHP's built-in server (development only):

```bash
cd backend
php -S localhost:8000 index.php
# API base is then http://localhost:8000/api
```

Smoke test:

```bash
curl http://localhost:8000/api/health
# {"success":true,"message":"API is running","data":{"version":"1.0.0"}}
```

---

## 7. Build the frontend

```bash
cd frontend
npm install
```

Create `frontend/.env` (build-time) with the API base URL:

```bash
# frontend/.env
VITE_API_URL=http://localhost:8000/api
```

Use the URL where your PHP API is actually reachable (e.g. `http://localhost:8080/api` on XAMPP, or `https://api.example.com/api` in production). Then build:

```bash
npm run build
```

This produces `frontend/dist/`. Serve it with any static server, or copy the contents of `frontend/dist/` into your web directory (e.g. next to, or as, the document root for the UI). For local preview:

```bash
npm run preview
# or: npx serve frontend/dist
```

> Rebuild (`npm run build`) any time you change `VITE_API_URL` — Vite bakes it into the bundle at build time.

---

## 8. Set `storage/` permissions

Uploads (medicine images, prescriptions, employee photos, logos, attachments) are written to `storage/` on the server filesystem:

```bash
chmod -R 775 storage backend/uploads
chown -R www-data:www-data storage backend/uploads   # Debian/Ubuntu Apache/Nginx
```

The directories ship with `.gitkeep` files so they survive a fresh clone; the app creates dated subfolders inside them at runtime.

---

## 9. Schedule the notification generator (cron)

Low-stock and near-expiry notifications are generated by a backend CLI script. Run it periodically (e.g. every morning):

```cron
# crontab -e
30 8 * * * /usr/bin/php /path/to/pharmacy-management/backend/cli/generate-notifications.php >> /var/log/pharmacy-notify.log 2>&1
```

The script scans batches for `quantity <= reorder_level` and `expiry_date <= today + notify_days` (thresholds in Settings) and inserts rows into the `notifications` table, which the frontend polls. On hosts without cron, trigger the same script via a scheduled task / manual run.

> If `backend/cli/generate-notifications.php` is not present in your checkout yet, any PHP CLI script that boots `config/Config.php` + `config/Database.php` and performs the scan above will do — the notification contract is: insert into `notifications` (`user_id` or broadcast, `type`, `title`, `message`).

---

## 10. Log in

Open the frontend URL in a browser and sign in:

- Email: `admin@pharmacy.local` / Password: `password123`

Then: change the password, set the pharmacy profile + logo in **Settings**, and review roles/permissions.

---

## Troubleshooting

**`SQLSTATE[HY000] [2002] Connection refused` / access denied (PDO)**
- Verify `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD` in `backend/.env`.
- Confirm the database exists and the user has privileges: `GRANT ALL PRIVILEGES ON pharmacy.* TO 'user'@'localhost'; FLUSH PRIVILEGES;`
- On XAMPP the MySQL user is `root` with an empty password by default; on some Linux distros MySQL uses unix-socket auth — set `DB_HOST=127.0.0.1` to force TCP.

**API routes return 404 / rewrite not working (Apache)**
- Enable `mod_rewrite` (`a2enmod rewrite`) and set `AllowOverride All` for the document root so `.htaccess` is honored.
- Confirm requests reach the front controller: `curl -i <host>/api/health` should hit `backend/index.php`.

**CORS errors in the browser console**
- `VITE_API_URL` must exactly match the API origin (scheme + host + port), including `/api`.
- Set `CORS_ORIGINS` in `backend/.env` to your frontend origin(s) in production instead of `*`.

**File uploads fail / "permission denied" writing to `storage/`**
- Re-apply step 8 permissions; ensure the PHP process user (e.g. `www-data`) owns or can write to `storage/`.

**`JWT_SECRET` errors / "invalid signature" after changing secret**
- All previously issued tokens become invalid — users just log in again.
- Never commit `backend/.env`; rotate `JWT_SECRET` if it was ever exposed.

**Frontend shows blank page / API 404s**
- `frontend/dist/` may be stale: rebuild after changing `frontend/.env` (`npm run build`), then re-copy to the web dir.
- Check the browser devtools Network tab for the failing request URL and compare with `VITE_API_URL`.

**Composer: `firebase/php-jwt` fails to install**
- It requires PHP 8.1+ and the `openssl` extension. This package is only a JWT library — it does not contact Firebase servers.
