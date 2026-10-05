#!/bin/bash
# SkyServer Storage Guard — installer.
# SKYSERVER-STORAGE-GUARD-INSTALLER
#
# Run as root on a WHM/cPanel server:
#
#   curl -sSL https://storage.gosecureserver.in/install.sh | bash
#
# Downloads the code from GitHub (public repo, no token needed) and hands off
# to bin/deploy.sh. Running it again updates in place: settings (/etc), scan
# reports and quarantined files are never touched.
set -euo pipefail

GITHUB_REPO="hdmedianetwork/cpanel_file_detector"
GITHUB_BRANCH="${STORAGE_GUARD_BRANCH:-main}"
INSTALL_DIR="/opt/skyserver-storage-guard"
# The branch archive, served by github.com itself — not the API, so it is
# not subject to the API's 60-requests-an-hour limit per server IP.
ARCHIVE_URL="${STORAGE_GUARD_ARCHIVE:-https://github.com/$GITHUB_REPO/archive/refs/heads/$GITHUB_BRANCH.tar.gz}"

log()  { echo "[storage-guard] $*"; }
die()  { echo "[storage-guard] ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ]     || die "Run this as root."
[ -d /usr/local/cpanel ] || die "cPanel/WHM not found at /usr/local/cpanel — this is for cPanel servers only."
for t in curl tar; do command -v "$t" >/dev/null 2>&1 || die "'$t' is required — install it and run again."; done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

log "Downloading $GITHUB_REPO ($GITHUB_BRANCH) from GitHub..."
HTTP="$(curl -sSL --retry 3 --max-time 300 -o "$TMP/code.tar.gz" -w '%{http_code}' "$ARCHIVE_URL" || true)"
case "$HTTP" in
  200) ;;
  404) die "GitHub says the repo or branch '$GITHUB_BRANCH' does not exist (HTTP 404). Is the repo public, and is the code merged into $GITHUB_BRANCH?" ;;
  000|"") die "Could not reach github.com. Check this server's internet/DNS/firewall." ;;
  *)   die "Download from GitHub failed (HTTP $HTTP). Try again in a minute." ;;
esac

mkdir -p "$TMP/code"
tar -xzf "$TMP/code.tar.gz" -C "$TMP/code" --strip-components=1 \
  || die "What GitHub sent is not a valid archive. Try again."
[ -f "$TMP/code/bin/deploy.sh" ] && [ -f "$TMP/code/lib/guard-lib.php" ] \
  || die "The download does not contain Storage Guard — nothing was changed."

NEW_VER="$(cat "$TMP/code/VERSION" 2>/dev/null || echo unknown)"
if [ -f "$INSTALL_DIR/VERSION" ]; then
  log "Updating v$(cat "$INSTALL_DIR/VERSION") → v$NEW_VER (settings and reports are kept)..."
else
  log "Installing v$NEW_VER into $INSTALL_DIR..."
fi

# Swapped in as a whole, so a failure never leaves half an old version
# mixed with half a new one.
rm -rf "$INSTALL_DIR.new" "$INSTALL_DIR.old"
mv "$TMP/code" "$INSTALL_DIR.new"
[ -d "$INSTALL_DIR" ] && mv "$INSTALL_DIR" "$INSTALL_DIR.old"
mv "$INSTALL_DIR.new" "$INSTALL_DIR"
rm -rf "$INSTALL_DIR.old"
chmod +x "$INSTALL_DIR"/bin/guard "$INSTALL_DIR"/bin/*.sh "$INSTALL_DIR"/scripts/*.sh "$INSTALL_DIR"/install.sh 2>/dev/null || true

"$INSTALL_DIR/bin/deploy.sh"

echo
log "Done — Storage Guard v$NEW_VER is installed."
log "Next: WHM → Plugins → SkyServer Storage Guard → Scan all accounts."
log "Nothing is sent to customers and nothing is deleted until you choose to."
