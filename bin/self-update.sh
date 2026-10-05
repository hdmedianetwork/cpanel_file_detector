#!/bin/bash
# Updates the module in place from GitHub, so a new release reaches this
# server with one button press in WHM.
#
#   self-update.sh check    → prints "local <ver> remote <ver> update|current"
#   self-update.sh apply    → re-runs the installer, which pulls the latest code
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GITHUB_REPO="hdmedianetwork/cpanel_file_detector"
GITHUB_BRANCH="${STORAGE_GUARD_BRANCH:-main}"
RAW="${STORAGE_GUARD_RAW:-https://raw.githubusercontent.com/$GITHUB_REPO/$GITHUB_BRANCH}"

ACTION="${1:-check}"

local_version() { cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo "unknown"; }

case "$ACTION" in
  check)
    LOCAL="$(local_version)"
    REMOTE="$(curl -fsSL --max-time 20 "$RAW/VERSION" 2>/dev/null | head -n1 | tr -d '[:space:]' || true)"
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
    # The latest installer, so a change to how installing works ships with
    # the release; the copy already on disk is the fallback.
    TMP="$(mktemp)"
    trap 'rm -f "$TMP"' EXIT
    if ! { curl -fsSL --max-time 30 -o "$TMP" "$RAW/install.sh" \
           && grep -q 'SKYSERVER-STORAGE-GUARD-INSTALLER' "$TMP" && bash -n "$TMP"; }; then
      cp "$INSTALL_DIR/install.sh" "$TMP"
    fi
    bash "$TMP"
    echo "Updated to version $(local_version)."
    ;;

  *)
    echo "usage: self-update.sh [check|apply]" >&2
    exit 1
    ;;
esac
