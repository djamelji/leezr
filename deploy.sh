#!/bin/bash
set -euo pipefail

# ═══════════════════════════════════════════════════════════════
# deploy.sh — Leezr atomic release deployment
#
# Usage:
#   bash deploy.sh {branch} {base_path} [--promote]
#
# Examples:
#   bash deploy.sh dev  /var/www/clients/client1/web3             # staging (auto)
#   bash deploy.sh main /var/www/clients/client1/web2             # prod: build only
#   bash deploy.sh main /var/www/clients/client1/web2 --promote   # prod: build + switch
#
# Behavior:
#   dev  → full deploy (build + switch symlink automatically)
#   main → build release only (no switch). Use --promote to activate.
#
# Structure:
#   {base_path}/
#     releases/         ← timestamped release directories
#     shared/
#       .env            ← persistent environment config
#       storage/        ← persistent storage (logs, cache, uploads)
#     current           → releases/{latest}
#     web               → current/public  (Apache document root)
# ═══════════════════════════════════════════════════════════════

BRANCH="${1:?Usage: deploy.sh <branch> <base_path> [--promote]}"
BASE_PATH="${2:?Usage: deploy.sh <branch> <base_path> [--promote]}"
PROMOTE=false
if [[ "${3:-}" == "--promote" ]]; then
    PROMOTE=true
fi

REPO_URL="https://github.com/djamelji/leezr.git"
KEEP_RELEASES=3
RELEASE_NAME=$(date '+%Y%m%d_%H%M%S')
RELEASE_DIR="$BASE_PATH/releases/$RELEASE_NAME"
SHARED_DIR="$BASE_PATH/shared"
LOG_FILE="$SHARED_DIR/storage/logs/deploy.log"

# ─── Ensure base structure ───────────────────────────────────

mkdir -p "$BASE_PATH/releases"
mkdir -p "$SHARED_DIR"

for dir in app/public framework/cache framework/sessions framework/views logs; do
    mkdir -p "$SHARED_DIR/storage/$dir"
done

# ─── Redirect output to deploy log ───────────────────────────

exec >> "$LOG_FILE" 2>&1

# ─── Flock: prevent concurrent deploys ───────────────────────

LOCK_FILE="$SHARED_DIR/.deploy-${BRANCH}.lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] BLOCKED — another $BRANCH deploy is running. Exiting."
    exit 1
fi

# ─── Production gate ─────────────────────────────────────────

if [[ "$BRANCH" == "main" && "$PROMOTE" != true ]]; then
    echo ""
    echo "═══════════════════════════════════════════════════════════"
    echo "  PRODUCTION BUILD: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "  Release will be prepared but NOT activated."
    echo "  To promote: bash deploy.sh main $BASE_PATH --promote"
    echo "═══════════════════════════════════════════════════════════"
fi

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  DEPLOY START: $(date '+%Y-%m-%d %H:%M:%S')"
echo "  Branch:  $BRANCH"
echo "  Release: $RELEASE_NAME"
echo "  Path:    $BASE_PATH"
echo "  Promote: $PROMOTE"
echo "═══════════════════════════════════════════════════════════"

# ─── 1. Clone fresh release ──────────────────────────────────

echo "→ [1/10] git clone --depth=1 -b $BRANCH..."
git clone --depth=1 -b "$BRANCH" "$REPO_URL" "$RELEASE_DIR"
cd "$RELEASE_DIR"
SHORT_HASH=$(git rev-parse --short HEAD)
echo "  Commit: $SHORT_HASH"

# ─── 2. Link shared .env ─────────────────────────────────────

echo "→ [2/10] link shared .env..."
if [ ! -f "$SHARED_DIR/.env" ]; then
    echo "  WARNING: $SHARED_DIR/.env not found — copying .env.production.example"
    cp "$RELEASE_DIR/.env.production.example" "$SHARED_DIR/.env"
    echo "  You MUST edit $SHARED_DIR/.env with real credentials"
fi
ln -sf "$SHARED_DIR/.env" "$RELEASE_DIR/.env"

# ─── 3. Link shared storage ──────────────────────────────────

echo "→ [3/10] link shared storage..."
rm -rf "$RELEASE_DIR/storage"
ln -sf "$SHARED_DIR/storage" "$RELEASE_DIR/storage"

# ─── 4. Backend dependencies ─────────────────────────────────

echo "→ [4/10] composer install..."
composer install --no-dev --optimize-autoloader --no-interaction --quiet --working-dir="$RELEASE_DIR"

# ─── 5. Database migrations ──────────────────────────────────

echo "→ [5/10] migrate..."
php "$RELEASE_DIR/artisan" migrate --force

# ─── 6. System data ──────────────────────────────────────────

echo "→ [6/10] seed SystemSeeder..."
php "$RELEASE_DIR/artisan" db:seed --class=SystemSeeder --force

# ─── 7. Frontend build ───────────────────────────────────────

echo "→ [7/10] pnpm install + build..."
cd "$RELEASE_DIR"
pnpm install --frozen-lockfile
pnpm build

# ─── 8. Inject version + optimize ────────────────────────────

echo "→ [8/10] version + optimize..."
sed -i "s/^VITE_APP_VERSION=.*/VITE_APP_VERSION=$SHORT_HASH/" "$SHARED_DIR/.env"
sed -i "s/^APP_BUILD_VERSION=.*/APP_BUILD_VERSION=$SHORT_HASH/" "$SHARED_DIR/.env"
php "$RELEASE_DIR/artisan" storage:link --force 2>/dev/null || true
php "$RELEASE_DIR/artisan" optimize
php "$RELEASE_DIR/artisan" event:cache

# ─── 9. Health check ─────────────────────────────────────────

echo "→ [9/10] health check..."
php "$RELEASE_DIR/artisan" config:clear
php "$RELEASE_DIR/artisan" route:list > /dev/null
php "$RELEASE_DIR/artisan" migrate:status > /dev/null
echo "  Health check passed."

# Re-optimize after config:clear
php "$RELEASE_DIR/artisan" optimize

# ─── 10. Symlink switch (or skip for prod without --promote) ─

if [[ "$BRANCH" == "main" && "$PROMOTE" != true ]]; then
    echo "→ [10/10] SKIPPED — production release prepared but not activated."
    echo "  Release ready at: $RELEASE_DIR"
    echo "  To promote: bash deploy.sh main $BASE_PATH --promote"
    echo ""
    echo "═══════════════════════════════════════════════════════════"
    echo "  BUILD OK (not promoted): $(date '+%Y-%m-%d %H:%M:%S')"
    echo "  Version:   $SHORT_HASH"
    echo "  Release:   $RELEASE_NAME"
    echo "  Duration:  ${SECONDS}s"
    echo "═══════════════════════════════════════════════════════════"
    exit 0
fi

echo "→ [10/10] switch symlinks..."

# current → releases/{timestamp}  (atomic via temp + mv)
ln -sfn "$RELEASE_DIR" "$BASE_PATH/current.tmp"
mv -Tf "$BASE_PATH/current.tmp" "$BASE_PATH/current"

# web → current/public  (Apache document root)
ln -sfn "$BASE_PATH/current/public" "$BASE_PATH/web"

echo "  current → $RELEASE_DIR"
echo "  web     → current/public"

# ─── Cleanup old releases ────────────────────────────────────

cd "$BASE_PATH/releases"
OLD_RELEASES=$(ls -1dt */ 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)))
if [ -n "$OLD_RELEASES" ]; then
    echo "  Cleaning: $OLD_RELEASES"
    echo "$OLD_RELEASES" | xargs rm -rf
fi

echo "═══════════════════════════════════════════════════════════"
echo "  DEPLOY OK: $(date '+%Y-%m-%d %H:%M:%S')"
echo "  Version:   $SHORT_HASH"
echo "  Release:   $RELEASE_NAME"
echo "  Duration:  ${SECONDS}s"
echo "═══════════════════════════════════════════════════════════"
