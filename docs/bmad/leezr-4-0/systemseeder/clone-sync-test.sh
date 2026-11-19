#!/usr/bin/env bash
# ADR-832 — recette de la synchronisation système sur un CLONE marqué (jamais sur une base réelle).
#
#   bash docs/bmad/leezr-4-0/systemseeder/clone-sync-test.sh <clone> <mysql-option-file> <out-dir> [artisan-dir]
#
# 1. migre le clone ; 2. applique des décisions Platform FICTIVES (prix, TVA, JobDomain, rôle personnalisé, retrait
# d'une permission du rôle admin, désactivation d'un module) ; 3. relève l'empreinte (CHECKSUM TABLE) de toutes les
# tables ; 4. lance la synchronisation (system:sync, ou db:seed SystemSeeder si artisan-dir pointe vers une ancienne
# version) ; 5. vérifie valeurs administrées, nouveaux éléments, presets, données Company ; 6. seconde exécution.
# artisan-dir permet de rejouer l'ANCIEN comportement (worktree au commit précédent) sur un second clone.
set -euo pipefail
CLONE="$1"; CNF="$2"; OUT="$3"; ART_DIR="${4:-$PWD}"
[[ "$CLONE" =~ ^leezr_(clone|audit|recipe)[a-z0-9_]*$ ]] || { echo "nom de clone refusé : $CLONE"; exit 2; }
mkdir -p "$OUT"
my() { mysql --defaults-extra-file="$CNF" -N -B "$CLONE" "$@"; }
[ "$(my -e "SELECT database_name FROM _recipe_clone_identity LIMIT 1")" = "$CLONE" ] || { echo "clone non marqué : refus"; exit 2; }
export DB_DATABASE="$CLONE" REDIS_PREFIX="${CLONE}-" CACHE_STORE=array MAIL_MAILER=array QUEUE_CONNECTION=database \
       REALTIME_DRIVER=null APP_ENV=production BILLING_ASYNC_QUEUES_ENABLED=false LOG_CHANNEL=stderr
art() { (cd "$ART_DIR" && php artisan "$@" --no-ansi); }
NEW=0; [ "$ART_DIR" = "$PWD" ] && NEW=1
checksums() { my -e "CHECKSUM TABLE $(my -e "SET SESSION group_concat_max_len=1000000; SELECT GROUP_CONCAT(CONCAT('\`',TABLE_NAME,'\`') ORDER BY TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")" | sed "s/^$CLONE\.//" | sort; }
FAIL=0; ok() { echo "  OK  $*" | tee -a "$OUT/verdict.txt"; }; ko() { echo "  KO  $*" | tee -a "$OUT/verdict.txt"; FAIL=1; }
: > "$OUT/verdict.txt"

art migrate --force > "$OUT/migrate.log" 2>&1

# ── 2. décisions Platform fictives (sur le clone uniquement) ─────────────────────────────────────────────
PLAN=$(my -e "SELECT \`key\` FROM plans ORDER BY level DESC LIMIT 1")
LS=$(my -e "SELECT id FROM legal_statuses WHERE is_vat_applicable=1 ORDER BY id LIMIT 1")
MOD=$(my -e "SELECT pm.\`key\` FROM platform_modules pm WHERE pm.is_enabled_globally=1 AND NOT EXISTS (SELECT 1 FROM company_modules cm WHERE cm.module_key=pm.\`key\`) ORDER BY pm.id LIMIT 1")
ADMIN=$(my -e "SELECT id FROM platform_roles WHERE \`key\`='admin'")
RMPERM=$(my -e "SELECT p.id FROM platform_permissions p JOIN platform_role_permission rp ON rp.platform_permission_id=p.id WHERE rp.platform_role_id=$ADMIN ORDER BY p.id LIMIT 1")
my -e "UPDATE plans SET price_monthly=424200, price_yearly=4242000, trial_days=21 WHERE \`key\`='$PLAN';
       UPDATE legal_statuses SET vat_rate=5.50 WHERE id=$LS;
       UPDATE markets SET vat_rate_bps=1999 WHERE \`key\`='FR';
       UPDATE jobdomains SET label='Logistique (Platform)', default_modules=JSON_ARRAY('core.members') WHERE \`key\`='logistique';
       UPDATE platform_modules SET is_enabled_globally=0 WHERE \`key\`='$MOD';
       UPDATE notification_topics SET label='Libellé Platform 832' ORDER BY sort_order LIMIT 1;
       UPDATE platform_payment_method_rules SET is_active=1, priority=7 WHERE method_key='manual' AND provider_key='internal';
       DELETE FROM platform_role_permission WHERE platform_role_id=$ADMIN AND platform_permission_id=$RMPERM;
       INSERT INTO platform_roles (\`key\`, name, created_at, updated_at) VALUES ('recette_832', 'Rôle recette 832', NOW(), NOW());
       INSERT INTO platform_role_permission (platform_role_id, platform_permission_id) SELECT (SELECT id FROM platform_roles WHERE \`key\`='recette_832'), id FROM platform_permissions ORDER BY id LIMIT 2;
       UPDATE platform_roles SET name='Administrateurs (Platform)' WHERE id=$ADMIN;"
admin_values() { my -e "SELECT CONCAT('plan ',price_monthly,'/',price_yearly,'/',trial_days) FROM plans WHERE \`key\`='$PLAN';
  SELECT CONCAT('legal_status_vat ',vat_rate) FROM legal_statuses WHERE id=$LS;
  SELECT CONCAT('market_vat_bps ',vat_rate_bps) FROM markets WHERE \`key\`='FR';
  SELECT CONCAT('jobdomain ',label,' ',default_modules) FROM jobdomains WHERE \`key\`='logistique';
  SELECT CONCAT('module_disabled ',\`key\`,'=',is_enabled_globally) FROM platform_modules WHERE \`key\`='$MOD';
  SELECT CONCAT('manual_rule ',is_active,'/',priority) FROM platform_payment_method_rules WHERE method_key='manual' AND provider_key='internal';
  SELECT CONCAT('admin_has_removed_perm ',COUNT(*)) FROM platform_role_permission WHERE platform_role_id=$ADMIN AND platform_permission_id=$RMPERM;
  SELECT CONCAT('admin_name ',name) FROM platform_roles WHERE id=$ADMIN;
  SELECT CONCAT('custom_role_perms ',GROUP_CONCAT(platform_permission_id ORDER BY platform_permission_id)) FROM platform_role_permission WHERE platform_role_id=(SELECT id FROM platform_roles WHERE \`key\`='recette_832');
  SELECT CONCAT('topic_label ',COUNT(*)) FROM notification_topics WHERE label='Libellé Platform 832';"; }
admin_values > "$OUT/administered-before.txt"
my -e "SELECT \`key\` FROM platform_modules ORDER BY 1" > "$OUT/modules-before.txt"
my -e "SELECT \`key\` FROM platform_permissions ORDER BY 1" > "$OUT/platform-perms-before.txt"
my -e "SELECT \`key\` FROM company_permissions ORDER BY 1" > "$OUT/company-perms-before.txt"
checksums > "$OUT/checksum-before.tsv"

# ── 4. synchronisation n°1 ─────────────────────────────────────────────────────────────────────────────
if [ "$NEW" = 1 ]; then art system:sync --force --json="$PWD/$OUT/run1.json" > "$OUT/run1.log" 2>&1
else art db:seed --class=SystemSeeder --force > "$OUT/run1.log" 2>&1; fi
checksums > "$OUT/checksum-after1.tsv"
admin_values > "$OUT/administered-after1.txt"
my -e "SELECT \`key\` FROM platform_modules ORDER BY 1" > "$OUT/modules-after1.txt"
my -e "SELECT \`key\` FROM platform_permissions ORDER BY 1" > "$OUT/platform-perms-after1.txt"
my -e "SELECT \`key\` FROM company_permissions ORDER BY 1" > "$OUT/company-perms-after1.txt"
join -t$'\t' "$OUT/checksum-before.tsv" "$OUT/checksum-after1.tsv" | awk -F'\t' '$2!=$3{print $1}' > "$OUT/tables-changed-run1.txt"

echo "== Valeurs administrées (avant → après synchronisation 1)" | tee -a "$OUT/verdict.txt"
diff "$OUT/administered-before.txt" "$OUT/administered-after1.txt" > "$OUT/administered-diff.txt" && ok "toutes les valeurs administrées fictives sont identiques" || ko "valeurs administrées modifiées : $(grep -c '^<' "$OUT/administered-diff.txt") (administered-diff.txt)"
echo "== Nouveaux éléments techniques" | tee -a "$OUT/verdict.txt"
echo "  modules ajoutés : $(comm -13 "$OUT/modules-before.txt" "$OUT/modules-after1.txt" | tr '\n' ' ')" | tee -a "$OUT/verdict.txt"
echo "  permissions Platform ajoutées : $(comm -13 "$OUT/platform-perms-before.txt" "$OUT/platform-perms-after1.txt" | tr '\n' ' ')" | tee -a "$OUT/verdict.txt"
echo "  permissions Company ajoutées : $(comm -13 "$OUT/company-perms-before.txt" "$OUT/company-perms-after1.txt" | tr '\n' ' ')" | tee -a "$OUT/verdict.txt"
for k in $(comm -13 "$OUT/platform-perms-before.txt" "$OUT/platform-perms-after1.txt"); do
  [ "$(my -e "SELECT COUNT(*) FROM platform_role_permission rp JOIN platform_permissions p ON p.id=rp.platform_permission_id WHERE rp.platform_role_id=$ADMIN AND p.\`key\`='$k'")" = 1 ] && ok "nouvelle permission $k offerte au rôle admin" || ko "nouvelle permission $k absente du rôle admin"; done
echo "== Tables modifiées par la synchronisation 1 : $(tr '\n' ' ' < "$OUT/tables-changed-run1.txt")" | tee -a "$OUT/verdict.txt"
CAT='^(plans|platform_modules|jobdomains|markets|legal_statuses|languages|market_language|company_permissions|platform_permissions|platform_roles|platform_role_permission|notification_topics|field_definitions|document_types|module_contributions|requirements|market_rule_sets|convention_collectives|convention_collective_rules|document_templates|platform_payment_modules|platform_payment_method_rules|platform_settings|platform_font_families|platform_users|platform_user_roles|platform_role_user|system_sync_baselines|migrations|jobs|cache|cache_locks|sessions)$'
grep -vE "$CAT" "$OUT/tables-changed-run1.txt" > "$OUT/non-catalog-changed.txt" || true
[ ! -s "$OUT/non-catalog-changed.txt" ] && ok "aucune donnée Company / historique modifiée" || ko "tables hors catalogue modifiées : $(tr '\n' ' ' < "$OUT/non-catalog-changed.txt")"
grep -qx presets "$OUT/tables-changed-run1.txt" && ko "presets modifiés" || ok "presets publiés intacts (checksum identique)"

# ── 6. synchronisation n°2 : idempotence ─────────────────────────────────────────────────────────────
if [ "$NEW" = 1 ]; then art system:sync --force --json="$PWD/$OUT/run2.json" > "$OUT/run2.log" 2>&1
else art db:seed --class=SystemSeeder --force > "$OUT/run2.log" 2>&1; fi
checksums > "$OUT/checksum-after2.tsv"
join -t$'\t' "$OUT/checksum-after1.tsv" "$OUT/checksum-after2.tsv" | awk -F'\t' '$2!=$3{print $1}' | grep -vE '^(jobs|cache|cache_locks|sessions)$' > "$OUT/tables-changed-run2.txt" || true
echo "== Synchronisation 2" | tee -a "$OUT/verdict.txt"
[ ! -s "$OUT/tables-changed-run2.txt" ] && ok "seconde exécution : aucune table modifiée" || ko "seconde exécution : $(tr '\n' ' ' < "$OUT/tables-changed-run2.txt")"
[ "$FAIL" = 0 ] && echo "  CLONE VERT — preuves : $OUT" | tee -a "$OUT/verdict.txt" || echo "  CLONE ROUGE — $OUT" | tee -a "$OUT/verdict.txt"
exit "$FAIL"
