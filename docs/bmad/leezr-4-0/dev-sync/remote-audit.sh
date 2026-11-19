#!/usr/bin/env bash
# Leezr 4.0 RC2 — audit du DEV distant (web3 / dev.leezr.com), STRICTEMENT EN LECTURE SEULE.
# À exécuter UNIQUEMENT après autorisation, sur le serveur, par le compte shell propre au site web3 (pas le compte
# CI partagé avec la production) :   bash remote-audit.sh > remote-audit-$(date -u +%Y%m%dT%H%M%SZ).txt 2>&1
#
# Garanties (vérifiées ligne à ligne, voir LEEZR-4-0-DEV-SYNC-PLAN.md §3) :
# - aucune écriture applicative, aucun job déclenché, aucune configuration modifiée, aucun service touché ;
# - SQL : SELECT uniquement, session MySQL en READ ONLY ;
# - aucun secret affiché : .env lu uniquement pour des clés non sensibles ou pour la PRÉSENCE d'une clé ;
#   les identifiants DB vont dans un fichier temporaire 600 supprimé à la sortie, jamais en argument de commande ;
# - artisan n'est appelé qu'avec des commandes de lecture (config:show sur des clés non secrètes, migrate:status,
#   db:table). Effet de bord possible : une ligne dans storage/logs si artisan journalise (aucune donnée métier).
set -u
APP="${APP:-/var/www/clients/client1/web3}"
PHP="${PHP:-/usr/bin/php8.4}"
CUR="$APP/current"
ENVF="$APP/shared/.env"
section() { printf '\n## %s\n' "$1"; }
envval() { grep -E "^$1=" "$ENVF" 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"; }
art() { (cd "$CUR" && "$PHP" artisan "$@" --no-ansi 2>&1); }
cfg() { printf '%-42s = ' "$1"; art config:show "$1" | grep -E "^[[:space:]]*$1[[:space:].]" | sed -E 's/^[[:space:]]*[^ ]+ \.+ //; s/[[:space:]]+$//' | head -1; echo; }

printf '# remote-audit %s on %s\n' "$(date -u +%FT%TZ)" "$(hostname)"

# ── R1 révision déployée ───────────────────────────────────────────
section "R1 révision"
readlink -f "$CUR"
grep -E '^APP_BUILD_VERSION=' "$ENVF" 2>/dev/null || echo "APP_BUILD_VERSION absent"
ls -1t "$APP/releases" 2>/dev/null | head -5

# ── R2 dernier déploiement (filtré : aucune ligne pouvant porter un secret) ──
section "R2 deploy.log"
tail -n 80 "$APP/shared/storage/logs/deploy.log" 2>/dev/null | grep -viE 'password|secret|token|key=|credential' | tail -n 40

# ── R3 environnement ───────────────────────────────────────────────
section "R3 environnement"
cfg app.env
cfg app.debug
cfg app.url

# ── R4 migrations ──────────────────────────────────────────────────
section "R4 migrations"
art migrate:status | grep -cE 'Pending' | sed 's/^/pending: /'
art migrate:status | grep -E '2026_10_30_150000|2026_11_23_000002' || echo "2026_10_30_150000 / 2026_11_23_000002 : non listées"

# ── R5 PHP ─────────────────────────────────────────────────────────
section "R5 PHP"
"$PHP" -v | head -1
"$PHP" -m | grep -iE '^(gd|imagick|zip|intl|bcmath|pdo_mysql|mbstring|exif|fileinfo|sodium|openssl|redis)$' | tr '\n' ' '; echo
command -v gs tesseract convert magick 2>/dev/null | tr '\n' ' '; echo

# ── R6 files ───────────────────────────────────────────────────────
section "R6 files et workers"
systemctl list-units 'leezr-queue-*' --all --no-pager 2>/dev/null | head -20
for u in $(systemctl list-unit-files 'leezr-queue-*' --no-legend 2>/dev/null | awk '{print $1}'); do
  printf '%s: ' "$u"; systemctl cat "$u" 2>/dev/null | grep -E '^ExecStart=' | sed 's/^ExecStart=//'; done
cfg queue.default

# ── R7 planificateur ───────────────────────────────────────────────
section "R7 planificateur"
crontab -l 2>/dev/null | grep -E 'schedule:run' || echo "aucune ligne schedule:run dans la crontab de $(whoami)"
grep -hE 'schedule:run' /etc/cron.d/* 2>/dev/null | grep -E "$APP|web3" || echo "aucune ligne schedule:run web3 lisible dans /etc/cron.d (cron ISPConfig : vérifier dans le panneau si besoin)"
systemctl list-units 'leezr-scheduler*' --all --no-pager 2>/dev/null | head -5
ls -1t "$APP/shared/storage/logs/scheduler" 2>/dev/null | head -5

# ── R8 PHP-FPM ─────────────────────────────────────────────────────
section "R8 PHP-FPM"
POOL=$(grep -lE "$APP" /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head -1)
echo "pool: ${POOL:-introuvable (droits de lecture ?)}"
[ -n "${POOL:-}" ] && grep -hE '^(pm|pm\.max_children|pm\.start_servers|pm\.max_spare_servers|request_terminate_timeout|php_admin_value\[(max_execution_time|memory_limit)\])' "$POOL"
(nproc 2>/dev/null || echo "nproc: n/a"); (free -m 2>/dev/null | head -2 || true)

# ── R9 Redis / cache ───────────────────────────────────────────────
section "R9 Redis et cache"
cfg database.redis.options.prefix
cfg database.redis.client
cfg cache.default
cfg cache.prefix
cfg session.driver

# ── R10 variables (PRÉSENCE seulement) ─────────────────────────────
section "R10 variables (présence)"
for k in APP_ENV APP_DEBUG QUEUE_CONNECTION CACHE_STORE SESSION_DRIVER REDIS_PREFIX REDIS_CLIENT REALTIME_DRIVER \
         REALTIME_TRANSPORT REALTIME_STREAM_TIMEOUT REALTIME_MAX_CONCURRENT_STREAMS BILLING_ASYNC_QUEUES_ENABLED \
         DOCUMENT_SCANNER JOBDOMAIN_VERSIONED_INIT MEMBERS_INVITATION_TTL_DAYS ONBOARDING_RETENTION_AUTO_APPLY \
         SIGNATURE_EXTERNAL_ENABLED LEEZR_SEED_TEST_ADMINS STRIPE_TEST_SECRET_KEY STRIPE_SECRET MAIL_MAILER; do
  grep -qE "^$k=" "$ENVF" 2>/dev/null && echo "$k: présent" || echo "$k: ABSENT"; done
# valeurs NON secrètes uniquement
for k in APP_ENV QUEUE_CONNECTION CACHE_STORE SESSION_DRIVER REALTIME_DRIVER BILLING_ASYNC_QUEUES_ENABLED DOCUMENT_SCANNER MAIL_MAILER; do
  echo "$k=$(envval "$k")"; done

# ── R11 stockage ───────────────────────────────────────────────────
section "R11 stockage"
df -h "$APP" | tail -1
du -sh "$APP/shared/storage" "$APP/releases" 2>/dev/null
ls -ld "$APP/shared/storage/app/private" 2>/dev/null

# ── R12 sauvegardes existantes ─────────────────────────────────────
section "R12 sauvegardes"
ls -lt "$APP/shared/backups" 2>/dev/null | head -5 || echo "aucun dossier shared/backups"

# ── R13 temps réel ─────────────────────────────────────────────────
section "R13 temps réel"
cfg realtime.driver
cfg realtime.transport
cfg realtime.stream_timeout

# ── SQL en lecture seule (R4 détaillé, R6 files, R14, références de recette) ──
section "SQL (lecture seule)"
CNF="$(mktemp "${TMPDIR:-/tmp}/leezr-ro.XXXXXX")"; chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$(envval DB_HOST)" "$(envval DB_PORT)" "$(envval DB_USERNAME)" "$(envval DB_PASSWORD)" > "$CNF"
DB="$(envval DB_DATABASE)"
q() { mysql --defaults-extra-file="$CNF" --init-command='SET SESSION TRANSACTION READ ONLY' -B "$DB" -e "$1" 2>&1; }
has() { q "SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$1'" | tail -1; }
echo "base: $DB"
echo "-- R4 migrations enregistrées"; q "SELECT COUNT(*) total, MAX(batch) last_batch FROM migrations"
q "SELECT migration, batch FROM migrations WHERE migration LIKE '2026_10_30_150000%' OR migration LIKE '2026_11_23_000002%'"
echo "-- R4 état de la migration modifiée (table / index / colonnes)"
q "SELECT COUNT(*) enrollments_table FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workforce_signature_enrollments'"
q "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workforce_signature_enrollments' GROUP BY INDEX_NAME"
q "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workforce_signature_signers' AND COLUMN_NAME IN ('authenticated_user_id','identity_assurance','reenrollment_count')"
echo "-- R6 files"; q "SELECT queue, COUNT(*) n FROM jobs GROUP BY queue"; q "SELECT queue, COUNT(*) n FROM failed_jobs GROUP BY queue"
echo "-- R14 demandes de signature échues (= ce que workforce:signature-expire traitera)"
q "SELECT COUNT(*) due FROM workforce_signature_requests WHERE status NOT IN ('completed','declined','cancelled','expired','failed') AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()"
echo "-- Référence de recette (comptages, aucune donnée personnelle)"
for t in companies memberships company_roles company_role_permission company_modules workforce_employees workforce_employment_contracts \
         workforce_shifts workforce_time_entries workforce_leave_requests workforce_payroll_runs member_documents company_documents \
         shipments invoices subscriptions company_payment_profiles plans jobdomains platform_modules markets document_types presets; do
  if [ "$(has "$t")" = "1" ]; then printf '%-34s %s\n' "$t" "$(q "SELECT COUNT(*) FROM \`$t\`" | tail -1)"; else printf '%-34s %s\n' "$t" "(table absente)"; fi; done
echo "-- Catalogue Platform susceptible d'avoir été édité (comparé au code par le clone, LOT 3)"
q "SELECT \`key\`, price_monthly, price_yearly, trial_days, updated_at FROM plans ORDER BY \`key\`"
q "SELECT \`key\`, updated_at, LEFT(SHA2(default_roles,256),16) roles_sha, LEFT(SHA2(default_modules,256),16) modules_sha FROM jobdomains ORDER BY \`key\`"
q "SELECT \`key\`, vat_rate_bps, is_active, updated_at FROM markets ORDER BY \`key\`"
[ "$(has presets)" = "1" ] && q "SELECT family_key, version, status FROM presets WHERE status='published' ORDER BY family_key"
printf '\n# fin remote-audit\n'
