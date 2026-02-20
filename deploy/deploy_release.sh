#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────
# deploy/deploy_release.sh — Atomic release deployment (ADR-076)
#
# Called by GitHub Actions AFTER artifact upload.
# Usage: bash deploy_release.sh <artifact.tar.gz> <app_path>
#
# The artifact is a pre-built tar.gz (no npm/composer on VPS).
# Steps: unpack → link shared → migrate → optimize → switch → cleanup
# If ANY step fails before switch, current release stays live.
# ──────────────────────────────────────────────────────────────────
set -euo pipefail

# ─── Arguments ────────────────────────────────────────────────────
ARTIFACT_PATH="${1:?Usage: deploy_release.sh <artifact.tar.gz> <app_path>}"
APP_PATH="${2:?Usage: deploy_release.sh <artifact.tar.gz> <app_path>}"

RELEASES_DIR="$APP_PATH/releases"
SHARED_DIR="$APP_PATH/shared"
CURRENT_LINK="$APP_PATH/current"
WEB_LINK="$APP_PATH/web"
LOG_FILE="$SHARED_DIR/storage/logs/deploy.log"

# ─── PHP binary (ISPConfig may use versioned path) ───────────────
PHP_BIN="${PHP_BIN:-php}"

# ─── Logging ──────────────────────────────────────────────────────
mkdir -p "$(dirname "$LOG_FILE")"
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

# ─── Flock (one deploy at a time per app_path) ───────────────────
LOCK_FILE="$SHARED_DIR/.deploy.lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
  log "BLOCKED — another deploy is running. Exiting."
  exit 1
fi

# ─── Validate artifact ───────────────────────────────────────────
[ -f "$ARTIFACT_PATH" ] || { log "ERROR: artifact not found: $ARTIFACT_PATH"; exit 1; }

# ─── Release ID ──────────────────────────────────────────────────
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
RELEASE_DIR="$RELEASES_DIR/$TIMESTAMP"

log ""
log "═══════════════════════════════════════════════════════════"
log "  DEPLOY START"
log "  Artifact : $ARTIFACT_PATH"
log "  App path : $APP_PATH"
log "═══════════════════════════════════════════════════════════"

# ─── [1/9] Create release directory ──────────────────────────────
log "→ [1/9] Create release directory"
mkdir -p "$RELEASE_DIR"

# ─── [2/9] Unpack artifact ──────────────────────────────────────
log "→ [2/9] Unpack artifact"
tar xzf "$ARTIFACT_PATH" -C "$RELEASE_DIR"

# Rename release with short SHA if metadata present
VERSION="unknown"
if [ -f "$RELEASE_DIR/.build-version" ]; then
  VERSION=$(cat "$RELEASE_DIR/.build-version")
  SHORT_SHA=$(echo "$VERSION" | head -c 7)
  NEW_DIR="$RELEASES_DIR/${TIMESTAMP}_${SHORT_SHA}"
  mv "$RELEASE_DIR" "$NEW_DIR"
  RELEASE_DIR="$NEW_DIR"
  log "  Version: $SHORT_SHA"
fi

# ─── [2.5] Remove Vite hot file if present (ADR-081) ─────────────
rm -f "$RELEASE_DIR/public/hot"

# ─── [3/9] Link shared .env + storage ───────────────────────────
log "→ [3/9] Link shared .env + storage"

# .env
ln -sfn "$SHARED_DIR/.env" "$RELEASE_DIR/.env"

# storage — remove the (empty) directory from artifact, symlink to shared
rm -rf "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"

# bootstrap/cache must exist and be writable
mkdir -p "$RELEASE_DIR/bootstrap/cache"
chmod -R ug+w "$RELEASE_DIR/bootstrap/cache"

# ─── [4/9] Update build version + app version in shared .env ─────
log "→ [4/9] Update APP_BUILD_VERSION=$VERSION"
if grep -q '^APP_BUILD_VERSION=' "$SHARED_DIR/.env" 2>/dev/null; then
  sed -i "s/^APP_BUILD_VERSION=.*/APP_BUILD_VERSION=$VERSION/" "$SHARED_DIR/.env"
else
  echo "APP_BUILD_VERSION=$VERSION" >> "$SHARED_DIR/.env"
fi

# APP_VERSION (semantic, e.g. 1.0.42) — ADR-082
APP_VERSION="dev"
if [ -f "$RELEASE_DIR/.app-version" ]; then
  APP_VERSION=$(cat "$RELEASE_DIR/.app-version")
fi
log "  APP_VERSION=$APP_VERSION"
if grep -q '^APP_VERSION=' "$SHARED_DIR/.env" 2>/dev/null; then
  sed -i "s/^APP_VERSION=.*/APP_VERSION=$APP_VERSION/" "$SHARED_DIR/.env"
else
  echo "APP_VERSION=$APP_VERSION" >> "$SHARED_DIR/.env"
fi

# ─── [5/9] Run migrations ───────────────────────────────────────
log "→ [5/9] Run migrations"
cd "$RELEASE_DIR"
$PHP_BIN artisan migrate --force 2>&1 | tee -a "$LOG_FILE"

# ─── [6/9] Run SystemSeeder (idempotent) ────────────────────────
log "→ [6/9] Run SystemSeeder"
$PHP_BIN artisan db:seed --class=SystemSeeder --force 2>&1 | tee -a "$LOG_FILE"

# ─── [7/9] Clear + optimize ─────────────────────────────────────
log "→ [7/9] Clear caches + optimize"
$PHP_BIN artisan config:clear 2>&1 | tee -a "$LOG_FILE"
$PHP_BIN artisan route:clear  2>&1 | tee -a "$LOG_FILE"
$PHP_BIN artisan view:clear   2>&1 | tee -a "$LOG_FILE"
$PHP_BIN artisan optimize     2>&1 | tee -a "$LOG_FILE"

# ─── [8/9] Health check (BEFORE switch) ─────────────────────────
log "→ [8/9] Health check"
$PHP_BIN artisan route:list    > /dev/null
$PHP_BIN artisan migrate:status > /dev/null
log "  Health check passed"

# ─── [9/9] Atomic symlink switch ────────────────────────────────
log "→ [9/9] Switch symlinks"

# current → release (atomic via mv -Tf = single rename() syscall)
ln -sfn "$RELEASE_DIR" "${CURRENT_LINK}.tmp"
mv -Tf "${CURRENT_LINK}.tmp" "$CURRENT_LINK"
log "  current → $(basename "$RELEASE_DIR")"

# web → current/public (Apache/Nginx document root)
ln -sfn "$CURRENT_LINK/public" "${WEB_LINK}.tmp"
mv -Tf "${WEB_LINK}.tmp" "$WEB_LINK"
log "  web → current/public"

# Reload PHP-FPM (clear OPcache + realpath cache)
if command -v systemctl &> /dev/null; then
  sudo systemctl reload php8.4-fpm 2>/dev/null \
    && log "  PHP-FPM reloaded" \
    || log "  WARN: PHP-FPM reload skipped (no sudo or php8.4-fpm not found)"
fi

# ─── Cleanup old releases (keep 5) ──────────────────────────────
log "  Cleaning old releases (keeping 5)..."
cd "$RELEASES_DIR"
ls -1dt */ 2>/dev/null | tail -n +6 | while read -r OLD; do
  log "    Removing: $OLD"
  rm -rf "$OLD"
done

# Cleanup artifact from /tmp
rm -f "$ARTIFACT_PATH"

# ─── Done ────────────────────────────────────────────────────────
DURATION=$SECONDS
log ""
log "═══════════════════════════════════════════════════════════"
log "  DEPLOY OK: $(date '+%Y-%m-%d %H:%M:%S')"
log "  Version  : $VERSION"
log "  Release  : $(basename "$RELEASE_DIR")"
log "  Duration : ${DURATION}s"
log "═══════════════════════════════════════════════════════════"
log ""
