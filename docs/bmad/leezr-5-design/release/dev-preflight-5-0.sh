#!/usr/bin/env bash
# ADR-862 — Leezr 5.0 DEV pre-deployment checks (same mechanism as Leezr 4.2, docs/bmad/leezr-4-2-audit/release/dev-preflight.sh)
#
#   ssh leezr-dev 'bash -s' < docs/bmad/leezr-5-design/release/dev-preflight-5-0.sh > dev-preflight-5-0.txt
#
# Only write : a fresh, verified gzip dump of c1leezrdev in shared/backups (700, outside the webroot) — the GitHub
# Actions pipeline backs up PROD only. Everything else is read only. No secret, password hash, e-mail or session id is
# printed : DB credentials go from shared/.env into a 600 temp file deleted on exit.
set -euo pipefail
APP=/var/www/clients/client1/web3; SHARED=$APP/shared; PHP=php8.4
_env() { grep -E "^$1=" "$SHARED/.env" 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" -e 's/[[:space:]]*$//' || true; }

echo "== 0. release live on DEV"
readlink "$APP/current"

echo "== 1. flags and drivers (DOCUMENT_SCANNER must be absent, none or clamav — NEVER simulated)"
for k in APP_ENV DOCUMENT_SCANNER QUEUE_CONNECTION SESSION_DRIVER CACHE_STORE CACHE_DRIVER MAIL_MAILER REALTIME_DRIVER COMPANY_EXCEPTIONAL_EXPORT_ENABLED BILLING_ASYNC_QUEUES_ENABLED; do
  v=$(_env "$k"); echo "$k=${v:-<absent>}"
done
[ "$(_env DOCUMENT_SCANNER)" = "simulated" ] && { echo "ABORT: DOCUMENT_SCANNER=simulated on DEV"; exit 1; }

echo "== 2. fresh backup"
DB=$(_env DB_DATABASE); [ "$DB" = "c1leezrdev" ] || { echo "ABORT: DB_DATABASE is '$DB', expected c1leezrdev"; exit 1; }
CNF=$(mktemp); chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$(_env DB_HOST)" "$(P=$(_env DB_PORT); echo "${P:-3306}")" "$(_env DB_USERNAME)" "$(_env DB_PASSWORD)" > "$CNF"
mkdir -p "$SHARED/backups"; chmod 700 "$SHARED/backups"
BK="$SHARED/backups/pre-5-0_c1leezrdev_$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
set -o pipefail
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --no-tablespaces "$DB" | gzip -c > "$BK"
chmod 600 "$BK"; gzip -t "$BK"
zcat "$BK" | tail -1 | grep -q "Dump completed" || { echo "ABORT: dump incomplete"; exit 1; }
echo "file=$BK tables=$(zcat "$BK" | grep -c '^CREATE TABLE') sha256=$(sha256sum "$BK" | cut -d' ' -f1)"
Q() { mysql --defaults-extra-file="$CNF" -N "$DB" -e "$1"; }
echo "historical companies (id, slug, status, updated_at): $(Q 'select id, slug, status, updated_at from companies order by id limit 10' | tr '\n' ' ')"

echo "== 3. pending migrations (expected after the deploy: 2026_12_20_000001 only, additive)"
cd "$APP/current" && $PHP artisan migrate:status 2>/dev/null | grep -E "Pending" || echo "none pending on the live release (the new one brings 2026_12_20_000001)"
echo "company_document_settings rows: $(Q 'select count(*) from company_document_settings') — the new column is NULL = historical scope « everyone »"

echo "== 4. system sync dry-run (live code ; administered values preserved, conflicts reported)"
$PHP artisan system:sync --dry-run 2>&1 | grep -E "System sync|conflict|DRY RUN" | head -12 || true

echo "== 5. workers and queues"
systemctl list-units --type=service --no-legend 2>/dev/null | grep -i leezr || echo "no leezr unit visible from this account"
Q "select 'jobs', count(*) from jobs union all select 'failed_jobs', count(*) from failed_jobs union all select concat('failed_jobs_24h'), count(*) from failed_jobs where failed_at > now() - interval 1 day"

echo "== 6. Platform accounts (no e-mail, no hash) — recovery paths before the owner changes the password of #1"
Q "select p.id, group_concat(r.\`key\`) roles, if(exists(select 1 from two_factor_credentials t where t.authenticatable_type like '%PlatformUser' and t.authenticatable_id = p.id and t.confirmed_at is not null),'2fa-on','2fa-off') from platform_users p left join platform_role_user pr on pr.platform_user_id = p.id left join platform_roles r on r.id = pr.platform_role_id group by p.id order by p.id" 2>/dev/null \
  || Q "select p.id, group_concat(r.\`key\`) from platform_users p left join platform_role_user pr on pr.platform_user_id = p.id left join platform_roles r on r.id = pr.platform_role_id group by p.id order by p.id"
echo "platform sessions of #1: $(Q "select count(*) from sessions where platform_user_id = 1" 2>/dev/null || echo n/a)"
echo "== done"
