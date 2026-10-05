#!/bin/bash
# Updates the module in place from GitHub and re-deploys it, so a code
# change upstream reaches this server with one button press in WHM
# instead of a re-run of the whole installer.
#
#   self-update.sh check    → prints "local <ver> remote <ver> update|current"
#   self-update.sh apply    → pulls the latest code and re-deploys
set -euo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_URL="https://github.com/hdmedianetwork/cpanel_file_detector.git"
REPO_RAW="https://raw.githubusercontent.com/hdmedianetwork/cpanel_file_detector"
REPO_BRANCH="${STORAGE_GUARD_BRANCH:-main}"

ACTION="${1:-check}"

local_version() { cat "$INSTALL_DIR/VERSION" 2>/dev/null || echo "unknown"; }

remote_version() {
  curl -fsSL --max-time 20 "$REPO_RAW/$REPO_BRANCH/VERSION" 2>/dev/null | head -n1 | tr -d '[:space:]'
}

case "$ACTION" in
  check)
    LOCAL="$(local_version)"
    REMOTE="$(remote_version || true)"
    if [ -z "$REMOTE" ]; then
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

    if [ -d "$INSTALL_DIR/.git" ]; then
      git -C "$INSTALL_DIR" fetch --depth 1 origin "$REPO_BRANCH"
      git -C "$INSTALL_DIR" checkout -q "$REPO_BRANCH" 2>/dev/null || git -C "$INSTALL_DIR" checkout -qB "$REPO_BRANCH" "origin/$REPO_BRANCH"
      git -C "$INSTALL_DIR" reset --hard "origin/$REPO_BRANCH"
    else
      # Installed from the standalone bundle, so there's no git checkout
      # to pull into — fetch a fresh copy and overlay it.
      TMP="$(mktemp -d)"
      trap 'rm -rf "$TMP"' EXIT
      git clone --depth 1 --branch "$REPO_BRANCH" "$REPO_URL" "$TMP/repo"
      cp -a "$TMP/repo/." "$INSTALL_DIR/"
    fi

    "$INSTALL_DIR/bin/deploy.sh"
    echo "Updated to version $(local_version)."
    ;;

  *)
    echo "usage: self-update.sh [check|apply]" >&2
    exit 1
    ;;
esac
