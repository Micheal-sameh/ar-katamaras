#!/usr/bin/env bash
# Builds the Windows installer and publishes it as an over-the-air update.
# Raise "version" in desktop/package.json first (installed apps only take a higher version).
set -euo pipefail
cd "$(dirname "$0")/.."

HOST="${PUBLISH_HOST:-Ar-linux}"
DIR="${PUBLISH_DIR:-/var/www/ar-katamaras/public/desktop}"

npm run dist:win
ssh "$HOST" "mkdir -p '$DIR'"
# The installer and its blockmap first, latest.yml last: it is what installed apps look at.
scp dist/Avarewase-Readings-Setup-*.exe dist/Avarewase-Readings-Setup-*.exe.blockmap "$HOST:$DIR/"
scp dist/latest.yml "$HOST:$DIR/"
echo "Published. Installed apps pick it up on their next start."
