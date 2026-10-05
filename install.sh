#!/bin/bash
# SkyServer Storage Guard — installer.
# SKYSERVER-STORAGE-GUARD-INSTALLER
#
# Host this one file anywhere (e.g. https://storage.gosecureserver.in/install.sh)
# and run it as root on a WHM/cPanel server:
#
#   curl -sSL https://storage.gosecureserver.in/install.sh | bash
#
# It downloads the code from GitHub and hands off to bin/deploy.sh. Running
# it again updates in place; settings (/etc), scan reports and quarantined
# files (/var/spool, /home/.skyserver-quarantine) are never touched.
#
# Private repo: GitHub needs a token to hand the code over. Put a read-only
# fine-grained token (this repo only, Contents: Read) in GITHUB_TOKEN below,
# or pass it per run:
#
#   curl -sSL https://storage.gosecureserver.in/install.sh | STORAGE_GUARD_TOKEN=github_pat_xxx bash
#
# The token is saved to /etc/skyserver-storage-guard.token (root only) so
# WHM's "Check for Updates" can use it later.
set -euo pipefail

GITHUB_REPO="hdmedianetwork/cpanel_file_detector"
GITHUB_BRANCH="${STORAGE_GUARD_BRANCH:-main}"
GITHUB_TOKEN=""

INSTALL_DIR="/opt/skyserver-storage-guard"
TOKEN_FILE="/etc/skyserver-storage-guard.token"
API="${STORAGE_GUARD_API:-https://api.github.com}"

log()  { echo "[storage-guard] $*"; }
die()  { echo "[storage-guard] ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ]     || die "This installer must be run as root."
[ -d /usr/local/cpanel ] || die "cPanel/WHM installation not found at /usr/local/cpanel."
for t in curl tar; do command -v "$t" >/dev/null 2>&1 || die "$t is required."; done

# Token: from the environment, then the line above, then a previous install.
TOKEN="${STORAGE_GUARD_TOKEN:-$GITHUB_TOKEN}"
if [ -z "$TOKEN" ] && [ -s "$TOKEN_FILE" ]; then
  TOKEN="$(tr -d '[:space:]' < "$TOKEN_FILE")"
fi
AUTH=()
[ -n "$TOKEN" ] && AUTH=(-H "Authorization: Bearer $TOKEN")

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

log "Downloading $GITHUB_REPO ($GITHUB_BRANCH) from GitHub..."
HTTP="$(curl -sSL --max-time 300 ${AUTH[@]+"${AUTH[@]}"} -H "Accept: application/vnd.github+json" \
         -o "$TMP/code.tar.gz" -w '%{http_code}' \
         "$API/repos/$GITHUB_REPO/tarball/$GITHUB_BRANCH" || true)"
case "$HTTP" in
  200) ;;
  401|403|404)
    if [ -n "$TOKEN" ]; then
      die "GitHub refused the token (HTTP $HTTP). It needs read access (Contents: Read) to $GITHUB_REPO."
    else
      die "GitHub returned HTTP $HTTP. The repo is private — run again with a token:
       curl -sSL <this url> | STORAGE_GUARD_TOKEN=github_pat_xxx bash"
    fi ;;
  *) die "Could not download from GitHub (HTTP ${HTTP:-none}). Check this server can reach api.github.com." ;;
esac

mkdir -p "$TMP/code"
tar -xzf "$TMP/code.tar.gz" -C "$TMP/code" --strip-components=1 \
  || die "The download from GitHub is not a valid archive."
[ -x "$TMP/code/bin/deploy.sh" ] && [ -f "$TMP/code/lib/guard-lib.php" ] \
  || die "The download does not look like Storage Guard — nothing was changed."

# Swap the code in as a whole, so a failed download never leaves half of an
# old version mixed with half of a new one.
if [ -f "$INSTALL_DIR/VERSION" ]; then
  log "Updating v$(cat "$INSTALL_DIR/VERSION") → v$(cat "$TMP/code/VERSION") — settings and reports are kept."
else
  log "Installing v$(cat "$TMP/code/VERSION") into $INSTALL_DIR..."
fi
rm -rf "$INSTALL_DIR.new" "$INSTALL_DIR.old"
mv "$TMP/code" "$INSTALL_DIR.new"
[ -d "$INSTALL_DIR" ] && mv "$INSTALL_DIR" "$INSTALL_DIR.old"
mv "$INSTALL_DIR.new" "$INSTALL_DIR"
rm -rf "$INSTALL_DIR.old"

if [ -n "$TOKEN" ]; then
  ( umask 077; printf '%s\n' "$TOKEN" > "$TOKEN_FILE" )
fi

"$INSTALL_DIR/bin/deploy.sh"

echo
log "Install complete (v$(cat "$INSTALL_DIR/VERSION")). Next steps:"
log "  1) WHM → Plugins → SkyServer Storage Guard → Scan all accounts."
log "  2) Look through what it found. Nothing is sent to customers and nothing"
log "     is deleted until you choose to."
log "  3) Settings → set the From address and support contact, send a test email."
log "  4) The nightly scan then runs at 03:30. Automatic reminders and"
log "     suspension stay off until you switch them on."
