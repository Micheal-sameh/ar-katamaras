# Over-the-air updates (website, Android, Windows)

One codebase, three places it runs. Change the code **in this repo**, never directly on the server, then ship it:

| Where | How people get a change | Reinstall needed? |
|---|---|---|
| Website | `git pull` + rebuild on the server | no |
| Android app | OTA bundle from `/update` | no |
| Windows app | OTA installer from `/desktop` | no (after the first install of 1.0.1 or newer) |

All three read the same web code in `public/`. Server: `Ar-linux`, folder `/var/www/ar-katamaras`, site `https://katamaras.avarewase.com`.

## 1. Website

```bash
git push                      # from your PC
ssh Ar-linux 'cd /var/www/ar-katamaras && git pull && docker compose up -d --build'
```

Cloudflare keeps CSS/JS in browsers for up to 4 hours (it overrides our `no-cache`). Once, in the Cloudflare dashboard: **Caching → Configuration → Browser Cache TTL → Respect Existing Headers**. After each release, purge the changed files (**Caching → Configuration → Purge Cache → Custom Purge**) so people see it now.

## 2. Android app

Phones fetch `https://katamaras.avarewase.com/update/version.json`, and download the zip it points to when its `version` is higher than the one they run.

1. Change the code in `public/`.
2. Raise `version` in `mobile/package.json` (for example `1.0.3` → `1.0.4`). A version that is not higher is ignored.
3. Build the bundle:
   ```bash
   cd mobile
   npm run release-web          # writes update/bundle-<version>.zip and update/version.json
   ```
4. Upload and serve it:
   ```bash
   scp update/bundle-1.0.4.zip update/version.json Ar-linux:/var/www/ar-katamaras/public/update/
   ssh Ar-linux 'cd /var/www/ar-katamaras && docker compose up -d --build web'
   ```
5. On the phone: just open the app. It downloads the update and reloads into it within a few seconds. Phones still on 1.0.3 or older need the old routine once (open, wait, close fully, open again).

Check it: `curl https://katamaras.avarewase.com/update/version.json` shows the new version.

**New APK** (`npm run apk` in `mobile/`) is only needed when native Android code changes (plugins, permissions, icon, app name). Everyone must reinstall it.

## 3. Windows app

Installed copies check `https://katamaras.avarewase.com/desktop/latest.yml` when they start, download a newer installer quietly, and install it when the app is closed.

1. Change the code in `public/`.
2. Raise `version` in `desktop/package.json`.
3. Release:
   ```bash
   cd desktop
   npm run release
   ```
   It builds the installer, uploads the installer and its `.blockmap`, then uploads `latest.yml` last (that file is what apps look at). No server rebuild: `public/desktop` is mounted into the web container.

Rules:
- Give people **`Avarewase-Readings-Setup-<version>.exe`**. The Portable `.exe` cannot update itself.
- The first install is by hand. After that, updates arrive on their own.
- The installer is not code-signed, so the first install shows Windows SmartScreen: **More info → Run anyway**.
- Mac / Linux builds: `npm run dist:mac` / `dist:linux` on that OS. They have no OTA yet.

Check it: `curl https://katamaras.avarewase.com/desktop/latest.yml` shows the new version.

## Troubleshooting

| Problem | Cause / fix |
|---|---|
| Browser still shows the old look | Cloudflare/browser cache: purge the file in Cloudflare, hard-refresh (Ctrl+Shift+R). |
| Phone does not update | Version was not raised, or `version.json` is cached in Cloudflare (purge it). Close the app fully and reopen. |
| Windows app does not update | Running the Portable build, or `version` was not raised. Updates are downloaded at start and installed on quit, so close and reopen once more. |
| `Missing script: "release-web"` | Run it in `mobile/`, not `desktop/`. Desktop uses `npm run release`. |
| `docker compose up` stuck with "container name already in use" | A half-finished recreate. `docker compose down --remove-orphans` then `docker compose up -d`. Data volumes are kept (no `-v`). |
| Port 8080 already in use | Host port is `HTTP_PORT` in `.env` (set to 8090 here); nginx proxies to it. |

## Keeping the server and this repo in step

If you copy a file to the server with `scp`, the server's git copy becomes "modified" and the next `git pull` can conflict. Prefer commit → push → `git pull` on the server. The bundles and installers (`public/update`, `public/desktop`) are build output and are not meant for git.
