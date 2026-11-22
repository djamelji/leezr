#!/usr/bin/env bash
# ADR-852 — Leezr 4.2 DEV pre-deployment checks, run BY THE OWNER from their terminal with their own (rotated) SSH access:
#
#   ssh jliouidevleezr@213.32.20.37 'bash -s' < docs/bmad/leezr-4-2-audit/release/dev-preflight.sh > dev-preflight.txt
#   scp jliouidevleezr@213.32.20.37:/var/www/clients/client1/web3/shared/backups/pre-4-2_* ~/leezr-backups/dev-pre-4-2/
#
# Only write: a fresh, verified, gzip dump of c1leezrdev in shared/backups (700, outside the webroot). Everything else is
# read-only. No secret is printed: DB credentials go from shared/.env into a 600 temp file deleted on exit.
set -euo pipefail
APP=/var/www/clients/client1/web3; SHARED=$APP/shared; PHP=php8.4
_env() { grep -E "^$1=" "$SHARED/.env" 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" -e 's/[[:space:]]*$//'; }

echo "== 1. flags (expected: absent or false)"
for k in COMPANY_EXCEPTIONAL_EXPORT_ENABLED BILLING_ASYNC_QUEUES_ENABLED APP_ENV QUEUE_CONNECTION MAIL_MAILER; do
  v=$(_env "$k"); echo "$k=${v:-<absent>}"
done

echo "== 2. fresh backup"
DB=$(_env DB_DATABASE); [ "$DB" = "c1leezrdev" ] || { echo "ABORT: DB_DATABASE is '$DB', expected c1leezrdev"; exit 1; }
CNF=$(mktemp); chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$(_env DB_HOST)" "$(P=$(_env DB_PORT); echo "${P:-3306}")" "$(_env DB_USERNAME)" "$(_env DB_PASSWORD)" > "$CNF"
mkdir -p "$SHARED/backups"; chmod 700 "$SHARED/backups"
BK="$SHARED/backups/pre-4-2_c1leezrdev_$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
set -o pipefail
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --no-tablespaces "$DB" | gzip -c > "$BK"
chmod 600 "$BK"; gzip -t "$BK"
zcat "$BK" | tail -1 | grep -q "Dump completed" || { echo "ABORT: dump incomplete"; exit 1; }
echo "file=$BK tables=$(zcat "$BK" | grep -c '^CREATE TABLE') sha256=$(sha256sum "$BK" | cut -d' ' -f1)"
echo "companies 1,2 rows: $(mysql --defaults-extra-file="$CNF" -N "$DB" -e 'select id, slug, status, updated_at from companies where id in (1,2)' | tr '\n' ' ')"

echo "== 3. pending migrations (expected: the six 2026_12_14 → 2026_12_19, or fewer)"
cd "$APP/current" && $PHP artisan migrate:status 2>/dev/null | grep -E "Pending" || echo "none pending"

echo "== 4. system sync dry-run (expected: 5 conflicts, administered values preserved)"
$PHP artisan system:sync --dry-run 2>&1 | grep -E "System sync|conflict|DRY RUN" | head -12 || true

echo "== 5. workers"
systemctl list-units --type=service --no-legend 2>/dev/null | grep -i leezr || echo "no leezr unit visible from this account"
grep -h "ExecStart" /etc/systemd/system/leezr*.service 2>/dev/null || true

echo "== 6. failed jobs / jobs"
mysql --defaults-extra-file="$CNF" -N "$DB" -e "select 'jobs', count(*) from jobs union all select 'failed_jobs', count(*) from failed_jobs"
echo "== done"
