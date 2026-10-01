#!/usr/bin/env bash
#
# Builds a ready-to-upload deployment ZIP for shared hosting (Hostinger etc.)
# so you can deploy WITHOUT SSH: just upload the zip in File Manager and extract.
#
# The zip includes the full `vendor/` folder (so you never run composer on the
# server) but excludes dev-only files, tests, your local .env and the SQLite DB.
#
# Usage (run locally where PHP + Composer are installed):
#   bash build-deploy-zip.sh
#
set -euo pipefail
cd "$(dirname "$0")"

echo "==> Installing production dependencies (no dev packages)…"
composer install --no-dev --optimize-autoloader

echo "==> Caching framework config/routes/views for speed…"
php artisan config:clear >/dev/null 2>&1 || true

OUT="wa-platform-deploy-$(date +%Y%m%d-%H%M).zip"
echo "==> Creating $OUT …"

zip -r -q "$OUT" . \
  -x "*.git*" \
  -x "node_modules/*" \
  -x "tests/*" \
  -x "*.zip" \
  -x ".env" \
  -x "database/database.sqlite" \
  -x "storage/logs/*" \
  -x "storage/framework/cache/data/*" \
  -x "storage/framework/sessions/*" \
  -x "storage/framework/views/*" \
  -x "build-deploy-zip.sh"

echo ""
echo "Done -> $OUT"
echo "Upload this to your hosting, extract, then follow README.md 'Deploy without SSH'."
