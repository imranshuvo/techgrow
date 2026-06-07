# TechGrow Ltd — Early-Access Landing Page

A simple, fast, production-friendly landing page that collects interested
leads before the full TechGrow Ltd website and SaaS products launch.

Built with **plain PHP** (no framework), **Tailwind CSS** (CDN), and a local
**SQLite** database. No Composer dependencies required.

---

## Features

- Clean, modern, mobile-first landing page (white/light, premium typography)
- Hero, About, "What we're building", email collector, and footer sections
- Lead capture form with:
  - Server-side **validation** and **sanitisation**
  - **CSRF protection** (per-session token, constant-time check)
  - **Honeypot** field for spam bots
  - **SQLite** storage via **prepared statements**
  - **Duplicate email** prevention (case-insensitive)
  - **Timestamp**, **IP address**, and user-agent recorded per signup
  - Clear success / info / error messages (POST → Redirect → GET, so refresh
    never re-submits)
- **Admin dashboard** (`/admin.php`): password-protected list of subscribers
  with signup stats, search, pagination, CSV export, and delete (GDPR)
- Database lives **outside the public web root** and is never web-served
- **Environment-based config** (`.env` locally, real env vars on Coolify/server)

---

## Project structure

```
techgrow.ltd/
├── public/                 ← web root (point your server's docroot here)
│   ├── index.php           ← landing page + form handling (controller)
│   ├── admin.php           ← password-protected admin dashboard
│   └── .htaccess           ← directory index, no listings, security headers
├── src/
│   ├── config.php          ← env loader + typed settings
│   ├── database.php        ← PDO/SQLite connection, schema, queries (auto-created)
│   ├── functions.php       ← session, CSRF, sanitisation, validation helpers
│   ├── admin.php           ← admin auth (login, lockout, session)
│   └── .htaccess           ← deny web access (shared-hosting safety net)
├── storage/
│   ├── subscribers.sqlite  ← created automatically on first run (git-ignored)
│   └── .htaccess           ← deny web access (shared-hosting safety net)
├── bin/
│   └── hash-password.php   ← generate the admin password hash
├── .env.example            ← config template (copy to .env for local dev)
├── Dockerfile              ← php:8.3-apache, docroot = public/
├── docker-compose.yml      ← build + persistent SQLite volume (Coolify-ready)
├── .gitignore
└── README.md
```

Keeping `index.php` in `public/` means `src/` and `storage/` are not reachable
over HTTP when the document root is set correctly. The `.htaccess` files are an
extra safety net for shared hosts where everything lives under one folder.

---

## Requirements

- PHP **8.1+** (tested on PHP 8.5)
- PHP extensions: `pdo`, `pdo_sqlite` (both standard / usually bundled)
- A web server (or PHP's built-in server for local development)

Check your setup:

```bash
php -v
php -m | grep -i pdo_sqlite
```

---

## Quick start (local development)

From the project root, serve the `public/` folder:

```bash
php -S localhost:8000 -t public
```

Then open <http://localhost:8000>.

On the first form submission the app will:

1. create the `storage/` directory if needed,
2. create `storage/subscribers.sqlite`, and
3. create the `subscribers` table and indexes.

No manual database setup is required.

---

## Configuration

Settings come from environment variables. For local development, copy the
template and edit it — real environment variables (e.g. set in Coolify) always
take precedence over the `.env` file.

```bash
cp .env.example .env
```

| Variable              | Default      | Purpose                                                        |
|-----------------------|--------------|----------------------------------------------------------------|
| `APP_ENV`             | `production` | `production` hides errors; `local` shows them.                 |
| `ADMIN_PASSWORD_HASH` | _(empty)_    | Bcrypt hash enabling the admin dashboard. Empty = disabled.    |
| `TRUST_PROXY`         | `false`      | Set `true` behind a proxy (Coolify/Nginx/Cloudflare) for real client IPs. |
| `ADMIN_SESSION_IDLE`  | `7200`       | Admin auto-logout after N seconds idle.                        |
| `ADMIN_PAGE_SIZE`     | `25`         | Subscriber rows per page in the dashboard (min 5).             |

The `.env` file is git-ignored and never baked into the Docker image.

---

## Admin dashboard

A password-protected dashboard lives at **`/admin.php`** (e.g.
`https://techgrow.ltd/admin.php`). It reads the same SQLite database and
provides signup stats, search, pagination, CSV export, and per-row delete.

**1. Generate a password hash** (never store the plain password):

```bash
php bin/hash-password.php
# → prints:  ADMIN_PASSWORD_HASH=$2y$12$...
```

**2. Set it in your environment:**

- *Local:* paste the line into `.env`.
- *Coolify / server:* add `ADMIN_PASSWORD_HASH` as an environment variable.

**3. Visit `/admin.php`** and log in. Until a hash is set, the dashboard shows
a "not configured" notice instead of exposing anything.

Security built in: bcrypt verification, CSRF on every form, session-id rotation
on login, idle timeout, and a temporary lockout after 5 failed attempts.

---

## Viewing collected leads

The database is a standard SQLite file. With the `sqlite3` CLI:

```bash
sqlite3 storage/subscribers.sqlite

sqlite> .headers on
sqlite> .mode column
sqlite> SELECT id, name, email, created_at FROM subscribers ORDER BY id DESC;
sqlite> .quit
```

Export to CSV:

```bash
sqlite3 -header -csv storage/subscribers.sqlite \
  "SELECT name, email, message, created_at FROM subscribers;" > leads.csv
```

### Database schema

| Column        | Type    | Notes                                       |
|---------------|---------|---------------------------------------------|
| `id`          | INTEGER | Primary key, auto-increment                 |
| `name`        | TEXT    | Required                                    |
| `email`       | TEXT    | Required, **unique** (case-insensitive)     |
| `message`     | TEXT    | Optional                                    |
| `ip_address`  | TEXT    | Captured at signup                          |
| `user_agent`  | TEXT    | Captured at signup                          |
| `created_at`  | TEXT    | UTC timestamp, defaults to `datetime('now')`|

---

## Deployment

### Option A — Server you control (recommended)

Point the **document root** at the `public/` directory. The rest of the project
stays above the web root and is never served.

**Apache** (virtual host):

```apache
<VirtualHost *:80>
    ServerName techgrow.ltd
    DocumentRoot /var/www/techgrow.ltd/public

    <Directory /var/www/techgrow.ltd/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx** + PHP-FPM:

```nginx
server {
    listen 80;
    server_name techgrow.ltd;
    root /var/www/techgrow.ltd/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Defence in depth: never serve the storage/source folders.
    location ~ ^/(storage|src)/ { deny all; return 404; }
}
```

Make sure the web-server user can write to `storage/`:

```bash
chown -R www-data:www-data storage
chmod 775 storage
```

### Option B — Basic / shared hosting (single public folder)

Some shared hosts force the web root to be `public_html/` (or similar) and
won't let you change it. In that case:

1. Upload the contents of **`public/`** into `public_html/`.
2. Upload the **`src/`** and **`storage/`** folders *above* `public_html/`
   (i.e. into the account's home directory, next to `public_html/`), and update
   the two `require` paths at the top of `index.php` accordingly — e.g.
   `require __DIR__ . '/../src/functions.php';`
3. If you cannot place folders above the web root, upload `src/` and `storage/`
   inside `public_html/` — the bundled `.htaccess` files in those folders deny
   direct web access on Apache as a fallback.

Ensure `storage/` is writable by PHP (often `755` or `775`, set via your host's
file manager or `chmod`).

### Option C — Docker / Docker Compose (incl. Coolify)

The repo ships a `Dockerfile` (php:8.3-apache, document root set to `public/`)
and a `docker-compose.yml`. The SQLite database is kept on a named volume
(`techgrow-storage`) so collected leads **survive redeploys and rebuilds**.

**Local:**

```bash
docker compose up --build       # -> http://localhost:8080
```

`docker-compose.override.yml` publishes the host port for local use only.

**Coolify:**

1. **DNS** — point an `A` record for your domain at the Coolify server IP.
2. **New Resource** → connect the Git repo → branch `main` →
   **Build Pack: Docker Compose** (uses `docker-compose.yml`).
3. **Domain** — set your FQDN on the `web` service. Coolify provisions
   HTTPS (Let's Encrypt) and routes through its proxy to container port 80.
4. **Storage** — the named `techgrow-storage` volume persists automatically;
   no extra config needed. (This is what keeps your leads across deploys.)
5. **Deploy.**

> Without the persistent volume, every redeploy starts with a fresh, empty
> database. The compose file handles this for you — don't remove the volume.

**Back up the SQLite file** (named volumes aren't covered by Coolify's
managed-database backups):

```bash
docker compose exec web \
  sqlite3 storage/subscribers.sqlite ".backup storage/backup.sqlite"
# or copy it off the host:
docker cp <container>:/var/www/html/storage/subscribers.sqlite ./backup.sqlite
```

---

## Security notes

- **Database is not web-exposed.** It sits in `storage/` (outside `public/`),
  is git-ignored, and `.htaccess` denies direct access as a backup.
- **Prepared statements** everywhere — no string-built SQL.
- **CSRF token** per session, validated with `hash_equals` (constant-time).
- **Honeypot** field silently drops obvious bots.
- **Input is sanitised** (control chars + tags stripped, length-capped) and
  **validated** (email format, required fields) on the server.
- **Output is escaped** with `htmlspecialchars` to prevent XSS.
- **Errors are logged**, never shown to visitors (`APP_ENV=production`).
- **Admin auth:** bcrypt password (hash only, via env var), CSRF on every form,
  session-id rotation on login, idle timeout, and lockout after 5 failed tries.
  The `/admin.php` page is `noindex` and stays disabled until a hash is set.
- For production, serve over **HTTPS** so the session cookie's `Secure` flag and
  `SameSite=Lax` give full protection.

---

## Going to production (optional next steps)

- Self-host Tailwind (build a minified CSS file) instead of the CDN for best
  performance and offline reliability — the CDN is intended for the first
  version only.
- Add a double opt-in / confirmation email before adding contacts to a list.
- Pipe new signups to an email tool (Mailchimp/Brevo) when you start sending.
- Add rate limiting (per IP) if the form attracts abuse.

---

© TechGrow Ltd. Contact: **info@techgrow.ltd**
