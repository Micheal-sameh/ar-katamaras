# Upload the mobile update to the server

Run everything from your PC. Server: `Ar-linux`, folder `/var/www/ar-katamaras`.

## 1. Build (local)

Raise `version` in `mobile/package.json` first (phones only take a higher version).

```bash
cd mobile
npm run release-web        # writes update/bundle-<version>.zip and update/version.json
```

## 2. Upload (local → server)

Replace `<version>` with the number in `mobile/package.json` (for example `1.0.5`).

```bash
scp update/bundle-<version>.zip update/version.json Ar-linux:/var/www/ar-katamaras/public/update/
```

Upload the whole `update` folder instead (also sends older zips):

```bash
scp -r update/* Ar-linux:/var/www/ar-katamaras/public/update/
```

## 3. Make the server serve it

```bash
ssh Ar-linux 'cd /var/www/ar-katamaras && docker compose up -d --build web'
```

## 4. Check

```bash
curl https://katamaras.avarewase.com/update/version.json
```

It must show the new version. If it still shows the old one, purge `version.json` in Cloudflare.

## One-shot (build + upload + rebuild)

```bash
cd mobile && npm run release-web && V=$(node -p "require('./package.json').version") \
  && scp update/bundle-$V.zip update/version.json Ar-linux:/var/www/ar-katamaras/public/update/ \
  && ssh Ar-linux 'cd /var/www/ar-katamaras && docker compose up -d --build web'
```

## Not delivered by this update

The new splash screen is native Android. Install the new APK for that (`npm run apk` in `mobile/`).
