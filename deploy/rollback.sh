#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────
# deploy/rollback.sh — Instant rollback via symlink (ADR-076)
#
# Usage:
#   bash rollback.sh <app_path>              # rollback to previous release
#   bash rollback.sh <app_path> <release_id> # rollback to specific release
#   bash rollback.sh <app_path> --list       # list all releases
#
# Rollback only switches the symlink. It does NOT:
#   - Run migrations (may need manual intervention for down migrations)
#   - Delete any release
#   - Modify shared/.env
# ──────────────────────────────────────────────────────────────────
set -euo pipefail

APP_PATH="${1:?Usage: rollback.sh <app_path> [release_id|--list]}"
TARGET="${2:-}"

RELEASES_DIR="$APP_PATH/releases"
CURRENT_LINK="$APP_PATH/current"
WEB_LINK="$APP_PATH/web"
SHARED_DIR="$APP_PATH/shared"
LOG_FILE="$SHARED_DIR/storage/logs/deploy.log"

PHP_BIN="${PHP_BIN:-php}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

# ─── List releases ───────────────────────────────────────────────
if [ "$TARGET" = "--list" ]; then
  CURRENT_REAL=$(readlink -f "$CURRENT_LINK" 2>/dev/null || echo "none")
  echo ""
  echo "Releases in $RELEASES_DIR:"
  echo "─────────────────────────────────────────"
  for dir in $(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null); do
    DIRNAME=$(basename "$dir")
    if [ "$(readlink -f "$dir")" = "$CURRENT_REAL" ]; then
      echo "  $DIRNAME  ← CURRENT"
    else
      echo "  $DIRNAME"
    fi
  done
  echo ""
  exit 0
fi

# ─── Determine target release ───────────────────────────────────
if [ -n "$TARGET" ]; then
  # Specific release requested
  ROLLBACK_DIR="$RELEASES_DIR/$TARGET"
  if [ ! -d "$ROLLBACK_DIR" ]; then
    echo "ERROR: release not found: $TARGET"
    echo "Use --list to see available releases."
    exit 1
  fi
else
  # Auto: rollback to previous release (second-newest)
  CURRENT_REAL=$(readlink -f "$CURRENT_LINK" 2>/dev/null || echo "")
  ROLLBACK_DIR=""

  for dir in $(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null); do
    REAL=$(readlink -f "$dir")
    if [ "$REAL" != "$CURRENT_REAL" ]; then
      ROLLBACK_DIR="$REAL"
      break
    fi
  done

  if [ -z "$ROLLBACK_DIR" ]; then
    echo "ERROR: no previous release found to rollback to."
    echo "Use --list to see available releases."
    exit 1
  fi
fi

# ─── Confirm ─────────────────────────────────────────────────────
CURRENT_NAME=$(basename "$(readlink -f "$CURRENT_LINK" 2>/dev/null)" 2>/dev/null || echo "none")
TARGET_NAME=$(basename "$ROLLBACK_DIR")

echo ""
echo "ROLLBACK"
echo "  Current : $CURRENT_NAME"
echo "  Target  : $TARGET_NAME"
echo ""
read -p "Proceed? [y/N] " -r CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
  echo "Aborted."
  exit 0
fi

# ─── Switch symlinks ─────────────────────────────────────────────
log "ROLLBACK: $CURRENT_NAME → $TARGET_NAME"

ln -sfn "$ROLLBACK_DIR" "${CURRENT_LINK}.tmp"
mv -Tf "${CURRENT_LINK}.tmp" "$CURRENT_LINK"

ln -sfn "$CURRENT_LINK/public" "${WEB_LINK}.tmp"
mv -Tf "${WEB_LINK}.tmp" "$WEB_LINK"

# Clear caches
cd "$ROLLBACK_DIR"
$PHP_BIN artisan config:clear 2>/dev/null || true
$PHP_BIN artisan route:clear  2>/dev/null || true
$PHP_BIN artisan view:clear   2>/dev/null || true
$PHP_BIN artisan optimize     2>/dev/null || true

# Reload PHP-FPM
if command -v systemctl &> /dev/null; then
  sudo systemctl reload php8.4-fpm 2>/dev/null || true
fi

log "ROLLBACK OK: current → $TARGET_NAME"
echo ""
echo "Rollback complete: current → $TARGET_NAME"
echo ""
echo "NOTE: If the rolled-back release has different migrations,"
echo "      you may need to run: php artisan migrate:rollback --step=N"
echo ""
