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
ARTIFACT_PATH="${1:?Usage: deploy_release.sh <artifact.tar.gz> <app_path> [branch]}"
APP_PATH="${2:?Usage: deploy_release.sh <artifact.tar.gz> <app_path> [branch]}"
BRANCH="${3:-main}"

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

# ─── [0/9] Ensure system dependencies (ADR-409) ─────────────────
log "→ [0/9] Ensure system dependencies (ImageMagick, Tesseract, Ghostscript)"
DEPS_NEEDED=""
{ command -v magick &>/dev/null || command -v convert &>/dev/null; } || DEPS_NEEDED="$DEPS_NEEDED imagemagick"
command -v tesseract &>/dev/null || DEPS_NEEDED="$DEPS_NEEDED tesseract-ocr tesseract-ocr-fra tesseract-ocr-eng"
command -v gs &>/dev/null || DEPS_NEEDED="$DEPS_NEEDED ghostscript"
command -v python3 &>/dev/null || DEPS_NEEDED="$DEPS_NEEDED python3 python3-pip"
if [ -n "$DEPS_NEEDED" ]; then
  log "  Installing: $DEPS_NEEDED"
  sudo apt-get update -qq && sudo apt-get install -y -qq $DEPS_NEEDED 2>&1 | tee -a "$LOG_FILE"
else
  log "  All system deps present"
fi

# ImageMagick 6 on Ubuntu blocks PDF operations by default — allow them for document processing
for POLICY_FILE in /etc/ImageMagick-6/policy.xml /etc/ImageMagick-7/policy.xml; do
  if [ -f "$POLICY_FILE" ] && grep -q 'rights="none".*pattern="PDF"' "$POLICY_FILE"; then
    log "  Fixing ImageMagick policy for PDF support in $POLICY_FILE"
    sudo sed -i 's/<policy domain="coder" rights="none" pattern="PDF"/<policy domain="coder" rights="read|write" pattern="PDF"/' "$POLICY_FILE"
  fi
done

# ADR-414: Install Ollama for AI document analysis (if not present)
if ! command -v ollama &>/dev/null; then
  log "  Installing Ollama..."
  curl -fsSL https://ollama.com/install.sh | sh 2>&1 | tee -a "$LOG_FILE" || log "  WARNING: Ollama install failed (non-fatal)"
fi
# Ensure Ollama service is running and pull default model
if command -v ollama &>/dev/null && command -v systemctl &>/dev/null; then
  if ! systemctl is-active --quiet ollama 2>/dev/null; then
    sudo systemctl enable ollama 2>/dev/null || true
    sudo systemctl start ollama 2>/dev/null || true
    log "  Ollama service started"
    sleep 3  # wait for service to be ready
  fi
  # Pull moondream model in background (small vision model, ~1.7GB)
  if ! ollama list 2>/dev/null | grep -q "moondream"; then
    log "  Pulling moondream model (background)..."
    nohup ollama pull moondream >> "$LOG_FILE" 2>&1 &
  else
    log "  Ollama moondream model already present"
  fi
fi

# ADR-410: Install Python OpenCV dependencies in venv for document detection
PYTHON_SCRIPTS="$APP_PATH/current/scripts/python"
if [ -f "$PYTHON_SCRIPTS/requirements.txt" ]; then
  log "  Setting up Python venv for document detection"
  python3 -m venv "$PYTHON_SCRIPTS/.venv" 2>&1 | tee -a "$LOG_FILE" || true
  if [ -f "$PYTHON_SCRIPTS/.venv/bin/pip" ]; then
    "$PYTHON_SCRIPTS/.venv/bin/pip" install -q -r "$PYTHON_SCRIPTS/requirements.txt" 2>&1 | tee -a "$LOG_FILE" || log "  WARNING: pip install failed (non-fatal)"
  fi
fi

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

# Build metadata from .app-meta (ADR-082)
if [ -f "$RELEASE_DIR/.app-meta" ]; then
  while IFS='=' read -r key value; do
    key=$(echo "$key" | xargs)
    value=$(echo "$value" | xargs)
    [ -z "$key" ] && continue
    log "  $key=$value"
    if grep -q "^${key}=" "$SHARED_DIR/.env" 2>/dev/null; then
      sed -i "s/^${key}=.*/${key}=${value}/" "$SHARED_DIR/.env"
    else
      echo "${key}=${value}" >> "$SHARED_DIR/.env"
    fi
  done < "$RELEASE_DIR/.app-meta"
else
  log "  WARN: .app-meta not found — skipping metadata injection"
fi

# Ensure AI_DRIVER is set in shared .env (default to ollama if missing)
if ! grep -q '^AI_DRIVER=' "$SHARED_DIR/.env" 2>/dev/null; then
  log "  Adding AI_DRIVER=ollama to .env"
  cat >> "$SHARED_DIR/.env" <<'AIEOF'

# AI — Ollama (ADR-414)
AI_DRIVER=ollama
OLLAMA_HOST=http://localhost:11434
OLLAMA_MODEL=moondream
OLLAMA_VISION_MODEL=moondream
OLLAMA_TIMEOUT=120
AIEOF
fi

# ─── [5/9] Fresh database (no real clients yet) ──────────────────
log "→ [5/9] migrate:fresh --seed (dev mode — no real clients)"
cd "$RELEASE_DIR"
$PHP_BIN artisan migrate:fresh --seed --force 2>&1 | tee -a "$LOG_FILE"

# Demo data on ALL environments (no real clients yet — temporary)
log "→ [5.5] Run DevSeeder (no real clients yet)"
$PHP_BIN artisan db:seed --class=DevSeeder --force 2>&1 | tee -a "$LOG_FILE"

# ─── [7/9] Clear + optimize ─────────────────────────────────────
log "→ [7/9] Clear caches + optimize"
$PHP_BIN artisan config:clear 2>&1 | tee -a "$LOG_FILE"
$PHP_BIN artisan route:clear  2>&1 | tee -a "$LOG_FILE"
$PHP_BIN artisan view:clear   2>&1 | tee -a "$LOG_FILE"
$PHP_BIN artisan optimize     2>&1 | tee -a "$LOG_FILE"

# Fix bootstrap/cache permissions — optimize creates files as deploy user
# but PHP-FPM runs as ISPConfig web user (e.g. web3:client1)
# Detect web user from releases/ dir (owned by ISPConfig web user)
VHOST_OWNER=$(stat -c '%U:%G' "$RELEASES_DIR" 2>/dev/null || echo "")
if [ -n "$VHOST_OWNER" ] && [ "$VHOST_OWNER" != "root:root" ]; then
  sudo chown -R "$VHOST_OWNER" "$RELEASE_DIR/bootstrap/cache" 2>/dev/null || true
  log "  bootstrap/cache ownership → $VHOST_OWNER"
fi
sudo chmod -R a+rw "$RELEASE_DIR/bootstrap/cache" 2>/dev/null || true

# ─── [7.5] Storage — NO symlink (ADR-401) ──────────────────────────
log "→ [7.5] Storage (PHP route, no symlink)"
# ISPConfig uses SymLinksIfOwnerMatch which blocks Apache from following
# the public/storage symlink (403). StorageFileController serves files
# via PHP instead, bypassing the symlink entirely.
# We MUST remove any existing symlink so Apache falls through to the
# .htaccess rewrite → index.php → Laravel → StorageFileController.
mkdir -p "$SHARED_DIR/storage/app/public"
rm -f "$RELEASE_DIR/public/storage"
log "  public/storage symlink removed — files served via PHP route"

# ─── [8/9] Health check (BEFORE switch) ─────────────────────────
log "→ [8/9] Health check"
$PHP_BIN artisan route:list    > /dev/null
$PHP_BIN artisan migrate:status > /dev/null
log "  Health check passed"

# ─── [9/9] Atomic symlink switch ────────────────────────────────
log "→ [9/9] Switch symlinks"

# current → release (atomic via mv -Tf = single rename() syscall)
# Use sudo — ISPConfig web root is owned by root, deploy user may not have write access
if ln -sfn "$RELEASE_DIR" "${CURRENT_LINK}.tmp" 2>/dev/null; then
  mv -Tf "${CURRENT_LINK}.tmp" "$CURRENT_LINK"
else
  sudo ln -sfn "$RELEASE_DIR" "${CURRENT_LINK}.tmp"
  sudo mv -Tf "${CURRENT_LINK}.tmp" "$CURRENT_LINK"
fi
log "  current → $(basename "$RELEASE_DIR")"

# web → current/public (Apache/Nginx document root)
if ln -sfn "$CURRENT_LINK/public" "${WEB_LINK}.tmp" 2>/dev/null; then
  mv -Tf "${WEB_LINK}.tmp" "$WEB_LINK"
else
  sudo ln -sfn "$CURRENT_LINK/public" "${WEB_LINK}.tmp"
  sudo mv -Tf "${WEB_LINK}.tmp" "$WEB_LINK"
fi
log "  web → current/public"

# Reload PHP-FPM (clear OPcache + realpath cache)
# Detect PHP version from the binary used by the app, then reload matching FPM
if command -v systemctl &> /dev/null; then
  APP_PHP_VER=$($PHP_BIN -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
  if [ -n "$APP_PHP_VER" ]; then
    FPM_SERVICE="php${APP_PHP_VER}-fpm"
    sudo systemctl reload "$FPM_SERVICE" 2>/dev/null \
      && log "  PHP-FPM reloaded ($FPM_SERVICE)" \
      || log "  WARN: PHP-FPM reload failed for $FPM_SERVICE"
  else
    log "  WARN: Could not detect PHP version from $PHP_BIN"
  fi
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
