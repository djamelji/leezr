# Leezr 3.9 — Runbook de pré-déploiement (ADR-829)

> Remplace et complète `docs/bmad/leezr-3-7/PRE-DEPLOYMENT-RUNBOOK.md`. **Rien n'a été vérifié sur la production** :
> aucun accès en lecture seule n'a été autorisé (D-11). Les commandes de lecture seule sont dans
> `docs/bmad/leezr-3-8/PRODUCTION-READONLY-CHECKS.md` (conservé tel quel) ; ce runbook dit **quoi** vérifier, **dans
> quel ordre**, et **ce qui bloque**. Aucune branche locale (`main`, `origin/main`) ne prouve ce qui est déployé.

Légende : **B** = bloquant (pas de déploiement sans) ; **C** = à constater et consigner ; **D** = décision humaine.

## 1. Avant le déploiement

| # | Sujet | Vérification | Attendu | Type |
|---|---|---|---|---|
| 1.1 | Révision déployée | `readlink -f current`, `APP_BUILD_VERSION` du `.env` partagé (checks §1) | Une révision connue ; consignée comme base de comparaison | B |
| 1.2 | Écart de code | `git log --oneline <révision prod>..<révision candidate>` sur un clone à jour **autorisé** | Liste des commits et migrations à livrer (local : `main..dev` = 206 commits, 45 fichiers de migration — indicatif seulement) | C |
| 1.3 | Migrations | `migrate:status` (checks §2) ; état de `2026_10_30_150000` et `2026_11_23_000002` | Trois états possibles, tous convergents (rejoués sur clones en 3.8) | B |
| 1.4 | Sauvegarde | Assurée par `deploy_release.sh` [4.9] : `mysqldump` vérifié (CREATE TABLE + « Dump completed »), abandon **avant** migration en cas d'échec | Fichier `shared/backups/pre-deploy_*.sql.gz` présent | B |
| 1.5 | Doublons bloquant une contrainte | `deploy_release.sh` [4.95] (`workforce_timesheet_periods`) | 0 doublon, sinon abandon | B |
| 1.6 | Variables d'environnement | présence seulement (checks §8) : `APP_ENV`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `REDIS_PREFIX`, `REALTIME_DRIVER`, `REALTIME_STREAM_TIMEOUT`, `REALTIME_MAX_CONCURRENT_STREAMS`, `BILLING_ASYNC_QUEUES_ENABLED` | `BILLING_ASYNC_QUEUES_ENABLED` absent ou `false` (D-17) | B |
| 1.7 | Presets publiés | requêtes checks §9 | Liste consignée ; comparée à la référence DEV (4 publiés) ; aucune publication automatique au déploiement | C |
| 1.8 | Redis | préfixes (checks §5) | PROD et DEV VPS : `REDIS_PREFIX` **différents** si la même instance est partagée | B |

## 2. Planificateur et workers

| # | Sujet | Vérification | Attendu | Type |
|---|---|---|---|---|
| 2.1 | Source unique du planificateur | cron ISPConfig du site (checks §3) ; aucune unité `leezr-scheduler*` | Exactement une source par environnement (ADR-739) | B |
| 2.2 | Chevauchements | 30 tâches `withoutOverlapping()` ; verrous dans le cache de l'environnement | `CACHE_STORE` propre à l'environnement | C |
| 2.3 | Nouvelles tâches planifiées | `onboarding:*`, `referrals:purge`, `documents:*`, `leave:*` | Activation décidée explicitement (elles modifient des données à échéance) | D |
| 2.4 | Workers | `leezr-queue-default-<site>`, `leezr-queue-ai-<site>` (checks §4) | Namespacés par site (ADR-738), `--max-jobs=100 --max-time=3600` | B |
| 2.5 | Isolation des files | `QUEUE_CONNECTION=database` → table `jobs` de chaque base | Aucun worker ne consomme la base d'un autre environnement | B |
| 2.6 | Files `billing` / `billing-slow` | **aucun consommateur, volontairement** ; `--async` refusé (ADR-828) | Aucun job en attente sur ces files ; `billing.async_queues_enabled = false` | B |
| 2.7 | Jobs historiques | `SELECT queue, COUNT(*) FROM jobs GROUP BY queue` | Consignés ; **jamais** lancés en masse au déploiement | C |

## 3. Serveur web, PHP-FPM et temps réel

Mesure (bancs isolés 3.7 → 3.9) : **un flux SSE ouvert occupe un worker PHP-FPM pendant toute sa vie**.

| # | Sujet | Vérification | Attendu | Type |
|---|---|---|---|---|
| 3.1 | Pool PHP-FPM | `pm`, `pm.max_children`, `request_terminate_timeout`, `php_admin_value[max_execution_time]` (checks §6) | Valeurs consignées : elles conditionnent 3.3 | B |
| 3.2 | Apache / proxy | `mod_proxy_fcgi` `flushpackets=on`, pas de `mod_deflate` sur `/api/realtime/stream`, `ProxyTimeout` ≥ `REALTIME_STREAM_TIMEOUT` (checks §7) | Flux transmis au fil de l'eau | B si SSE activé |
| 3.3 | Plafond des flux | `REALTIME_MAX_CONCURRENT_STREAMS` = `pm.max_children` − réserve pour les requêtes ordinaires (au moins la moitié du pool) | **Pas de valeur sans 3.1** ; la valeur DEV (1) n'est **pas** une valeur de production | D-10 |
| 3.4 | Durée d'un flux | `REALTIME_STREAM_TIMEOUT` < `max_execution_time` verrouillé (le flux s'arrête seul 5 s avant, ADR-827) | Pas de flux tué par PHP | C |
| 3.5 | **Mode dégradé** | Si 3.1 ou 3.2 ne sont pas établis : `REALTIME_DRIVER=null` | Le serveur répond **204** au client SSE, aucun worker occupé ; le client passe au polling (30 s) et ne sonde qu'avec un backoff borné (30 s → 5 min) — ADR-829 | Repli par défaut |
| 3.6 | Statuts | Sans session : **401 JSON** ; autre société : **403** ; plafond : **429** | Jamais de page HTML pour `/api/*` (ADR-829) | B |

## 4. Données et sujets historiques (hors déploiement)

| # | Sujet | État | Type |
|---|---|---|---|
| 4.1 | Incident paie société 1 (DEV local) | Outil validé `docs/bmad/leezr-3-7/incident/restore-company1-april2026.php` ; **non exécuté** ; ne concerne pas la production | D-13 |
| 4.2 | Rôles chauffeur historiques | Simulation seulement ; vague 1 = sociétés 2, 3, 31–34 ; 1 et 101 exclues | D-8 |
| 4.3 | Relances différées | Défaut démontré, correctif **préparé** (`leezr-3-9/billing/`), non appliqué ; `--async` verrouillé | D-17 |

## 5. Déploiement

1. Points **B** des sections 1 à 3 constatés et consignés (sinon : pas de déploiement).
2. `deploy.yml` (build CI, release atomique) → `deploy_release.sh` : sauvegarde vérifiée [4.9], pré-check [4.95], `migrate --force` [5/9], workers [6–6.7], caches [7/9], bascule du lien `current`.
3. Aucun `migrate:fresh`, aucun seeder de démo, aucun `--async` billing.

## 6. Retour arrière

- **Code** : `deploy/rollback.sh <app_path>` (ou `<release_id>`) — bascule du lien uniquement ; ne touche ni `.env`, ni base, ni release.
- **Base** : les migrations livrées sont additives ; pas de `migrate:rollback` automatique. En cas de corruption : restauration du `pre-deploy_*.sql.gz` du déploiement, **décision humaine** (perte des écritures postérieures).
- **Temps réel** : `REALTIME_DRIVER=null` + `php artisan config:cache` → mode dégradé immédiat (3.5), sans redéploiement.
- **Workers** : `systemctl stop leezr-queue-*-<site>` ; les jobs restent en table.

## 7. Après le déploiement (première heure, puis J+1)

| Contrôle | Attendu |
|---|---|
| `/up` et une connexion réelle | 200 |
| `storage/logs/laravel.log` | pas de nouvelle erreur récurrente |
| `scheduler:heartbeat` | une ligne par minute |
| `failed_jobs` | pas de croissance anormale |
| `jobs` sur `billing` / `billing-slow` | 0 |
| Flux SSE (si activé) | `leezr:realtime:stream:count:streams` ≤ plafond ; aucun 5xx sur `/api/realtime/stream` ; workers FPM libres (`pm.status`) |
| Données | `migrate:status` sans « Pending » ; comptage des presets publiés identique à 1.7 |
