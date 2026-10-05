#!/bin/bash
# SkyServer Storage Guard — installer (git-based, for development).
#
# Clones the module into /opt/skyserver-storage-guard and hands off to
# bin/deploy.sh, which does the actual wiring (config, cron, logrotate, the
# cPanel page and the WHM dashboard). The same deploy step runs on every
# update. To install on a server that cannot clone this repo, build the
# standalone installer instead: scripts/build-installer.sh.
set -euo pipefail

REPO_URL="https://github.com/hdmedianetwork/cpanel_file_detector.git"
REPO_BRANCH="${STORAGE_GUARD_BRANCH:-main}"
INSTALL_DIR="/opt/skyserver-storage-guard"

log()  { echo "[storage-guard] $*"; }
die()  { echo "[storage-guard] ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ]     || die "This installer must be run as root."
[ -d /usr/local/cpanel ] || die "cPanel/WHM installation not found at /usr/local/cpanel."
command -v git >/dev/null 2>&1 || die "git is required."

log "Fetching module source (branch: $REPO_BRANCH)..."
if [ -d "$INSTALL_DIR/.git" ]; then
  git -C "$INSTALL_DIR" fetch --depth 1 origin "$REPO_BRANCH"
  git -C "$INSTALL_DIR" checkout -qB "$REPO_BRANCH" "origin/$REPO_BRANCH"
  git -C "$INSTALL_DIR" reset --hard "origin/$REPO_BRANCH"
else
  rm -rf "$INSTALL_DIR"
  git clone --depth 1 --branch "$REPO_BRANCH" "$REPO_URL" "$INSTALL_DIR"
fi

"$INSTALL_DIR/bin/deploy.sh"

echo
log "Install complete. Next steps:"
log "  1) WHM → Plugins → SkyServer Storage Guard → Scan all accounts."
log "  2) Look through what it found. Nothing is sent to customers and nothing"
log "     is deleted until you choose to."
log "  3) Settings → set the From address and support contact, send a test email."
log "  4) The nightly scan then runs at 03:30. Automatic reminders and"
log "     suspension stay off until you switch them on."
