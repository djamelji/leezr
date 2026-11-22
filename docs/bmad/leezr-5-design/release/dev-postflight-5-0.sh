#!/usr/bin/env bash
# ADR-862 — Leezr 5.0 DEV post-deployment checks, READ ONLY (no write, no secret printed).
#   ssh leezr-dev 'bash -s' < docs/bmad/leezr-5-design/release/dev-postflight-5-0.sh
set -euo pipefail
APP=/var/www/clients/client1/web3; SHARED=$APP/shared; PHP=php8.4
_env() { grep -E "^$1=" "$SHARED/.env" 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" -e 's/[[:space:]]*$//' || true; }
DB=$(_env DB_DATABASE); [ "$DB" = "c1leezrdev" ] || { echo "ABORT: DB_DATABASE is '$DB'"; exit 1; }
CNF=$(mktemp); chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$(_env DB_HOST)" "$(P=$(_env DB_PORT); echo "${P:-3306}")" "$(_env DB_USERNAME)" "$(_env DB_PASSWORD)" > "$CNF"
Q() { mysql --defaults-extra-file="$CNF" -N "$DB" -e "$1"; }
echo "== release"; readlink "$APP/current"; cat "$APP/current/REVISION" 2>/dev/null || true
echo "DOCUMENT_SCANNER=$(_env DOCUMENT_SCANNER)"; echo "APP_ENV=$(_env APP_ENV)"
cd "$APP/current"
echo "== migrations"
$PHP artisan migrate:status 2>/dev/null | grep -E "2026_12_20_000001" || echo "MISSING 2026_12_20_000001"
echo "pending=$($PHP artisan migrate:status 2>/dev/null | grep -c Pending || true)"
echo "column=$(Q "select column_name from information_schema.columns where table_schema='$DB' and table_name='company_document_settings' and column_name='module_obligation_scope'")"
echo "scope per company: $(Q "select company_id, coalesce(module_obligation_scope,'NULL') from company_document_settings order by company_id" | tr '\n' ' ')"
echo "== system sync (dry run)"
$PHP artisan system:sync --dry-run 2>&1 | grep -E "System sync|conflict|DRY RUN" | head -12 || true
echo "== historical table checksums"
mysql --defaults-extra-file="$CNF" "$DB" -N -e "CHECKSUM TABLE companies, memberships, users, platform_users, company_roles, field_activations, field_definitions, workforce_employees, workforce_job_roles, subscriptions, invoices, financial_ledger_entries, company_modules, shipments"
echo "companies: $(Q 'select id, slug, status, updated_at from companies order by id' | tr '\n' ' ')"
echo "positions (count, with declared activities): $(Q "select count(*), sum(json_extract(metadata,'$.activities') is not null) from workforce_job_roles" | tr '\n' ' ')"
echo "== workers and queues"
systemctl is-active leezr-queue-default-web3 leezr-queue-ai-web3 2>/dev/null | tr '\n' ' '; echo
echo "jobs=$(Q 'select count(*) from jobs') failed_jobs=$(Q 'select count(*) from failed_jobs') failed_24h=$(Q 'select count(*) from failed_jobs where failed_at > now() - interval 1 day')"
echo "== server errors since deploy (laravel log, levels only)"
L=$(ls -t "$SHARED/storage/logs/"laravel*.log 2>/dev/null | head -1)
[ -n "$L" ] && { echo "log=$(basename "$L")"; grep -aE "^\[$(date +%Y-%m-%d)" "$L" | grep -aoE '\.(ERROR|CRITICAL|ALERT|EMERGENCY): .{0,140}' | sort | uniq -c | sort -rn | head -10 || true; } || echo "no laravel log"
echo "== health"; curl -s -o /dev/null -w 'up=%{http_code}\n' https://dev.leezr.com/up
