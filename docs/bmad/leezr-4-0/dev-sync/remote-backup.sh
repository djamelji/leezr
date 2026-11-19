#!/usr/bin/env bash
# Leezr 4.0 RC2 — sauvegarde du DEV distant AVANT push (S1 base, S2 fichiers). NON EXÉCUTÉ : autorisation requise.
# Sur web3, par le compte shell du site :   bash remote-backup.sh
# Écrit UNIQUEMENT dans $APP/shared/backups (hors webroot, chmod 700/600). Ne modifie ni la base ni l'application :
# mysqldump --single-transaction lit un instantané cohérent sans verrou bloquant (InnoDB).
# Aucun secret en argument ni à l'écran : identifiants dans un fichier temporaire 600, supprimé à la sortie.
set -euo pipefail
APP="${APP:-/var/www/clients/client1/web3}"
ENVF="$APP/shared/.env"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
BK="$APP/shared/backups"; mkdir -p "$BK"; chmod 700 "$BK"
envval() { grep -E "^$1=" "$ENVF" | head -1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"; }
CNF="$(mktemp "${TMPDIR:-/tmp}/leezr-bk.XXXXXX")"; chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$(envval DB_HOST)" "$(envval DB_PORT)" "$(envval DB_USERNAME)" "$(envval DB_PASSWORD)" > "$CNF"
DB="$(envval DB_DATABASE)"

# S1 — base (structure + données + routines + triggers)
SQL="$BK/pre-rc2_${DB}_${TS}.sql.gz"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces "$DB" | gzip -c > "$SQL"
chmod 600 "$SQL"
# Intégrité : gzip valide, structure présente, dump complet, empreinte
gzip -t "$SQL"
TABLES=$(gzip -dc "$SQL" | grep -c 'CREATE TABLE' || true)
gzip -dc "$SQL" | tail -3 | grep -q 'Dump completed' || { echo "ERREUR : dump incomplet"; exit 1; }
LIVE=$(mysql --defaults-extra-file="$CNF" -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_TYPE='BASE TABLE'")
[ "$TABLES" -eq "$LIVE" ] || { echo "ERREUR : $TABLES tables dans le dump, $LIVE dans la base"; exit 1; }
sha256sum "$SQL" > "$SQL.sha256"
# Comptages de référence (comparés après restauration d'essai)
mysql --defaults-extra-file="$CNF" -N -B "$DB" -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY 1" |
  while read -r t; do printf '%s\t%s\n' "$t" "$(mysql --defaults-extra-file="$CNF" -N -B "$DB" -e "SELECT COUNT(*) FROM \`$t\`")"; done > "$SQL.counts.tsv"
chmod 600 "$SQL.sha256" "$SQL.counts.tsv"

# S2 — fichiers (documents, bulletins, dépôts) + .env (secrets : 600, jamais affiché)
FILES="$BK/pre-rc2_storage-app_${TS}.tgz"
tar -czf "$FILES" -C "$APP/shared" storage/app
cp "$ENVF" "$BK/pre-rc2_env_${TS}"
chmod 600 "$FILES" "$BK/pre-rc2_env_${TS}"
tar -tzf "$FILES" > /dev/null
sha256sum "$FILES" > "$FILES.sha256"

echo "OK S1 $(basename "$SQL") ($(du -h "$SQL" | cut -f1)), $TABLES tables, dump complet"
echo "OK S2 $(basename "$FILES") ($(du -h "$FILES" | cut -f1)) + copie .env"
echo "Rapatrier (S4) : $SQL, $SQL.sha256, $SQL.counts.tsv, $FILES, $FILES.sha256 — puis vérifier les sha256 côté poste."
