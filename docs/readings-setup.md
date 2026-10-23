# Readings module: setup, operation, packaging

## What it is

- `GET /api/readings/{YYYY-MM-DD}?lang=ar|en` returns one day's readings (Vespers, Matins, Liturgy, Synaxarium).
- Lookup order: cache → database (`daily_readings`) → PPT override → Katameros API. Whatever is found is written back to the database and the cache.
- If the API is down and nothing is stored: HTTP 503 with a friendly message in the requested language.
- Response headers: `Cache-Control`, `ETag` (304 on `If-None-Match`), and `X-Readings-Source` (`api`, `ppt` or `cache`).
- The frontend is plain HTML/CSS/JS in `public/` (a PWA). Laravel serves it at `/`.

## On-prem server setup

Requirements: PHP 8.2+ with `zip`, `mbstring`, `curl`, `xml`, `sqlite3` or `pdo_mysql`; Composer; a web server (nginx/Apache) with document root `public/`.

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
| `readings:import-ppt {path} {--date=} {--coptic=MM-DD} {--lang=}` | Import a PPTX file or a whole folder of them. |

**Known local issue:** if `php artisan readings:warm` fails with `cURL error 77: error adding trust anchors`, PHP's `curl.cainfo` in `php.ini` points to a `cacert.pem` that does not exist. Download a current `cacert.pem` from curl.se, save it, and set `curl.cainfo` and `openssl.cafile` to its path.

## PPT files

PPTX files are converted once on the server and stored as text in `reading_overrides`. They are never served to clients.

- **File name** decides the day: `2026-10-23.pptx` (a Gregorian date) or `coptic-02-13.pptx` (every 13 Paopi; month-day). Add `_ar`, `_en` or `_fr` for the language (default Arabic).
- **Slides:** a slide whose first line names a section (القداس, السنكسار …) or a reading (البولس, الكاثوليكون, الإبركسيس, المزمور والإنجيل …) starts it. Every other line is text of the current reading. Lines that start with a number (`4 text…`) become numbered verses.
- Importing a changed file rewrites the affected stored days and clears their cache. Importing an unchanged file does nothing.
- An override replaces the whole day for that language. The convention above is a first version: send sample files and it will be adjusted to how your slides are really laid out.

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
