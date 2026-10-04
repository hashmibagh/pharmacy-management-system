# KSWEB Installation Guide (Android)

Run the entire Pharmacy Management System on your Android phone using **KSWEB** (a web-server suite for Android with Apache/Lighttpd + PHP + MySQL). No Firebase, no Supabase — just your phone acting as the server.

**Recommended approach:** build the React frontend on a PC (fast), then copy the `dist/` folder to the phone. Building on the phone via Termux is possible and covered in step 9 as an alternative.

---

## Step 1 — Install KSWEB

1. Install **KSWEB** from the Google Play Store (paid app; the free trial works for setup and testing).
2. Open KSWEB and grant it the storage/file permissions it asks for — it needs them to serve files from its web directory.
3. In KSWEB's settings, note the web root path. The default is **`/htdocs`** inside KSWEB's private data area (shown on the KSWEB main screen as the "htdocs" folder, e.g. `/data/data/ru.ksweb/files/htdocs` or a user-accessible path depending on version). You can also change it to a folder on shared storage so file managers can see it.

## Step 2 — Start Apache

1. On the KSWEB main screen, tap the **server toggle** (or the "Start" button) to start the web server.
2. Make sure the selected server is **Apache** (KSWEB → Settings → select Apache rather than Lighttpd/Nginx, so the `.htaccess` rewrite to `backend/index.php` works).
3. Confirm PHP is enabled — KSWEB ships PHP 8.x; in KSWEB settings pick **PHP 8.1 or newer** if multiple versions are listed.
4. Test: open `http://localhost:8080` in Chrome on the phone. You should see KSWEB's default page (or your `htdocs` contents).

> **Port note:** KSWEB's default HTTP port is **8080**, so the server URL is `http://localhost:8080` on the phone itself. If port 8080 is busy, change it in KSWEB settings (e.g. to 8081) and use that port everywhere below.

## Step 3 — Start MySQL

1. In KSWEB, go to the **Tools / Services** section and start **MySQL** (tap its toggle).
2. Note the credentials shown by KSWEB — typically host `localhost`, port `3306`, user **`root`** with an **empty password** by default.
3. Verify it's running: open KSWEB's built-in **phpMyAdmin** (or Adminer) link, usually at `http://localhost:8080/phpmyadmin`, and log in as `root` with the empty password.

## Step 4 — Create database

1. In phpMyAdmin, click **Databases** → enter `pharmacy` as the name → choose collation `utf8mb4_unicode_ci` → **Create**.
2. (MySQL shell alternative, via KSWEB's terminal or an SSH app:)
   ```sql
   CREATE DATABASE pharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

## Step 5 — Import schema.sql

1. Get `database/schema.sql` onto the phone (copy it from your PC via USB/Bluetooth/Google Drive, or download it in the phone's browser).
2. In phpMyAdmin: select the `pharmacy` database → **Import** tab → **Choose file** → pick `schema.sql` → **Go**.
3. Wait for "Import has been successfully finished." You should now see the tables (users, medicines, batches, sales, etc.) in the left panel.

## Step 6 — Import seed.sql

1. Same as step 5, but import `database/seed.sql` into the `pharmacy` database.
2. This creates the demo roles, permission catalog, and demo logins. Default admin login:
   - Email: `admin@pharmacy.local` — Password: `password123`
3. Verify: in phpMyAdmin, browse the `users` table — you should see the demo accounts.

## Step 7 — Copy backend

1. Copy the entire **`backend/`** folder from the project into KSWEB's web root, e.g. `/htdocs/pharmacy/backend/` (create the `pharmacy` folder first).
2. How to copy: download the repo ZIP on the phone (the repo is public: open it in Chrome → **Code → Download ZIP**), extract with a file-manager app, then move `backend/` into place. USB from a PC works too.
3. **No Composer needed.** The JWT library (`firebase/php-jwt`, MIT-licensed) is bundled under `backend/lib/` and loads automatically. Running `composer install` on a PC is still supported and takes precedence if `backend/vendor/` exists, but you can skip it entirely for KSWEB.

## Step 8 — Configure .env

The API front controller (`backend/index.php`) loads its config from **`backend/.env`** — this file does not exist yet; you create it.

1. On the phone, open a text editor that can handle plain text (e.g. **QuickEdit**, **Acode**, or **MT Manager**).
2. Copy the contents of the project's `.env.example` (view it on the PC, or open the raw file on the phone) into a new file named exactly **`.env`** inside `/htdocs/pharmacy/backend/`.
   - Note: Android file managers sometimes hide dotfiles — use an editor that can save dotfiles, or create it on the PC as `backend/.env` *before* copying (recommended).
3. Edit the values for KSWEB:

   ```ini
   APP_ENV=production
   APP_URL=http://localhost:8080
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=pharmacy
   DB_USER=root
   DB_PASSWORD=
   JWT_SECRET=<paste a long random string — generate on PC: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;">
   UPLOAD_PATH=storage/uploads
   JWT_ACCESS_TTL=900
   JWT_REFRESH_TTL=604800
   CORS_ORIGINS=*
   AUTH_MODE=jwt
   APP_TIMEZONE=Asia/Karachi
   ```
4. Save. If you created `.env` on the PC before copying, double-check the phone path matches (`/htdocs/pharmacy/backend/.env`).
5. Quick API test in Chrome: `http://localhost:8080/pharmacy/backend/api/health` should return `{"success":true,...}`. (Adjust the path if you placed `backend/` elsewhere under `/htdocs`.)

## Step 9 — Build React frontend

**Option A — build on a PC (recommended, faster):**

```bash
cd frontend
npm install
npm run build
```

This creates `frontend/dist/`. Then copy the **contents of `dist/`** to the phone (USB/Drive) for step 10.

**Option B — build on the phone with Termux:**

1. Install **Termux** from F-Droid/GitHub (not the outdated Play Store build).
2. In Termux:
   ```bash
   pkg update && pkg install nodejs git
   cd ~/pharmacy-management/frontend   # wherever you placed the project
   npm install
   npm run build
   ```
3. This takes noticeably longer than on a PC and needs ~2 GB free space.

**Low-RAM tips (8 GB devices):**
- Close Chrome and other apps before `npm install` / `npm run build` — Vite's build can peak at 1.5–2 GB RAM.
- If the build is killed (OOM), retry with a smaller worker pool: `NODE_OPTIONS=--max-old-space-size=3072 npm run build`.
- Prefer **Option A (PC build)** — it avoids the RAM pressure entirely, and `dist/` is just static files you copy over.

## Step 10 — Copy frontend build to the web directory

1. Create a folder for the UI under KSWEB's web root, e.g. `/htdocs/pharmacy/` (the same `pharmacy` folder that holds `backend/`), and copy **the contents of `frontend/dist/`** (the built `index.html`, `assets/`, `icons/`, etc.) into it.
2. Resulting layout on the phone:
   ```
   /htdocs/pharmacy/
   ├── index.html        ← frontend (from dist/)
   ├── assets/
   ├── icons/
   └── backend/
       ├── index.php     ← API front controller
       ├── .env
       └── vendor/
   ```
3. The `.htaccess` inside `backend/` rewrites `/api/*` to `backend/index.php`; requests for `/` serve the frontend's `index.html`. Keep `backend/` exactly as named so step 11's URL matches.

## Step 11 — Configure API URL

The frontend bakes the API base URL in **at build time** via `VITE_API_URL`, so set it **before** running `npm run build` (step 9):

1. On the build machine, create `frontend/.env` with:
   ```bash
   VITE_API_URL=http://localhost:8080/pharmacy/backend/api
   ```
   (Adjust the path/port if your KSWEB setup differs.)
2. Rebuild: `npm run build`, then re-copy `dist/` to the phone (step 10).
3. Sanity check in Chrome devtools (or just use the app): API calls should go to `http://localhost:8080/pharmacy/backend/api/...` and return JSON. A `404` on `/api/health` means the path in `VITE_API_URL` doesn't match where `backend/` actually sits under `/htdocs`.

## Step 12 — Open the application in Chrome

1. On the phone, open **Chrome** and go to:
   ```
   http://localhost:8080/pharmacy/
   ```
2. The login screen appears. Sign in with the demo account:
   - Email: `admin@pharmacy.local` — Password: `password123`
3. **Immediately** change the password (Profile → Change password), then set your pharmacy name/logo in **Settings**.
4. To use it from another device on the same Wi-Fi, replace `localhost` with the phone's LAN IP (find it in Wi-Fi settings, e.g. `http://192.168.1.50:8080/pharmacy/`).

### KSWEB tips & troubleshooting

- **Apache won't start:** another app may hold port 8080 — change KSWEB's HTTP port in settings and update the URLs above.
- **MySQL won't start:** free up RAM (close background apps); on some phones MySQL needs a restart of KSWEB after a reboot.
- **`SQLSTATE` connection errors:** re-check step 8 — `DB_HOST=localhost`, `DB_PORT=3306`, `DB_USER=root`, empty `DB_PASSWORD` are KSWEB's defaults.
- **Uploads failing:** `storage/` under the project root and `backend/uploads/` must be writable by KSWEB's PHP process; if writes fail, copy the `storage/` folders' `.gitkeep` structure and check folder permissions in your file manager.
- **Notifications (low stock / expiry):** Android has no cron; run the generator manually when needed, or trigger it from a Termux `crontab`/scheduler script: `php /path/to/backend/cli/generate-notifications.php` (see `docs/INSTALLATION.md` §9).
- **PWA install:** in Chrome menu tap "Add to Home screen" / "Install app" for an app-like icon; the service worker caches the shell for offline use.
