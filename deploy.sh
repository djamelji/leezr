#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# deploy.sh — RETIRED (ADR-833, 2026-11-19)
#
# This was the historical git-clone deployment, started by the GitHub webhook public/webhook.php. It ran in
# parallel with GitHub Actions and executed `db:seed --class=DevSeeder` on the real DEV database at every push.
#
# The ONLY deployment path is now GitHub Actions (.github/workflows/deploy.yml → deploy/deploy_release.sh),
# for both dev (web3) and main (web2). This stub intentionally does nothing and never touches the database.
# Second safety net: artisan refuses to run from a legacy `releases/YYYYMMDD_HHMMSS` directory (DeploymentGuard).
# ═══════════════════════════════════════════════════════════════
BASE_PATH="${2:-}"
LOG_FILE="${BASE_PATH:+$BASE_PATH/shared/storage/logs/deploy.log}"
MSG="[$(date '+%Y-%m-%d %H:%M:%S')] LEGACY deploy.sh RETIRED (ADR-833) — branch=${1:-?} ignored; deployments run through GitHub Actions only."
if [ -n "$LOG_FILE" ] && [ -w "$(dirname "$LOG_FILE")" ]; then echo "$MSG" >> "$LOG_FILE"; else echo "$MSG"; fi
exit 0
