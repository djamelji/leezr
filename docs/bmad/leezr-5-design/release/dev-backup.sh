#!/usr/bin/env bash
# ADR-863 — DEV full backup before a deployment (the pipeline backs up PROD only). Prints no secret.
#   ssh leezr-dev 'bash -s' < docs/bmad/leezr-5-design/release/dev-backup.sh <label>
set -euo pipefail
APP=/var/www/clients/client1/web3; SHARED=$APP/shared; LABEL=${1:-pre-deploy}
_env() { grep -E "^$1=" "$SHARED/.env" 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" -e 's/[[:space:]]*$//' || true; }
DB=$(_env DB_DATABASE); [ "$DB" = "c1leezrdev" ] || { echo "ABORT: DB_DATABASE is '$DB', expected c1leezrdev"; exit 1; }
CNF=$(mktemp); chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$(_env DB_HOST)" "$(P=$(_env DB_PORT); echo "${P:-3306}")" "$(_env DB_USERNAME)" "$(_env DB_PASSWORD)" > "$CNF"
mkdir -p "$SHARED/backups"; chmod 700 "$SHARED/backups"
BK="$SHARED/backups/${LABEL}_${DB}_$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --no-tablespaces "$DB" | gzip -c > "$BK"
chmod 600 "$BK"; gzip -t "$BK"
zcat "$BK" | tail -1 | grep -q "Dump completed" || { echo "ABORT: dump incomplete"; exit 1; }
echo "file=$BK tables=$(zcat "$BK" | grep -c '^CREATE TABLE') size=$(stat -c %s "$BK") sha256=$(sha256sum "$BK" | cut -d' ' -f1)"
