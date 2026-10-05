#!/bin/bash
# Deploys whatever is in INSTALL_DIR onto this server: spool, config, cron,
# logrotate, the cPanel Storage Report page and the WHM dashboard.
# Idempotent — the installer and the in-place updater both run it.
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF_FILE="/etc/skyserver-storage-guard.conf"
SPOOL_DIR="/var/spool/skyserver-storage-guard"
CRON_FILE="/etc/cron.d/skyserver-storage-guard"
DYNAMICUI_DIR="/var/cpanel/dynamicui"
FRONTEND_BASE="/usr/local/cpanel/base/frontend"
WHM_CGI_DIR="/usr/local/cpanel/whostmgr/docroot/cgi/skyserver_storage_guard"
PLUGIN_NAME="skyserver_storage"

log() { echo "[storage-guard] $*"; }
die() { echo "[storage-guard] ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ]     || die "deploy.sh must run as root."
[ -d /usr/local/cpanel ] || die "cPanel/WHM not found at /usr/local/cpanel."

chmod +x "$INSTALL_DIR"/bin/guard "$INSTALL_DIR"/bin/*.sh "$INSTALL_DIR"/scripts/*.sh 2>/dev/null || true

PHP_BIN="$(command -v php || true)"
[ -z "$PHP_BIN" ] && [ -x /usr/local/cpanel/3rdparty/bin/php ] && PHP_BIN="/usr/local/cpanel/3rdparty/bin/php"
[ -n "$PHP_BIN" ] || die "No PHP binary found — Storage Guard is written in PHP."

log "Setting up spool directories..."
mkdir -p "$SPOOL_DIR"/{reports,cases,quarantine,scanning,notices,rescan-requests}
# Root's own records: nobody else needs to read them.
chmod 700 "$SPOOL_DIR"/reports "$SPOOL_DIR"/cases "$SPOOL_DIR"/quarantine "$SPOOL_DIR"/scanning
# The cPanel page runs as the account, so it has to be able to walk down to
# its own notice — traversable (x) but not listable, so no account can
# discover anyone else's. Each notice is 0640 root:<user>.
chmod 751 "$SPOOL_DIR" "$SPOOL_DIR"/notices
# "Check again" is a drop box: any account may add its own request, and the
# sticky bit stops it touching anyone else's. bin/guard checks that the
# file's owner is the account it names before acting on it.
chmod 1733 "$SPOOL_DIR"/rescan-requests

if [ ! -f "$CONF_FILE" ]; then
  cp "$INSTALL_DIR/etc/skyserver-storage-guard.conf.example" "$CONF_FILE"
  log "Config created at $CONF_FILE — enforcement is off until you switch it on."
else
  log "Existing config found at $CONF_FILE — leaving it untouched."
fi
chmod 600 "$CONF_FILE"

log "Installing cron jobs..."
sed "s#__INSTALL_DIR__#$INSTALL_DIR#g" "$INSTALL_DIR/etc/cron/skyserver-storage-guard" > "$CRON_FILE"
chmod 644 "$CRON_FILE"

log "Installing log rotation..."
cp "$INSTALL_DIR/etc/logrotate/skyserver-storage-guard" /etc/logrotate.d/skyserver-storage-guard
chmod 644 /etc/logrotate.d/skyserver-storage-guard
touch /var/log/skyserver-storage-guard.log
chmod 600 /var/log/skyserver-storage-guard.log

log "Installing the cPanel Storage Report page into every theme..."
for THEME_DIR in "$FRONTEND_BASE"/*/; do
  [ -d "$THEME_DIR" ] || continue
  DEST="${THEME_DIR}${PLUGIN_NAME}"
  mkdir -p "$DEST"
  cp "$INSTALL_DIR"/plugin/*.php "$DEST/"
  cp "$INSTALL_DIR/ui/sky-ui.php" "$DEST/"
  chmod 644 "$DEST"/*.php

  # The theme reads a menu item's icon from its own application_icons
  # directory, named after the descriptor's file=> key.
  ICON_DIR="${THEME_DIR}assets/application_icons"
  mkdir -p "$ICON_DIR"
  cp "$INSTALL_DIR/plugin/$PLUGIN_NAME.svg" "$ICON_DIR/$PLUGIN_NAME.svg"
  cp "$INSTALL_DIR/plugin/$PLUGIN_NAME.png" "$ICON_DIR/$PLUGIN_NAME.png"
  cp "$INSTALL_DIR/plugin/$PLUGIN_NAME.png" "$DEST/$PLUGIN_NAME.png"
  chmod 644 "$ICON_DIR/$PLUGIN_NAME".* "$DEST/$PLUGIN_NAME.png"

  mkdir -p "${THEME_DIR}dynamicui"
  cp "$INSTALL_DIR/plugin/$PLUGIN_NAME.conf" "${THEME_DIR}dynamicui/dynamicui_$PLUGIN_NAME.conf"
  chmod 644 "${THEME_DIR}dynamicui/dynamicui_$PLUGIN_NAME.conf"
done
mkdir -p "$DYNAMICUI_DIR"
cp "$INSTALL_DIR/plugin/$PLUGIN_NAME.conf" "$DYNAMICUI_DIR/dynamicui_$PLUGIN_NAME.conf"
chmod 644 "$DYNAMICUI_DIR/dynamicui_$PLUGIN_NAME.conf"

log "Installing WHM admin dashboard..."
mkdir -p "$WHM_CGI_DIR"
sed "1s|.*|#!${PHP_BIN}|" "$INSTALL_DIR/whm-plugin/index.cgi" > "$WHM_CGI_DIR/index.cgi"
chmod 750 "$WHM_CGI_DIR/index.cgi"
cp "$INSTALL_DIR/ui/sky-ui.php" "$WHM_CGI_DIR/sky-ui.php"
chmod 640 "$WHM_CGI_DIR/sky-ui.php"

if [ -x /usr/local/cpanel/bin/register_appconfig ]; then
  /usr/local/cpanel/bin/unregister_appconfig skyserver_storage_guard >/dev/null 2>&1 || true
  if REG_OUT="$(/usr/local/cpanel/bin/register_appconfig "$INSTALL_DIR/whm-plugin/skyserver_storage_guard.appconfig" 2>&1)"; then
    log "  WHM plugin registered."
  else
    log "  WARNING: register_appconfig failed. Its output was:"
    printf '    %s\n' "$REG_OUT"
    log "  The WHM menu entry will be missing until this is resolved."
  fi
else
  log "  WARNING: /usr/local/cpanel/bin/register_appconfig not found on this server."
fi

log "Rebuilding cPanel UI caches..."
if [ -x /usr/local/cpanel/scripts/rebuild_sprites ]; then
  /usr/local/cpanel/scripts/rebuild_sprites >/dev/null 2>&1 || true
elif [ -x /usr/local/cpanel/bin/rebuild_sprites ]; then
  /usr/local/cpanel/bin/rebuild_sprites >/dev/null 2>&1 || true
fi

log "Deploy complete (version $(cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo unknown))."
