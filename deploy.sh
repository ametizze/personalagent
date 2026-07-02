#!/usr/bin/env bash
#
# PersonalAgent — deploy/setup helper for a Debian/Ubuntu VPS (Nginx + PHP-FPM).
#
# Run from the cloned project directory, as root:
#     sudo ./deploy.sh
#
# Configurable via environment variables (defaults shown):
#     DOMAIN=personalagent.example.com   PHP_BIN=php8.5   WEB_USER=www-data
#     INSTALL_PACKAGES=1  INSTALL_NGINX=1  INSTALL_CRON=1
#
# Example:
#     sudo DOMAIN=bot.meudominio.com ./deploy.sh
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php8.5}"
WEB_USER="${WEB_USER:-www-data}"
DOMAIN="${DOMAIN:-personalagent.example.com}"
INSTALL_PACKAGES="${INSTALL_PACKAGES:-1}"
INSTALL_NGINX="${INSTALL_NGINX:-1}"
INSTALL_CRON="${INSTALL_CRON:-1}"

export COMPOSER_ALLOW_SUPERUSER=1

log()  { printf '\n\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\n\033[1;33m!!\033[0m %s\n'  "$*"; }
die()  { printf '\n\033[1;31mxx\033[0m %s\n'  "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root:  sudo ./deploy.sh"

# 1. System packages -----------------------------------------------------------
if [ "$INSTALL_PACKAGES" = "1" ]; then
    log "Installing system packages..."
    apt-get update -y
    apt-get install -y nginx git unzip \
        "${PHP_BIN}-fpm" "${PHP_BIN}-cli" "${PHP_BIN}-sqlite3" "${PHP_BIN}-curl" "${PHP_BIN}-mbstring"
fi

command -v "$PHP_BIN"  >/dev/null || die "$PHP_BIN not found."
command -v composer    >/dev/null || die "composer not found — install it first (https://getcomposer.org)."

# 2. PHP dependencies ----------------------------------------------------------
log "Installing PHP dependencies (production)..."
composer install --no-dev --optimize-autoloader --working-dir="$APP_DIR" \
    || composer install --no-dev --optimize-autoloader --ignore-platform-req=php --working-dir="$APP_DIR"

# 3. Environment file ----------------------------------------------------------
if [ ! -f "$APP_DIR/.env" ]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    warn ".env created from the example. Fill in your tokens, then re-run this script:"
    warn "    nano $APP_DIR/.env"
    exit 0
fi

# 4. Permissions ---------------------------------------------------------------
log "Setting permissions..."
mkdir -p "$APP_DIR/storage/logs"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/storage"
chmod -R 750 "$APP_DIR/storage"
chown "$WEB_USER":"$WEB_USER" "$APP_DIR/.env"
chmod 640 "$APP_DIR/.env"

# 5. Database migration --------------------------------------------------------
log "Running database migration..."
sudo -u "$WEB_USER" "$PHP_BIN" "$APP_DIR/cron/migrate.php"

# 6. Nginx ---------------------------------------------------------------------
if [ "$INSTALL_NGINX" = "1" ]; then
    log "Configuring Nginx for $DOMAIN..."
    conf="/etc/nginx/sites-available/personalagent"
    sed -e "s|__DOMAIN__|$DOMAIN|g" \
        -e "s|__ROOT__|$APP_DIR|g" \
        -e "s|__PHP_SOCK__|/run/php/${PHP_BIN}-fpm.sock|g" \
        "$APP_DIR/docs/nginx.conf" > "$conf"
    ln -sf "$conf" /etc/nginx/sites-enabled/personalagent
    nginx -t && systemctl reload nginx
fi

# 7. Cron (every minute, as the web user) --------------------------------------
if [ "$INSTALL_CRON" = "1" ]; then
    log "Installing cron job (every minute, as $WEB_USER)..."
    line="* * * * * $PHP_BIN $APP_DIR/cron/scheduler.php >> $APP_DIR/storage/logs/cron.log 2>&1"
    ( crontab -u "$WEB_USER" -l 2>/dev/null | grep -vF "$APP_DIR/cron/scheduler.php" || true; echo "$line" ) \
        | crontab -u "$WEB_USER" -
fi

# Done -------------------------------------------------------------------------
log "Done!"
cat <<EOF

Manual steps left:

  1) Enable HTTPS (required for the Telegram webhook):
       sudo apt install -y certbot python3-certbot-nginx
       sudo certbot --nginx -d $DOMAIN

  2) Register the webhook with Telegram:
       cd $APP_DIR && $PHP_BIN setup-webhook.php

Logs:
  App / webhook : $APP_DIR/storage/logs/app.log
  Cron jobs     : $APP_DIR/storage/logs/cron.log
  Nginx         : /var/log/nginx/personalagent.access.log
                  /var/log/nginx/personalagent.error.log
  PHP-FPM       : /var/log/${PHP_BIN}-fpm.log
EOF
