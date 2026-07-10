#!/usr/bin/env bash
# Build an installation/upgrade package for Steward.
# Usage: ./build_package.sh [output-dir]
#   Defaults to ~/Desktop if it exists, otherwise ~/
#
# Fresh install:
#   tar xzf steward-<version>.tar.gz -C /var/www/html/
#   Rename the extracted directory, then visit http://server/<name>/setup/
#
# Upgrade from an existing installation:
#   tar xzf steward-<version>.tar.gz --strip-components=1 -C /var/www/html/steward/
#   php /var/www/html/steward/migrate.php                    (applies pending DB migrations)
#   -- OR log in as admin and go to Settings → Migrations and click "Apply All"
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSION=$(grep -oP "APP_VERSION',\s*'\K[^']+" "${APP_DIR}/config/app.php" 2>/dev/null || echo "1.0.0")
PKG_NAME="steward-${VERSION}"
OUT_DIR="${1:-}"

if [[ -z "$OUT_DIR" ]]; then
    OUT_DIR="$( [[ -d "$HOME/Desktop" ]] && echo "$HOME/Desktop" || echo "$HOME" )"
fi

WORK_DIR=$(mktemp -d)
trap 'rm -rf "$WORK_DIR"' EXIT

PKG_DIR="${WORK_DIR}/${PKG_NAME}"
mkdir -p "${PKG_DIR}"

echo "  Copying application files…"
rsync -a \
    --exclude='.git/' \
    --exclude='.claude/' \
    --exclude='converter/.claude/' \
    --exclude='build_package.sh' \
    --exclude='config/database.php' \
    --exclude='setup/.installed' \
    --exclude='converter/storage/tmp/*' \
    "${APP_DIR}/" "${PKG_DIR}/"

echo "  Copying clean schema…"
# The install/ directory already contains the canonical schema used by setup/index.php
# (schema.sql + apache.conf.example are already inside install/ which rsync copied above)

# Make setup wizard executable via web (permissions set after extract by web server)
chmod 644 "${PKG_DIR}/setup/index.php" 2>/dev/null || true

TARBALL="${OUT_DIR}/${PKG_NAME}.tar.gz"
tar -czf "$TARBALL" -C "$WORK_DIR" "$PKG_NAME"

SIZE=$(du -sh "$TARBALL" | cut -f1)

echo
echo "Package built: ${TARBALL}  (${SIZE})"
echo
echo "── Fresh Install ────────────────────────────────────────"
echo "  1. Extract into your web root:"
echo "       tar xzf ${PKG_NAME}.tar.gz -C /var/www/html/"
echo
echo "  2. Set ownership:"
echo "       sudo chown -R www-data:www-data /var/www/html/${PKG_NAME}/"
echo "       # Rename if needed: mv /var/www/html/${PKG_NAME}/ /var/www/html/steward/"
echo
echo "  3. Add to Apache config (see install/apache.conf.example),"
echo "     then open http://YOUR_SERVER/<instance-name>/setup/ in a browser."
echo
echo "  4. After setup completes, delete the setup/ directory:"
echo "       rm -rf /var/www/html/<instance-name>/setup/"
echo
echo "── Upgrade (existing installation) ─────────────────────"
echo "  1. Extract over your existing installation:"
echo "       tar xzf ${PKG_NAME}.tar.gz --strip-components=1 -C /var/www/html/steward/"
echo
echo "  2. Apply database migrations (choose one):"
echo "       php /var/www/html/steward/migrate.php"
echo "     -- OR log in as admin and go to Settings → Migrations"
echo
echo "  3. Set ownership if needed:"
echo "       sudo chown -R www-data:www-data /var/www/html/steward/"
echo
