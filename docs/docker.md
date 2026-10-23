# Running with Docker

One command starts the whole stack: the website, the API, the weekly pre-cache scheduler, the retry queue worker and Redis. The setup has not been built or run yet (Docker was not installed on the machine it was written on), so expect to fix small things on the first `docker compose build`.

| Service | What it does |
|---|---|
| `web` | nginx. Serves the PWA from `public/`, passes the API to PHP. Published on `127.0.0.1:8080`. |
| `app` | PHP-FPM. Runs the database migrations when it starts. |
| `queue` | Queue worker (on Redis). Runs the 1 / 5 / 15 minute retries of failed days and the check that emails you after the monthly pre-cache. |
| `scheduler` | The whole schedule (Cairo time): Tuesday 03:00 warm-up, daily 04:00 catch-up, Saturday refresh, the 25th monthly pre-cache and its catch-up, nightly backup, heartbeats. |
| `redis` | Cache, locks and rate limiting. Not reachable from outside. |

Data lives in three Docker volumes: `data` (the SQLite database: every stored day), `storage` (logs) and `redis`.

## First start

```bash
cp docker/app.env.example docker/app.env
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show   # paste the result into docker/app.env as APP_KEY
# also set APP_URL in docker/app.env
docker compose up -d
docker compose exec app php artisan readings:warm     # first fill: the next 7 days, every language
docker compose exec app php artisan readings:status
```

Open http://localhost:8080.

## Everyday commands

```bash
docker compose logs -f scheduler queue app      # the "fewer than 3 days stored ahead" alert appears here (level: critical)
docker compose exec app php artisan readings:status
```

The tests are not in the image; run them on your dev machine (`php artisan test`).

**Update to a new version:** `git pull && docker compose build && docker compose up -d` (migrations run automatically).

**Backup:** the database is one file in the `data` volume:

```bash
docker compose exec app cp /data/database.sqlite /data/backup-$(date +%F).sqlite
docker run --rm -v readings_data:/data -v "$PWD":/backup alpine tar czf /backup/readings-data.tgz -C /data .
```

## Publishing it to the internet

The container listens on `127.0.0.1` only, so nothing is exposed until you add HTTPS in front. Pick one:

- **Cloudflare Tunnel (no open ports on the church network).** Run `cloudflared` on the same machine with a tunnel whose service is `http://localhost:8080`.
- **Caddy / nginx / Traefik on the host** with a Let's Encrypt certificate, proxying to `http://127.0.0.1:8080`. Make sure it sends `X-Forwarded-For` and `X-Forwarded-Proto`.
- **Direct:** set `BIND_ADDRESS=0.0.0.0` (for example in a `.env` file next to `docker-compose.yml`) only on a trusted network, since that serves plain HTTP.

The nginx in the stack trusts `X-Forwarded-For` from private addresses only (Docker network, localhost, LAN). If your proxy has a public address, add it as another `set_real_ip_from` line in `docker/nginx.conf`; otherwise every visitor looks like one IP and the per-IP rate limit would cut everyone off together.

## Known limits

- SQLite is enough for this read-mostly site. If you later need several app servers, switch `DB_CONNECTION` to MySQL/PostgreSQL in `docker-compose.yml`.
- If the machine or its internet connection goes down, the public site goes down with it (see "Availability trade-off" in `docs/readings-setup.md`).

## The queue

The `queue` service starts with the stack and restarts itself if it stops (it also restarts cleanly every hour). Jobs wait in Redis, so nothing is lost if the worker is down for a while, and the `app` and `scheduler` containers hand work to it automatically.

```bash
docker compose ps                                   # queue should be "running"
docker compose logs -f queue                        # one line per job: RUNNING ... DONE / FAIL
docker compose exec redis redis-cli llen queues:default   # jobs waiting
docker compose exec app php artisan queue:failed    # jobs that failed after all their retries
docker compose exec app php artisan queue:retry all # run the failed ones again
```

After updating the code (`docker compose up -d`) the worker is recreated, so it always runs the new code. A job that is running when you stop the stack gets up to 70 seconds to finish.

To send the failure email, the queue worker and a real mail server (`MAIL_*` in `docker/app.env`) are both needed.
## Health checks and backups

`queue` and `scheduler` have Docker health checks (`docker compose ps` shows `unhealthy` if either goes quiet), but Docker does not restart an unhealthy container or tell you about it. For an alert, point an uptime monitor at `https://your-site/api/health` (see "Monitoring" in `docs/readings-setup.md`).

A nightly database copy is written to `/var/www/html/storage/app/backups` inside the `storage` volume. That is the same machine as the data, so copy it off regularly:

```bash
docker compose cp app:/var/www/html/storage/app/backups ./backups-$(date +%F)
```

To restore: stop the stack, copy a backup over `/data/database.sqlite` in the `data` volume, start it again.