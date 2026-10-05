#!/bin/bash
# Updates the module in place from the published installer, so a new
# release reaches this server with one button press in WHM.
#
#   self-update.sh check    → prints "local <ver> remote <ver> update|current"
#   self-update.sh apply    → downloads the published install.sh and runs it
#
# The repo is private, so updates come from the same place the installer
# does: <UPDATE_URL>/VERSION and <UPDATE_URL>/install.sh, both uploaded by
# whoever runs scripts/build-installer.sh. Reinstalling over an existing
# install keeps the config, reports and quarantine — deploy.sh never
# overwrites them.
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Written by the installer; the default is only for an install that predates it.
UPDATE_URL="${STORAGE_GUARD_URL:-$(cat "$INSTALL_DIR/UPDATE_URL" 2>/dev/null || echo "https://storage.gosecureserver.in")}"
UPDATE_URL="${UPDATE_URL%/}"

ACTION="${1:-check}"

local_version() { cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo "unknown"; }

remote_version() {
  curl -fsSL --max-time 20 "$UPDATE_URL/VERSION" 2>/dev/null | head -n1 | tr -d '[:space:]'
}

case "$ACTION" in
  check)
    LOCAL="$(local_version)"
    REMOTE="$(remote_version || true)"
    # Anything that is not a version number is an error page, not a release.
    if ! [[ "$REMOTE" =~ ^[0-9][0-9A-Za-z.+-]*$ ]]; then
      echo "local $LOCAL remote unreachable error"
      exit 1
    fi
    if [ "$LOCAL" = "$REMOTE" ]; then
      echo "local $LOCAL remote $REMOTE current"
    else
      echo "local $LOCAL remote $REMOTE update"
    fi
    ;;

  apply)
    [ "$(id -u)" -eq 0 ] || { echo "self-update must run as root" >&2; exit 1; }
    TMP="$(mktemp)"
    trap 'rm -f "$TMP"' EXIT
    curl -fsSL --max-time 120 -o "$TMP" "$UPDATE_URL/install.sh" \
      || { echo "Could not download $UPDATE_URL/install.sh" >&2; exit 1; }
    # Refuse to run anything that is not our own installer — an error page,
    # a login page, or a half-downloaded file.
    head -n1 "$TMP" | grep -q '^#!/bin/bash' \
      && grep -q 'SKYSERVER-STORAGE-GUARD-INSTALLER' "$TMP" \
      && bash -n "$TMP" \
      || { echo "$UPDATE_URL/install.sh is not a Storage Guard installer — not running it." >&2; exit 1; }
    bash "$TMP"
    echo "Updated to version $(local_version)."
    ;;

  *)
    echo "usage: self-update.sh [check|apply]" >&2
    exit 1
    ;;
esac
