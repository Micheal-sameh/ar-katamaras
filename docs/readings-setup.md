# Readings module: setup, operation, packaging

## What it is

- `GET /api/readings/{YYYY-MM-DD}?lang=ar|en` returns one day's readings (Vespers, Matins, Liturgy, Synaxarium).
- Lookup order: cache → database (`daily_readings`) → Katameros API. Whatever is found is written back to the database and the cache.
- If the API is down and nothing is stored: HTTP 503 with a friendly message in the requested language.
- Response headers: `Cache-Control`, `ETag` (304 on `If-None-Match`), and `X-Readings-Source` (`api` or `cache`).
- The frontend is plain HTML/CSS/JS in `public/` (a PWA). Laravel serves it at `/`.

## On-prem server setup

Requirements: PHP 8.2+ with `mbstring`, `curl`, `xml`, `sqlite3` or `pdo_mysql`; Composer; a web server (nginx/Apache) with document root `public/`.

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# .env: APP_ENV=production, APP_DEBUG=false, APP_TIMEZONE=Africa/Cairo, DB_*, CACHE_STORE / READINGS_CACHE_STORE
php artisan migrate --force
php artisan config:cache && php artisan route:cache
php artisan readings:warm            # first fill: the next 7 days, every configured language
php artisan readings:status
```

1. **Timezone.** Set the server's timezone to Africa/Cairo (`timedatectl set-timezone Africa/Cairo`, or Windows Date & Time). `APP_TIMEZONE` already defaults to it.
2. **Cache.** Use Redis if available (`READINGS_CACHE_STORE=redis`); otherwise `database` or `file`. The store must support locks (all three do).
3. **Scheduler.** One cron entry:
   ```
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   ```
   On Windows use Task Scheduler to run `php artisan schedule:run` every minute.
   - Tuesday 03:00 Cairo: `readings:warm --days=7` (every language).
   - Daily 04:00 Cairo: the same command, but only while the window is incomplete. This is the "retry every day until it succeeds" rule. Stored days are skipped, so it costs nothing once complete.
4. **Queue worker (Supervisor)** for the 1 / 5 / 15 minute retries of failed days:
   ```ini
   [program:readings-worker]
   command=php /path/to/app/artisan queue:work --sleep=3 --tries=3 --max-time=3600
   autostart=true
   autorestart=true
   user=www-data
   numprocs=1
   stdout_logfile=/var/log/readings-worker.log
   ```
5. **Alerts.** After every run the command logs how many consecutive days are stored ahead. Fewer than 3 (`READINGS_ALERT_BELOW_DAYS`) logs at `critical` level. Point your log monitoring or a Laravel log channel (Slack, email) at that level.
6. **During an internet outage** the app keeps serving stored days to the local network. Clients also keep the days they viewed (service worker + localStorage).

Useful commands:

| Command | Purpose |
|---|---|
| `readings:warm {--days=7} {--lang=*}` | Fetch and store missing days. Exit code is non-zero if the window is still incomplete. |
| `readings:status` | Stored range per language, and consecutive days stored from today. |

**Known local issue:** if `php artisan readings:warm` fails with `cURL error 77: error adding trust anchors`, PHP's `curl.cainfo` in `php.ini` points to a `cacert.pem` that does not exist. Download a current `cacert.pem` from curl.se, save it, and set `curl.cainfo` and `openssl.cafile` to its path.

## Frontend

- `public/js/config.js` holds `API_BASE`. It is the only setting to change.
- `?demo=1` shows built-in sample data without a backend.
- Change the `VERSION` constant in `public/sw.js` when you ship new frontend files, so installed copies refresh.

## Packaging with Tauri (desktop)

The frontend is static files, so Tauri wraps `public/` directly.

1. Prerequisites: Rust (`rustup`), the Tauri system dependencies for your OS, and Node.js.
2. In a separate folder (not inside the Laravel project):
   ```bash
   npm create tauri-app@latest readings-desktop   # choose "vanilla" / no framework
   ```
3. Copy the contents of `public/` (`index.html`, `css/`, `js/`, `icons/`, `manifest.json`) into that project's frontend folder (`src/` or whatever `frontendDist` in `src-tauri/tauri.conf.json` points to). Do not copy `sw.js`; the desktop app does not need it.
4. Edit the copied `js/config.js` so `API_BASE` is the absolute URL of your Laravel server, for example `http://192.168.1.10/api/readings`. In Tauri the page origin is not your server, so a relative URL would fail.
5. If the server is HTTP rather than HTTPS, allow it in `tauri.conf.json` (CSP `connect-src`), for example:
   `"csp": "default-src 'self'; connect-src 'self' http://192.168.1.10; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com"`.
6. Run `npm run tauri dev` to test and `npm run tauri build` to produce the installer (`.msi` / `.exe`, `.dmg`, `.AppImage`).
7. CORS: Laravel already answers cross-origin requests for `api/*` (any origin). To restrict it, publish the config with `php artisan config:publish cors` and list your origins (`http://tauri.localhost`, `tauri://localhost`).
8. For offline use inside the desktop app, the app already keeps every viewed day in localStorage, and your on-prem server holds the stored days.

## Public access (reachable from outside the church)

The app is a public, read-only site. There are no logins, cookies or write endpoints; there is nothing to log in to.

**What the code already does**
- Only days within 366 days of today can be requested (`READINGS_PAST_DAYS` / `READINGS_FUTURE_DAYS`). Without this, anyone could script thousands of dates and make your server hammer the Katameros API.
- 60 requests per minute per client IP (`READINGS_THROTTLE_PER_MINUTE`), answered with HTTP 429 beyond that.
- Security headers on everything Laravel serves, HSTS over HTTPS, and `Cache-Control` / `ETag` so repeat visits cost almost nothing.
- CORS: `GET` only on `api/*`. Restrict origins with `CORS_ALLOWED_ORIGINS`.
- In production, generated URLs are forced to `https`.

**What you must do on the server**
1. **HTTPS is required.** Browsers only allow the service worker and "install app" over HTTPS. Use Let's Encrypt (certbot), or Cloudflare.
2. **Production `.env`:** `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`, and `APP_URL=https://your-domain`. Never expose `.env`; the web root must be `public/` only.
3. **Behind a reverse proxy, CDN or tunnel** (nginx, Cloudflare, Cloudflare Tunnel): set `TRUSTED_PROXIES` (`*` only if the app can be reached solely through the proxy). Otherwise rate limiting sees the proxy's IP for every visitor, so one heavy user could block everybody.
4. **Network:** open only ports 80/443. A Cloudflare Tunnel avoids opening any inbound port on the church network, which is the safest way to publish from an on-prem machine.
5. **Static files need their own headers.** `index.html`, CSS and JS are served directly by the web server, not by Laravel. nginx example:
   ```nginx
   add_header X-Content-Type-Options "nosniff" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   add_header Strict-Transport-Security "max-age=31536000" always;
   add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'" always;
   location = /sw.js { add_header Cache-Control "no-cache" always; }
   ```
6. **Backups:** the database holds every stored day. Back it up (a nightly copy of `database/database.sqlite`, or `mysqldump`).
7. **Keep PHP and Composer packages updated** (`composer update` regularly, then run the tests).

**Availability trade-off.** If the app runs on a machine inside the church, an internet or power outage there takes the public site down too. The stored days only help people on the local network during an outage. For reliable public access, host it on a small VPS or put Cloudflare in front, since its edge cache can keep answering for days that visitors have already requested.

**Before launch**
- Confirm the licence of the Katameros API for public use. The API's terms decide whether you may cache and re-serve its text.
- The page loads fonts from Google Fonts. For a stricter privacy stance, self-host Amiri, Inter and Noto Sans Arabic and drop the Google links (the CSP above then no longer needs the two Google entries).

Running in Docker instead: see `docs/docker.md` (one `docker compose up -d`).

## Monthly pre-cache and failure email

`readings:warm-month` runs on the **25th of every month at 03:30 (Cairo)**. It stores the rest of the current month plus the whole next month, for every language, so visitors do not wait for a live fetch. Days already stored are skipped. Change the day or time with `READINGS_MONTHLY_DAY` / `READINGS_MONTHLY_TIME`. Run it by hand any time with `php artisan readings:warm-month` (`--months=0` stores only the rest of this month).

**When something goes wrong, an email goes to `READINGS_ALERT_EMAIL`** (default `micheal.sameh@avarewase.com`):
- If the job crashes: immediately, with the error.
- If some days could not be fetched: each failed day is retried after 1, 5 and 15 minutes. About 23 minutes after the run, one check looks at the whole range and sends **one** email listing the missing days and the first errors. If the retries fixed everything, no email is sent.

The email needs a real mail server. The default `MAIL_MAILER=log` only writes the message to `storage/logs`. Set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and a `MAIL_FROM_ADDRESS` your provider allows (a Gmail/Google Workspace or Microsoft 365 account with an app password works). Test it with:

```bash
php artisan tinker --execute="Mail::raw('test', fn(\$m) => \$m->to(config('readings.alerts.email'))->subject('Readings mail test'));"
```

The queue worker must be running (it runs the retries and the check). The weekly Tuesday run still only logs failures; it does not send email.
