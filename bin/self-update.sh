#!/bin/bash
# Updates the module in place from GitHub, so a new release reaches this
# server with one button press in WHM.
#
#   self-update.sh check    → prints "local <ver> remote <ver> update|current"
#   self-update.sh apply    → fetches install.sh from the repo and runs it
#
# Uses the token the installer saved in /etc/skyserver-storage-guard.token,
# so it works with the repo private.
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GITHUB_REPO="hdmedianetwork/cpanel_file_detector"
GITHUB_BRANCH="${STORAGE_GUARD_BRANCH:-main}"
TOKEN_FILE="/etc/skyserver-storage-guard.token"
API="${STORAGE_GUARD_API:-https://api.github.com}"

ACTION="${1:-check}"

TOKEN="${STORAGE_GUARD_TOKEN:-}"
if [ -z "$TOKEN" ] && [ -r "$TOKEN_FILE" ]; then
  TOKEN="$(tr -d '[:space:]' < "$TOKEN_FILE")"
fi
AUTH=()
[ -n "$TOKEN" ] && AUTH=(-H "Authorization: Bearer $TOKEN")

local_version() { cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo "unknown"; }

repo_file() { # <path> → contents on stdout
  curl -fsSL --max-time 30 ${AUTH[@]+"${AUTH[@]}"} -H "Accept: application/vnd.github.raw" \
    "$API/repos/$GITHUB_REPO/contents/$1?ref=$GITHUB_BRANCH"
}

case "$ACTION" in
  check)
    LOCAL="$(local_version)"
    REMOTE="$(repo_file VERSION 2>/dev/null | head -n1 | tr -d '[:space:]' || true)"
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
    repo_file install.sh > "$TMP" || { echo "Could not fetch install.sh from GitHub." >&2; exit 1; }
    grep -q 'SKYSERVER-STORAGE-GUARD-INSTALLER' "$TMP" && bash -n "$TMP" \
      || { echo "What GitHub returned is not the Storage Guard installer — not running it." >&2; exit 1; }
    STORAGE_GUARD_TOKEN="$TOKEN" bash "$TMP"
    echo "Updated to version $(local_version)."
    ;;

  *)
    echo "usage: self-update.sh [check|apply]" >&2
    exit 1
    ;;
esac
