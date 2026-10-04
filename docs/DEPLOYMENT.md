# Deployment Guide

Production deployment for the Pharmacy Management System. For first-time setup steps (database import, `.env`, build), see [INSTALLATION.md](INSTALLATION.md) — this guide covers **server configuration only**.

> No Firebase, no Supabase, no external services. You deploy a PHP API + MySQL + static frontend build.

---

## 1. Apache (vhost + .htaccess)

**Virtual host** — document root at the project root (the shipped `.htaccess` rewrites `/api/*` to `backend/index.php` and serves the frontend from `/`):

```apache
<VirtualHost *:80>
    ServerName pharmacy.example.com
    DocumentRoot /var/www/pharmacy-management

    <Directory /var/www/pharmacy-management>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Deny direct web access to sensitive dirs
    <Directory /var/www/pharmacy-management/backend>
        # index.php is reached via rewrite only; block everything else sensitive
    </Directory>
    RedirectMatch 404 ^/backend/(config|database|logs|middleware|models|services|helpers|controllers|reports)(/|$)
    RedirectMatch 404 ^/\.env

    ErrorLog ${APACHE_LOG_DIR}/pharmacy-error.log
    CustomLog ${APACHE_LOG_DIR}/pharmacy-access.log combined
</VirtualHost>
```

**`.htaccess`** (place at project root; a starter is shipped — adapt as needed):

```apache
RewriteEngine On

# API -> front controller
RewriteCond %{REQUEST_URI} ^/api/
RewriteRule ^api/(.*)$ backend/index.php [QSA,L]

# SPA fallback for the frontend (don't rewrite real files or /api)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} !^/api/
RewriteRule ^ index.html [L]

# Block dotfiles
RedirectMatch 403 ^/.*/\.
```

Requirements: `a2enmod rewrite`, `AllowOverride All`, PHP 8.1+ via `mod_php` or PHP-FPM.

---

## 2. Nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name pharmacy.example.com;
    root /var/www/pharmacy-management;
    index index.html;

    # --- API: route everything under /api/ to the front controller ---
    location /api/ {
        try_files $uri /backend/index.php$is_args$args;
    }

    # --- PHP execution (only index.php should ever execute) ---
    location = /backend/index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;  # match your PHP version
    }

    # --- Frontend SPA ---
    location / {
        try_files $uri $uri/ /index.html;
    }

    # --- Hardening: never serve these ---
    location ~ ^/backend/(config|database|logs|middleware|models|services|helpers|controllers|reports|vendor)/ {
        deny all; return 404;
    }
    location ~ /\. { deny all; }                 # dotfiles (.env, .git)
    location ~ \.php$ { deny all; }              # no other PHP entry points
    location = /backend/index.php { }            # (handled above)

    # Uploaded files: serve, never execute as PHP
    location /storage/ {
        add_header X-Content-Type-Options nosniff;
        location ~ \.php$ { deny all; }
    }

    client_max_body_size 20M;  # prescription scans, product photos
}
```

Then `nginx -t && systemctl reload nginx`. Adjust `fastcgi_pass` to your PHP-FPM socket/port.

---

## 3. Shared hosting (cPanel-style, `public_html`)

Typical layout when you only get one web-accessible folder:

```
home/user/
├── pharmacy-backend/          # NOT web-accessible: backend/, .env, vendor/
│   ├── backend/
│   └── storage/
└── public_html/               # web root
    ├── index.html             # contents of frontend/dist/
    ├── assets/
    ├── api -> ../pharmacy-backend/backend/index.php   # via .htaccess rewrite instead of symlink
    └── .htaccess
```

Practical approach:

1. Upload `backend/` (with `vendor/` and `backend/.env`) **above** `public_html`, e.g. `~/pharmacy-backend/`.
2. Copy the contents of `frontend/dist/` into `public_html/`.
3. In `public_html/.htaccess`, rewrite `/api/*` to the backend outside the web root:

   ```apache
   RewriteEngine On
   RewriteCond %{REQUEST_URI} ^/api/
   RewriteRule ^api/(.*)$ /home/user/pharmacy-backend/backend/index.php [QSA,L]
   ```

   (Many hosts allow absolute filesystem paths in rewrites; if yours doesn't, place `backend/` *inside* `public_html` and protect subfolders with `Deny from all` `.htaccess` files, blocking `config/`, `database/`, `logs/`, `vendor/`, and `.env`.)

4. Make `storage/` writable by the PHP user (cPanel: 755 on dirs, files owned by your user is usually enough under suPHP/FastCGI).
5. Set `APP_URL` and `CORS_ORIGINS` in `backend/.env` to your real domain; set `APP_ENV=production`.
6. Scheduled notifications: use the host's **Cron Jobs** panel to run `php /home/user/pharmacy-backend/backend/cli/generate-notifications.php` daily (see INSTALLATION.md §9).

---

## 4. Frontend on Netlify / Vercel / GitHub Pages

The frontend is a static Vite build, so it can live on any static host while the PHP API runs on your server.

**Build settings**

| Setting | Value |
|---|---|
| Build command | `npm run build` |
| Publish directory | `frontend/dist` (set base dir to `frontend/` if the host asks) |
| Environment variable | `VITE_API_URL=https://api.example.com/api` ← your PHP server's API base |

> `VITE_API_URL` is baked in at build time — set it in the host's env UI, then trigger a rebuild.

**SPA rewrite rules** (so deep links like `/sales/123` don't 404):

- **Netlify** — `frontend/public/_redirects`:
  ```
  /*    /index.html   200
  ```
- **Vercel** — `vercel.json`:
  ```json
  { "rewrites": [{ "source": "/(.*)", "destination": "/index.html" }] }
  ```
- **GitHub Pages** — add `frontend/public/404.html` that redirects to `/index.html` (Pages has no real rewrite support).

**PWA notes**

- The service worker (`frontend/public/sw.js`, manifest icons under `public/icons/`) is included in `dist/` by the build — keep those files in `public/`.
- Service workers require **HTTPS** (or localhost); plain-HTTP deployments silently disable offline caching.
- If the API is on a different origin, the PWA still works, but cross-origin API calls are never cached — only the app shell is offline-capable. Set `CORS_ORIGINS` on the API to the static host's exact origin.

---

## 5. HTTPS / secure cookies checklist

- [ ] Terminate TLS at the web server or CDN; redirect all HTTP → HTTPS.
- [ ] `APP_URL` in `backend/.env` uses `https://`.
- [ ] Refresh-token cookie is set `HttpOnly; Secure; SameSite=Lax` (Strict if frontend and API share the domain). Never expose tokens to JavaScript.
- [ ] `CORS_ORIGINS` lists exact origins — no `*` in production.
- [ ] HSTS header enabled (`Strict-Transport-Security: max-age=31536000`).
- [ ] `APP_ENV=production` so stack traces never leak to clients.
- [ ] `JWT_SECRET` is long, random, unique per environment, and not in git.

---

## 6. Production `.env` guidance

```ini
APP_ENV=production
APP_URL=https://pharmacy.example.com
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pharmacy
DB_USER=pharmacy_app
DB_PASSWORD=<strong unique password>
JWT_SECRET=<64+ hex chars, unique per environment>
UPLOAD_PATH=storage/uploads
JWT_ACCESS_TTL=900
JWT_REFRESH_TTL=604800
CORS_ORIGINS=https://pharmacy.example.com
AUTH_MODE=jwt
APP_TIMEZONE=Asia/Karachi
```

- Create a **dedicated MySQL user** with privileges only on the app database — never deploy with `root`/empty password.
- Keep `backend/.env` at `640` owned by the PHP user; never commit it (`.gitignore` covers `.env` everywhere).
- Keep `JWT_ACCESS_TTL` short (15 min default). The frontend silently refreshes via the httpOnly cookie.
- Back up `storage/` together with MySQL dumps — uploads are on disk, not in the DB:
  ```bash
  mysqldump -u pharmacy_app -p pharmacy | gzip > pharmacy-$(date +%F).sql.gz
  tar -czf storage-$(date +%F).tar.gz storage/
  ```
