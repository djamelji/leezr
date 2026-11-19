#!/usr/bin/env bash
# ADR-833 — runs restore-companies-1-2.php ON THE DEV SERVER (web3) — NOT EXECUTED: explicit authorisation required.
#
#   Simulation (default, rollback) :  bash restore-remote.sh
#   Application                     :  bash restore-remote.sh --apply
#
# Both files are copied to the web3 shell account's home first. Credentials are read from web3/shared/.env into a
# 600 temp option file deleted on exit; nothing secret is printed or passed as an argument. Refuses any base other
# than web3's own (the PHP script additionally refuses production databases and requires --confirm-database).
set -euo pipefail
APP=/var/www/clients/client1/web3
ENVF="$APP/shared/.env"
HERE="$(cd "$(dirname "$0")" && pwd)"
ev() { grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"; }
DB="$(ev DB_DATABASE)"
[ "$DB" = "c1leezrdev" ] || { echo "REFUS : base inattendue ($DB)"; exit 2; }
CNF="$(mktemp)"; chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\ndatabase=%s\n' "$(ev DB_HOST)" "$(ev DB_PORT)" "$(ev DB_USERNAME)" "$(ev DB_PASSWORD)" "$DB" > "$CNF"
if [ "${1:-}" = "--apply" ]; then
  /usr/bin/php8.4 "$HERE/restore-companies-1-2.php" "$CNF" --apply --confirm-database="$DB"
else
  /usr/bin/php8.4 "$HERE/restore-companies-1-2.php" "$CNF"
fi
