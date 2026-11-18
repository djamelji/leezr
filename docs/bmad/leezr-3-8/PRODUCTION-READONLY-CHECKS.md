# Leezr 3.8 — Vérifications de production en lecture seule (D-10, D-11)

> **Non exécutées.** Aucun accès en lecture seule à la production n'a été autorisé pour cette mission. Les vérifications de production passées exigeaient soit une connexion SSH (identité partagée DEV/PROD), soit un workflow GitHub Actions, donc un push, interdit. Les commandes ci-dessous sont prêtes à être lancées par une personne habilitée ou après autorisation explicite.

Toutes les commandes sont en **lecture seule**. Elles n'ouvrent aucune transaction et n'affichent aucun secret : les valeurs de `.env` ne sont lues que pour des clés non sensibles, et l'existence des autres clés est vérifiée sans leur valeur.

Variables d'exemple : `APP=/var/www/clients/client1/web2` (production), `PHP=/usr/bin/php8.4`.

## 1. Révision déployée

```sh
readlink -f $APP/current                                  # release active (horodatage_sha)
grep -E '^APP_BUILD_VERSION=' $APP/shared/.env            # non secret
ls -1t $APP/releases | head -3                            # releases conservées
```

Attendu, d'après les notes du 2026-11-08 : `7d49e78`. Les références git locales ne prouvent rien.

## 2. Migrations et schéma

```sh
cd $APP/current && $PHP artisan migrate:status --no-ansi | grep -E 'Pending' | wc -l
$PHP artisan migrate:status --no-ansi | grep -E '2026_10_30_150000|2026_11_23_000002'
```

```sql
-- client SQL en lecture seule (utilisateur sans droit d'écriture si possible), base c1leezr
SELECT migration, batch FROM migrations WHERE migration LIKE '2026_10_30_150000%' OR migration LIKE '2026_11_23_000002%';
SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workforce_signature_enrollments';
SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workforce_signature_enrollments' GROUP BY INDEX_NAME;
SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workforce_signature_signers'
 AND COLUMN_NAME IN ('authenticated_user_id','identity_assurance','reenrollment_count');
```

Interprétation : voir le rapport 3.8, §9. Les trois états possibles convergent ; ils ont été rejoués sur des clones.

## 3. Planificateur

```sh
crontab -l -u web2 2>/dev/null | grep schedule:run
grep -h schedule:run /etc/cron.d/ispconfig /etc/cron.d/* 2>/dev/null
systemctl list-units 'leezr-scheduler*' --all --no-pager        # attendu : aucune (ADR-739)
tail -n 3 $APP/shared/storage/logs/scheduler/billing-renew.log
```

## 4. Workers et files

```sh
systemctl list-units 'leezr-queue-*' --all --no-pager
systemctl cat leezr-queue-default-web2 leezr-queue-ai-web2 | grep ExecStart
cd $APP/current && $PHP artisan config:show queue.default
$PHP artisan queue:monitor default,ai,billing,billing-slow            # lecture
```

```sql
SELECT queue, COUNT(*) FROM jobs GROUP BY queue;
SELECT queue, COUNT(*) FROM failed_jobs GROUP BY queue;
```

## 5. Redis (espaces de noms)

```sh
cd $APP/current && $PHP artisan config:show database.redis.options.prefix
$PHP artisan config:show cache.default ; $PHP artisan config:show realtime.redis_prefix
# comparer avec web3 (DEV VPS) : préfixes DIFFÉRENTS exigés si la même instance Redis est partagée
```

## 6. PHP-FPM (dimensionnement SSE, D-10)

```sh
ls /etc/php/8.4/fpm/pool.d/
grep -hE '^(pm|pm\.max_children|pm\.start_servers|pm\.max_spare_servers|request_terminate_timeout|php_admin_value\[(max_execution_time|memory_limit)\]|php_value\[max_execution_time\])' /etc/php/8.4/fpm/pool.d/web2.conf
ps -o rss= -C php-fpm8.4 | awk '{s+=$1;n++} END {print n" workers, RSS moyen "int(s/n/1024)" Mo"}'
free -m ; nproc
```

## 7. Serveur web et proxy (compatibilité SSE)

```sh
apache2ctl -S 2>/dev/null | grep -i leezr                     # ISPConfig : Apache
grep -hiE 'proxy_fcgi|SetHandler|flushpackets|Timeout|ProxyTimeout|FcgidIOTimeout|deflate' /etc/apache2/sites-enabled/*web2* 2>/dev/null
nginx -T 2>/dev/null | grep -iE 'fastcgi_read_timeout|fastcgi_buffering|proxy_read_timeout|proxy_buffering'
```

Point critique : derrière Apache et `mod_proxy_fcgi`, un flux SSE n'est transmis au fil de l'eau que si la sortie n'est pas tamponnée (`flushpackets=on`) et pas compressée (`mod_deflate`). Sinon, les événements arrivent par paquets ou à la fin du flux. **Ce point n'a pas été vérifié** : à contrôler avant d'activer `REALTIME_DRIVER=sse` en production.

## 8. Variables d'environnement requises (présence seulement)

```sh
for k in APP_ENV QUEUE_CONNECTION CACHE_STORE SESSION_DRIVER REDIS_PREFIX REALTIME_DRIVER REALTIME_TRANSPORT \
         REALTIME_STREAM_TIMEOUT REALTIME_HEARTBEAT_INTERVAL REALTIME_MAX_CONCURRENT_STREAMS BILLING_ASYNC_QUEUES_ENABLED; do
  grep -qE "^$k=" $APP/shared/.env && echo "$k: présent" || echo "$k: ABSENT"; done
cd $APP/current && for k in app.env queue.default cache.default realtime.driver realtime.stream_timeout realtime.max_concurrent_streams billing.async_queues_enabled; do
  echo "$k = $($PHP artisan config:show $k 2>/dev/null | tail -1)"; done   # valeurs non secrètes
```

## 9. Presets publiés

```sql
SELECT family_key, version, status, published_at FROM presets WHERE status = 'published' ORDER BY family_key;
SELECT status, COUNT(*) FROM presets GROUP BY status;
```

Référence DEV (2026-11-18, lecture seule) : 4 presets publiés (`logistique_catalog` v2, `logistique_planning` v1, `logistique_rbac` v4, `logistique_requirements` v1), 4 brouillons, 3 archivés.
