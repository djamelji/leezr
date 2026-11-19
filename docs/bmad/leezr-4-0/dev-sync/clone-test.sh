#!/usr/bin/env bash
# Leezr 4.0 RC2 — S3 : restauration d'essai + test des migrations + test de SystemSeeder sur un CLONE local du DEV
# distant. Exécuté sur le POSTE (jamais sur le serveur), depuis la racine du dépôt au SHA à déployer (dev = RC2).
#
#   bash docs/bmad/leezr-4-0/dev-sync/clone-test.sh <dump.sql.gz> <mysql-option-file> [clone_name]
#
# - le dump vient de remote-backup.sh (S1) ; son .sha256 et son .counts.tsv doivent être à côté ;
# - la base créée est un clone MARQUÉ (_recipe_clone_identity), nom leezr_clone_devremote_* ; elle n'est jamais
#   supprimée par ce script ;
# - toutes les commandes artisan visent le clone (DB_DATABASE), APP_ENV=production comme le DEV distant (à ajuster
#   si R3 dit autre chose), cache/mail en mémoire, files sur la base du clone (jamais exécutées), temps réel coupé.
# Sortie : un dossier de preuves <clone>/ (schéma, comptages, diffs) et un verdict par étape.
set -euo pipefail
DUMP="$1"; CNF="$2"; CLONE="${3:-leezr_clone_devremote_$(date -u +%Y%m%d%H%M)}"
[[ "$CLONE" =~ ^leezr_(clone|audit|recipe)[a-z0-9_]*$ ]] || { echo "nom de clone refusé : $CLONE"; exit 2; }
OUT="storage/app/recipe-clones/$CLONE"; mkdir -p "$OUT"
REFDB="${REFDB:-leezr}"   # base de référence au schéma RC2 (DEV local), LUE seulement
my() { mysql --defaults-extra-file="$CNF" -N -B "$@"; }
export DB_DATABASE="$CLONE" REDIS_PREFIX="${CLONE}-" CACHE_STORE=array MAIL_MAILER=array QUEUE_CONNECTION=database \
       REALTIME_DRIVER=null APP_ENV="${CLONE_APP_ENV:-production}" BILLING_ASYNC_QUEUES_ENABLED=false
art() { php artisan "$@" --no-ansi; }
step() { printf '\n== %s\n' "$1" | tee -a "$OUT/verdict.txt"; }
ok() { echo "  OK  $*" | tee -a "$OUT/verdict.txt"; }
ko() { echo "  KO  $*" | tee -a "$OUT/verdict.txt"; FAIL=1; }
FAIL=0
schema() { my -e "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$1' AND TABLE_NAME<>'_recipe_clone_identity' ORDER BY 1,2"; }
indexes() { my -e "SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$1' AND TABLE_NAME<>'_recipe_clone_identity' GROUP BY 1,2,4 ORDER BY 1,2"; }
counts() { my -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$CLONE' AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'_recipe_clone_identity' ORDER BY 1" | while read -r t; do printf '%s\t%s\n' "$t" "$(my "$CLONE" -e "SELECT COUNT(*) FROM \`$t\`")"; done; }
checksums() { my "$CLONE" -e "CHECKSUM TABLE $(my -e "SET SESSION group_concat_max_len=1000000; SELECT GROUP_CONCAT(CONCAT('\`',TABLE_NAME,'\`')) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$CLONE' AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'_recipe_clone_identity'")" | sed "s/^$CLONE\.//"; }
CATALOG="plans jobdomains platform_modules markets legal_statuses languages company_permissions platform_permissions platform_roles platform_role_permission platform_payment_modules platform_payment_method_rules field_definitions document_types module_contributions requirements market_rule_sets convention_collectives convention_collective_rules document_templates notification_topics platform_settings presets"
dumprows() { for t in $CATALOG; do if [ "$(my -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$CLONE' AND TABLE_NAME='$t'")" = 1 ]; then cols=$(my -e "SELECT GROUP_CONCAT(CONCAT('\`',COLUMN_NAME,'\`') ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$CLONE' AND TABLE_NAME='$t' AND COLUMN_NAME NOT IN ('created_at','updated_at')"); my "$CLONE" -e "SELECT $cols FROM \`$t\` ORDER BY 1" | sed "s/^/$t\t/"; fi; done; }  # timestamps excluded: only VALUE changes count

step "1. Intégrité du dump"
(cd "$(dirname "$DUMP")" && shasum -a 256 -c "$(basename "$DUMP").sha256") && ok "sha256" || ko "sha256"
gzip -t "$DUMP" && ok "gzip" || ko "gzip"

step "2. Restauration d'essai dans $CLONE (clone marqué)"
[ "$(my -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$CLONE'")" = 0 ] || { ko "$CLONE existe déjà (jamais écrasé)"; exit 1; }
my -e "CREATE DATABASE \`$CLONE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
# GTID / binlog statements of the source server are not replayed (MySQL GTID dumps would fail elsewhere)
gzip -dc "$DUMP" | grep -vE 'GTID_PURGED|SQL_LOG_BIN' | mysql --defaults-extra-file="$CNF" "$CLONE"
my "$CLONE" -e "CREATE TABLE _recipe_clone_identity (database_name VARCHAR(64) NOT NULL PRIMARY KEY, source_database VARCHAR(64) NOT NULL, source_dump_sha256 CHAR(64) NOT NULL, created_at DATETIME NOT NULL); INSERT INTO _recipe_clone_identity VALUES ('$CLONE', 'c1leezrdev', '$(cut -d' ' -f1 "$DUMP.sha256")', UTC_TIMESTAMP())"
counts > "$OUT/counts-restored.tsv"
if [ -f "$DUMP.counts.tsv" ]; then diff <(sort "$DUMP.counts.tsv") <(sort "$OUT/counts-restored.tsv") > "$OUT/restore-diff.txt" && ok "comptages identiques à la source ($(wc -l < "$OUT/counts-restored.tsv") tables)" || ko "écart de comptage après restauration (restore-diff.txt)"; else ko "counts.tsv absent : restauration non comparée"; fi
APP_ENV=local art recipe:status > "$OUT/recipe-status.json" && ok "clone marqué reconnu par la garde" || ko "garde de recette : clone non reconnu"

step "3. Migrations RC2 sur le clone"
art migrate:status > "$OUT/migrate-status-before.txt" || true
echo "  en attente : $(grep -c Pending "$OUT/migrate-status-before.txt" || true)" | tee -a "$OUT/verdict.txt"
counts > "$OUT/counts-before.tsv"
if art migrate --force > "$OUT/migrate.log" 2>&1; then ok "migrate"; else ko "migrate (migrate.log)"; fi
[ "$(art migrate:status | grep -c Pending || true)" = 0 ] && ok "0 migration en attente" || ko "migrations encore en attente"

step "4. Schéma et données après migration"
diff <(schema "$REFDB") <(schema "$CLONE") > "$OUT/schema-diff.txt" && ok "colonnes identiques à $REFDB" || ko "écart de colonnes (schema-diff.txt)"
diff <(indexes "$REFDB") <(indexes "$CLONE") > "$OUT/index-diff.txt" && ok "index identiques à $REFDB" || ko "écart d'index (index-diff.txt)"
counts > "$OUT/counts-after-migrate.tsv"
join -t$'\t' <(sort "$OUT/counts-before.tsv") <(sort "$OUT/counts-after-migrate.tsv") | awk -F'\t' '$2>$3{print $1": "$2" -> "$3}' > "$OUT/rows-lost.txt"
[ ! -s "$OUT/rows-lost.txt" ] && ok "aucune table n'a perdu de lignes" || ko "lignes perdues (rows-lost.txt)"

step "5. SystemSeeder (catalogue Platform) — différences exactes"
checksums > "$OUT/checksum-before-seeder.tsv"; dumprows > "$OUT/catalog-before.tsv"
if art db:seed --class=SystemSeeder --force > "$OUT/systemseeder.log" 2>&1; then ok "SystemSeeder"; else ko "SystemSeeder (systemseeder.log)"; fi
checksums > "$OUT/checksum-after-seeder.tsv"; dumprows > "$OUT/catalog-after.tsv"
diff "$OUT/checksum-before-seeder.tsv" "$OUT/checksum-after-seeder.tsv" | grep '^[<>]' | awk '{print $2}' | sort -u > "$OUT/tables-changed-by-seeder.txt" || true
diff "$OUT/catalog-before.tsv" "$OUT/catalog-after.tsv" > "$OUT/catalog-diff.txt" || true
echo "  tables touchées (checksum) : $(tr '\n' ' ' < "$OUT/tables-changed-by-seeder.txt")" | tee -a "$OUT/verdict.txt"
echo "  catalogue — valeurs modifiées (hors horodatage) : $(grep -E '^[<>] ' "$OUT/catalog-diff.txt" | awk -F'\t' '{sub(/^[<>] /,"",$1); print $1}' | sort | uniq -c | tr '\n' ' ')" | tee -a "$OUT/verdict.txt"
CATRX="^($(echo $CATALOG | tr ' ' '|')|jobs|cache|cache_locks|sessions|migrations)$"
grep -vE "$CATRX" "$OUT/tables-changed-by-seeder.txt" > "$OUT/non-catalog-changed.txt" || true
[ ! -s "$OUT/non-catalog-changed.txt" ] && ok "aucune table hors catalogue modifiée (données des sociétés intactes)" || ko "tables hors catalogue modifiées : $(tr '\n' ' ' < "$OUT/non-catalog-changed.txt")"
for t in plans markets market_rule_sets convention_collective_rules platform_role_permission platform_payment_method_rules; do
  if grep -qE "^< $t	" "$OUT/catalog-diff.txt"; then ko "$t : valeur existante modifiée ou retirée par SystemSeeder (édition Platform remise aux valeurs du code ? à arbitrer)"
  elif grep -qE "^> $t	" "$OUT/catalog-diff.txt"; then ok "$t : ajouts seulement ($(grep -cE "^> $t	" "$OUT/catalog-diff.txt"))"
  else ok "$t inchangé"; fi; done

for t in jobdomains platform_modules company_permissions field_definitions document_types; do
  grep -qE "^< $t	" "$OUT/catalog-diff.txt" && echo "  ARB $t : valeur(s) existante(s) remplacée(s) par le code — détail catalog-diff.txt ; décision AVANT SystemSeeder distant" | tee -a "$OUT/verdict.txt"; done

step "6. Tâches planifiées nouvelles — effet prévisible"
art workforce:signature-expire --dry-run | tee -a "$OUT/verdict.txt"

step "Verdict"
[ "$FAIL" = 0 ] && echo "  CLONE VERT — preuves : $OUT" | tee -a "$OUT/verdict.txt" || echo "  CLONE ROUGE — ne pas pousser ; analyser $OUT" | tee -a "$OUT/verdict.txt"
exit "$FAIL"
