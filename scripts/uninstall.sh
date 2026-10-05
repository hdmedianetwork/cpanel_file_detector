#!/bin/bash
# Removes SkyServer Storage Guard: cron, the cPanel page, the WHM dashboard
# and the installed code. Leaves the config, the reports and anything still
# in quarantine — quarantined files belong to customers, so they are listed
# at the end for you to put back or delete by hand.
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run as root." >&2; exit 1; }

INSTALL_DIR="/opt/skyserver-storage-guard"
SPOOL_DIR="/var/spool/skyserver-storage-guard"

echo "[storage-guard] Removing cron job and log rotation..."
rm -f /etc/cron.d/skyserver-storage-guard /etc/logrotate.d/skyserver-storage-guard

echo "[storage-guard] Removing the cPanel Storage Report page..."
rm -f /var/cpanel/dynamicui/dynamicui_skyserver_storage.conf
for THEME_DIR in /usr/local/cpanel/base/frontend/*/; do
  rm -rf "${THEME_DIR}skyserver_storage"
  rm -f "${THEME_DIR}dynamicui/dynamicui_skyserver_storage.conf"
  rm -f "${THEME_DIR}assets/application_icons/skyserver_storage".{png,svg}
done

echo "[storage-guard] Removing the WHM dashboard..."
/usr/local/cpanel/bin/unregister_appconfig skyserver_storage_guard >/dev/null 2>&1 || true
rm -rf /usr/local/cpanel/whostmgr/docroot/cgi/skyserver_storage_guard

echo "[storage-guard] Removing the notice copies from account homes..."
for N in "$SPOOL_DIR"/notices/*.json; do
  [ -e "$N" ] || continue
  HOME_DIR="$(getent passwd "$(basename "$N" .json)" 2>/dev/null | cut -d: -f6 || true)"
  # Only a real directory, never a link a customer put in its place.
  if [ -n "$HOME_DIR" ] && [ -d "$HOME_DIR/.skyserver-storage" ] && [ ! -L "$HOME_DIR/.skyserver-storage" ]; then
    rm -f "$HOME_DIR/.skyserver-storage/notice.json"
    rmdir "$HOME_DIR/.skyserver-storage" 2>/dev/null || true
  fi
done

echo "[storage-guard] Removing $INSTALL_DIR..."
rm -rf "$INSTALL_DIR"

LEFT="$(ls "$SPOOL_DIR"/quarantine/*.json 2>/dev/null | wc -l)"
echo "[storage-guard] Done. Kept: /etc/skyserver-storage-guard.conf and $SPOOL_DIR."
if [ "$LEFT" -gt 0 ]; then
  echo "[storage-guard] $LEFT quarantine batch(es) still hold customer files, under each"
  echo "               home's parent folder in .skyserver-quarantine/ (e.g. /home/.skyserver-quarantine)."
fi
