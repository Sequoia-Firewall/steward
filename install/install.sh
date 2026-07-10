#!/usr/bin/env bash
# Steward — Interactive Installer
# Usage: sudo ./install.sh
set -euo pipefail

# ── colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}  →${NC} $*"; }
success() { echo -e "${GREEN}  ✓${NC} $*"; }
warn()    { echo -e "${YELLOW}  !${NC} $*"; }
die()     { echo -e "${RED}  ✗ ERROR:${NC} $*" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── banner ───────────────────────────────────────────────────────────────────
echo
echo -e "${BOLD}╔══════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║             Steward — Installer          ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════╝${NC}"
echo

# ── root check ───────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    die "This script must be run as root (use sudo ./install.sh)"
fi

# ── prerequisite checks ───────────────────────────────────────────────────────
echo -e "${BOLD}Checking prerequisites…${NC}"

check_cmd() {
    local cmd=$1 pkg=${2:-$1}
    if command -v "$cmd" &>/dev/null; then
        success "$cmd found"
    else
        die "$cmd not found. Install it first: sudo apt-get install $pkg"
    fi
}

check_cmd php php
check_cmd mysql mysql-client

PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')
if [[ $PHP_MAJOR -lt 8 ]]; then
    die "PHP 8.0 or higher required (found PHP ${PHP_MAJOR}.${PHP_MINOR})"
fi
success "PHP ${PHP_MAJOR}.${PHP_MINOR} ok"

for ext in pdo pdo_mysql mbstring; do
    if php -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null; then
        success "PHP extension $ext ok"
    else
        die "PHP extension '$ext' is not loaded. Install php${PHP_MAJOR}.${PHP_MINOR}-mysql (and php-mbstring)."
    fi
done

if command -v apache2 &>/dev/null || command -v apachectl &>/dev/null; then
    success "Apache found"
    WEB_SERVER=apache2
elif command -v nginx &>/dev/null; then
    success "nginx found"
    WEB_SERVER=nginx
else
    warn "Neither Apache nor nginx detected. You will need to configure your web server manually."
    WEB_SERVER=other
fi

echo

# ── configuration prompts ─────────────────────────────────────────────────────
echo -e "${BOLD}Configuration${NC}"
echo -e "  Press Enter to accept the default shown in [brackets]."
echo

prompt() {
    local var=$1 label=$2 default=$3 secret=${4:-no}
    if [[ $secret == yes ]]; then
        read -rsp "  ${label} [leave blank = ${default}]: " val
        echo
    else
        read -rp "  ${label} [${default}]: " val
    fi
    eval "${var}=\"\${val:-${default}}\""
}

prompt WEB_ROOT      "Web root directory"     "/var/www/html"
prompt APP_DIR       "App subdirectory name"  "steward"
prompt APP_PATH      "URL base path"          "/${APP_DIR}"
prompt TIMEZONE      "Timezone"               "America/New_York"

echo
echo -e "  ${BOLD}MySQL / MariaDB connection for the app${NC}"
prompt DB_HOST  "  Database host"          "localhost"
prompt DB_NAME  "  Database name"          "steward"
prompt DB_USER  "  Database user"          "steward"
prompt DB_PASS  "  Database password"      "steward" yes

echo
echo -e "  ${BOLD}MySQL admin credentials (to create the database and user)${NC}"
echo -e "  ${YELLOW}Leave admin user blank to skip DB creation (import schema manually).${NC}"
prompt ADMIN_USER "  MySQL admin user"       "root"
prompt ADMIN_PASS "  MySQL admin password"   ""     yes

echo
LOAD_SAMPLE=n
read -rp "  Load sample data (3 demo users, sample transactions)? [y/N]: " LOAD_SAMPLE
LOAD_SAMPLE=${LOAD_SAMPLE:-n}

echo
# ── confirmation ──────────────────────────────────────────────────────────────
echo -e "${BOLD}Review settings${NC}"
echo "  Install path : ${WEB_ROOT}/${APP_DIR}"
echo "  URL base path: ${APP_PATH}"
echo "  Database     : ${DB_NAME} @ ${DB_HOST}  (user: ${DB_USER})"
echo "  Timezone     : ${TIMEZONE}"
echo
read -rp "  Proceed? [y/N]: " CONFIRM
[[ ${CONFIRM,,} == y* ]] || { echo "Aborted."; exit 0; }
echo

# ── create database and user ──────────────────────────────────────────────────
if [[ -n "$ADMIN_USER" ]]; then
    echo -e "${BOLD}Setting up database…${NC}"

    MYSQL_ADMIN_OPTS=(-h "$DB_HOST" -u "$ADMIN_USER")
    [[ -n "$ADMIN_PASS" ]] && MYSQL_ADMIN_OPTS+=(-p"$ADMIN_PASS")

    mysql "${MYSQL_ADMIN_OPTS[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
FLUSH PRIVILEGES;
SQL
    success "Database '${DB_NAME}' and user '${DB_USER}' ready"

    info "Importing schema…"
    mysql "${MYSQL_ADMIN_OPTS[@]}" "$DB_NAME" < "${SCRIPT_DIR}/schema.sql"
    success "Schema imported"

    if [[ ${LOAD_SAMPLE,,} == y* ]]; then
        info "Importing sample data…"
        if [[ -f "${SCRIPT_DIR}/sample_data.sql" ]]; then
            mysql "${MYSQL_ADMIN_OPTS[@]}" "$DB_NAME" < "${SCRIPT_DIR}/sample_data.sql"
            success "Sample data loaded"
        else
            warn "sample_data.sql not found — skipping"
        fi
    fi
else
    warn "Skipping database creation. Import schema manually:"
    warn "  mysql -u root -p ${DB_NAME} < ${SCRIPT_DIR}/schema.sql"
fi

# ── copy application files ────────────────────────────────────────────────────
INSTALL_DEST="${WEB_ROOT}/${APP_DIR}"
echo
echo -e "${BOLD}Installing application files to ${INSTALL_DEST}…${NC}"

if [[ -d "$INSTALL_DEST" ]]; then
    warn "${INSTALL_DEST} already exists — files will be overwritten."
    read -rp "  Continue? [y/N]: " OVR
    [[ ${OVR,,} == y* ]] || { echo "Aborted."; exit 0; }
fi

APP_SRC="${SCRIPT_DIR}/app"
if [[ ! -d "$APP_SRC" ]]; then
    die "App source directory not found at ${APP_SRC}"
fi

rsync -a --exclude='.claude/' --exclude='install/' "${APP_SRC}/" "${INSTALL_DEST}/"
success "Files copied"

# ── write config/database.php ─────────────────────────────────────────────────
cat > "${INSTALL_DEST}/config/database.php" <<PHP
<?php
define('DB_HOST',    '${DB_HOST}');
define('DB_NAME',    '${DB_NAME}');
define('DB_USER',    '${DB_USER}');
define('DB_PASS',    '${DB_PASS}');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static \$pdo = null;
    if (\$pdo === null) {
        \$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        \$options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, \$options);
        } catch (PDOException \$e) {
            error_log('Database connection failed: ' . \$e->getMessage());
            http_response_code(500);
            die('Database connection failed. Please check configuration.');
        }
    }
    return \$pdo;
}
PHP
success "config/database.php written"

# ── write config/app.php ──────────────────────────────────────────────────────
cat > "${INSTALL_DEST}/config/app.php" <<PHP
<?php
define('APP_NAME',    'Steward');
define('APP_VERSION', '1.0.0');
define('BASE_PATH',   '${APP_PATH}');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_name('STEWARD_SESSION');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('${TIMEZONE}');

ini_set('display_errors', 0);
error_reporting(E_ALL);

define('MONEY_SYMBOL',   '\$');
define('MONEY_DECIMALS', 2);
PHP
success "config/app.php written"

# ── file permissions ──────────────────────────────────────────────────────────
echo
info "Setting file permissions…"
WEB_USER=www-data
find "${INSTALL_DEST}" -type d -exec chmod 755 {} \;
find "${INSTALL_DEST}" -type f -exec chmod 644 {} \;
chown -R "${WEB_USER}:${WEB_USER}" "${INSTALL_DEST}"
success "Permissions set (owner: ${WEB_USER})"

# ── enable Apache mod_rewrite ─────────────────────────────────────────────────
if [[ $WEB_SERVER == apache2 ]]; then
    if command -v a2enmod &>/dev/null; then
        a2enmod rewrite &>/dev/null && info "mod_rewrite enabled (reload apache2 to apply)"
    fi
fi

# ── done ──────────────────────────────────────────────────────────────────────
echo
echo -e "${GREEN}${BOLD}Installation complete!${NC}"
echo
echo -e "  ${BOLD}Next steps:${NC}"
echo
echo -e "  1. Add the Directory block from ${SCRIPT_DIR}/apache.conf.example"
echo -e "     to your Apache VirtualHost config, then reload:"
echo -e "       sudo systemctl reload apache2"
echo
echo -e "  2. Open  http://YOUR_SERVER${APP_PATH}/"
echo
if [[ ${LOAD_SAMPLE,,} == y* ]]; then
    echo -e "  3. Default login credentials (CHANGE THESE IMMEDIATELY):"
    echo -e "       admin  / Admin123!   (administrator)"
    echo -e "       john   / John123!    (user)"
    echo -e "       viewer / View123!    (viewer)"
else
    echo -e "  3. No sample data was loaded."
    echo -e "     Log in with any credentials you add via SQL, or run:"
    echo -e "       mysql -u ${DB_USER} -p ${DB_NAME} < ${SCRIPT_DIR}/sample_data.sql"
fi
echo
