#!/usr/bin/env bash
# ADR-833 — PRODUCTION (web2 / leezr.com) : what did the legacy webhook pipeline do? STRICTLY READ-ONLY.
# NOT EXECUTED — requires an explicit authorisation and the production site account (after credential rotation).
#
#   ssh <prod-site-account>@<vps> 'bash -s' < prod-legacy-audit.sh > prod-legacy-audit-$(date -u +%Y%m%dT%H%M%SZ).txt
#
# Reads only: symlinks, release directory listing, deploy.log, the PRESENCE of keys in .env (never values),
# `artisan migrate:status` (read-only). No SQL, no write, no service touched, no secret printed.
set -u
APP="${APP:-/var/www/clients/client1/web2}"
LOG="$APP/shared/storage/logs/deploy.log"
section() { printf '\n## %s\n' "$1"; }
printf '# prod-legacy-audit %s on %s (user %s)\n' "$(date -u +%FT%TZ)" "$(hostname)" "$(id -un)"

section "P1 version en ligne"
readlink -f "$APP/current"; readlink -f "$APP/web" 2>/dev/null
grep -E '^APP_BUILD_VERSION=' "$APP/shared/.env" 2>/dev/null || echo "APP_BUILD_VERSION absent"
grep -E '^APP_ENV=' "$APP/shared/.env" 2>/dev/null

section "P2 releases (nom, propriétaire, date) — ancien format AAAAMMJJ_HHMMSS vs CI AAAAMMJJHHMMSS[_sha]"
ls -la --time-style=+%F_%T "$APP/releases" 2>/dev/null | awk 'NR>1{print $3, $6, $7}'
echo "current est une release héritée : $(readlink -f "$APP/current" | grep -qE '/releases/[0-9]{8}_[0-9]{6}$' && echo OUI || echo non)"

section "P3 deploy.log — bilan des deux pipelines"
if [ -r "$LOG" ]; then
  echo "taille : $(wc -l < "$LOG") lignes"
  echo "héritées démarrées : $(grep -c '^  DEPLOY START: ' "$LOG")   héritées complètes : $(grep -c '^  DEPLOY OK: ' "$LOG")"
  echo "CI démarrées       : $(grep -c '\]   DEPLOY START' "$LOG")   CI complètes       : $(grep -c '\]   DEPLOY OK: ' "$LOG")"
  echo "-- 15 dernières exécutions héritées (début, release, dernière étape atteinte, erreur)"
  awk '/^  DEPLOY START: /{if(s)print s" | "last" | "err; s=$3" "$4; last=""; err=""}
       /^→ \[/{last=$0}
       /^In [A-Za-z]+\.php line|ERR_|command not found|Permission denied|^  DEPLOY OK: /{err=$0}
       /^  Release: /{s=s" "$2}
       END{if(s)print s" | "last" | "err}' "$LOG" | tail -15
  echo "-- étapes héritées ayant écrit en base (migrate / seed) : $(grep -cE '^→ \[(5|6)/9\] (migrate|seed)' "$LOG")"
  echo "-- bascules héritées de current : $(grep -c '^  current → ' "$LOG")"
  grep -n '^  current → ' "$LOG" | tail -5
  echo "-- suppressions héritées de releases"
  grep -n '^  Cleaning: ' "$LOG" | tail -5 | cut -c1-200
else
  echo "deploy.log illisible ou absent"
fi

section "P4 migrations (lecture)"
(cd "$APP/current" && /usr/bin/php8.4 artisan migrate:status --no-ansi 2>&1 | grep -c Pending) || true

section "P5 fichiers de l'ancien mécanisme dans la release en ligne"
ls -la "$APP/current/deploy.sh" "$APP/current/public/webhook.php" 2>&1 | awk '{print $1, $3, $6, $7, $8, $NF}'
grep -c 'RETIRED (ADR-833)' "$APP/current/public/webhook.php" 2>/dev/null | sed 's/^/webhook.php neutralisé : /'
echo "GITHUB_WEBHOOK_SECRET présent dans .env : $(grep -qE '^GITHUB_WEBHOOK_SECRET=.+' "$APP/shared/.env" 2>/dev/null && echo oui || echo non)"
printf '\n# fin prod-legacy-audit\n'
