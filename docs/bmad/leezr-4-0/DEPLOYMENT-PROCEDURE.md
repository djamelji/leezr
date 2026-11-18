# Leezr 4.0 RC — Procédure de mise en production (ADR-830)

> **Livrable documentaire. Ce n'est pas une autorisation d'exécution.** Chaque étape marquée 🔒 exige une autorisation
> explicite. Complète `docs/bmad/leezr-3-9/PRE-DEPLOYMENT-RUNBOOK.md` (détail par sujet) et utilise les commandes de
> `docs/bmad/leezr-3-8/PRODUCTION-READONLY-CHECKS.md` (lecture seule, sans secret). L'état de la branche `main` locale
> ne prouve rien sur ce qui est déployé.

## 0. Ce qui est démontré localement / ce qui manque

| Point | Démontré localement | Preuve de production manquante |
|---|---|---|
| Révision déployée | — | `readlink -f current`, `APP_BUILD_VERSION` (checks §1) |
| PHP | `composer.json` `^8.2` ; CI de build en 8.3 ; les 96 paquets verrouillés acceptent 8.4 | version réelle du binaire et des extensions (`php -v`, `php -m`) sur le serveur |
| Migrations | DEV : 347/347 appliquées, aucune en attente ; aucune migration ajoutée ni modifiée depuis la 3.8 ; les 3 états possibles de `2026_10_30_150000` convergent (rejoués en 3.8) | `migrate:status` de production (checks §2) |
| Variables d'environnement | gabarit `.env.example` ; `BILLING_ASYNC_QUEUES_ENABLED=false` par défaut | présence des clés (checks §8, valeurs non secrètes seulement) |
| Planificateur | 30 tâches `withoutOverlapping()` ; une seule source attendue (cron ISPConfig, ADR-739) | crontab du site, absence d'unité `leezr-scheduler*` (checks §3) |
| Workers et isolation des files | unités namespacées par site (ADR-738) ; `QUEUE_CONNECTION=database` | unités actives, `jobs`/`failed_jobs` par file (checks §4) |
| Billing | relances : un passage par échéance prouvé (tests + course réelle à deux processus sur clone) ; `--async` refusé | aucun job sur `billing`/`billing-slow` ; drapeau à `false` |
| Redis | préfixes isolés prouvés sur les bancs | préfixes PROD ≠ DEV si instance partagée (checks §5) |
| Sessions | `SESSION_DRIVER=database` (gabarit) ; continuité recettée (expiration, reconnexion, retour à l'écran, onglets) | driver, durée, domaine des cookies de production |
| Fichiers et stockage | `shared/storage` lié par `deploy_release.sh` [3/9] | espace disque, droits, présence des documents générés |
| SSE / polling | profil A recetté (§3) ; profil B recetté sur banc (ADR-829) | pool PHP-FPM, Apache `mod_proxy_fcgi` / compression / timeouts (checks §6–7) |
| Sauvegardes | `deploy_release.sh` [4.9] : dump vérifié, abandon avant migration en cas d'échec | une restauration de test d'un dump récent (hors production) |
| Monitoring | `scheduler:heartbeat`, journaux `storage/logs/scheduler`, `billing:check-dlq` | qui surveille, où, avec quelles alertes |
| Retour arrière | `deploy/rollback.sh` (bascule du lien) | au moins 2 releases conservées sur le serveur |

## 1. Profils d'exploitation du temps réel

| | **Profil A — Polling (recommandé par défaut)** | **Profil B — SSE** |
|---|---|---|
| Configuration | `REALTIME_DRIVER=null` | `REALTIME_DRIVER=sse`, `REALTIME_MAX_CONCURRENT_STREAMS=<N>` |
| Workers | aucun flux durable : l'endpoint répond **204**, aucun compteur | 1 worker PHP-FPM par flux ouvert |
| Actualisation | navigation et sociétés toutes les 30 s ; badge de notifications toutes les 30 s (ADR-830) ; au retour de visibilité | événements poussés (~1 s), repli polling automatique |
| Sondes SSE | 3 au chargement (2 s, 4 s), puis 1 tentative bornée (30 s → 300 s) | — |
| Prérequis | aucun | relevé du pool ; `N` ≤ `pm.max_children` − réserve (au moins la moitié du pool) ; `flushpackets=on` ; pas de compression sur `/api/realtime/stream` ; `ProxyTimeout` ≥ `REALTIME_STREAM_TIMEOUT` ; `REALTIME_STREAM_TIMEOUT` < `max_execution_time` verrouillé |
| Surveillance | taux de requêtes `/api/nav`, `/api/notifications/unread-count` | `leezr:realtime:stream:count:streams` ≤ N ; `pm.status` (active, `max children reached` = 0) ; 429 en hausse = plafond trop bas |
| Bascule | — | retour au profil A : `REALTIME_DRIVER=null` + `php artisan config:cache`, sans redéploiement |

Le profil B n'est pas validé pour la production tant que les prérequis ne sont pas relevés sur le serveur réel (D-10).

## 2. Ordre des opérations

1. 🔒 **Sauvegardes vérifiées** — dump complet de la base de production, contrôle d'intégrité, copie hors serveur ; sauvegarde de `shared/.env` et `shared/storage`. Critère : restauration d'essai réussie sur une base jetable hors production.
2. 🔒 **Vérifications préalables (lecture seule)** — checks §1 à §9 ; consigner la révision déployée, l'état des migrations, les variables, le pool, le proxy, les files.
3. **Validation des migrations** — sur un clone **de la production** (pas du DEV) : `migrate --pretend`, puis `migrate` sur le clone ; contrôle du schéma (`workforce_signature_enrollments`, index réparés) et des comptages métier avant/après.
4. 🔒 **Configuration des environnements** — profil A (`REALTIME_DRIVER=null`), `BILLING_ASYNC_QUEUES_ENABLED=false`, préfixes Redis distincts, `CACHE_STORE` propre à l'environnement, activation décidée des nouvelles tâches planifiées.
5. 🔒 **Déploiement** (après autorisation) — `deploy.yml` → `deploy_release.sh` : sauvegarde [4.9], pré-check [4.95], `migrate --force` [5/9], workers [6–6.7], caches [7/9], bascule de `current`.
6. **Smoke tests** (≤ 15 min) — `/up` = 200 ; connexion société et platform ; `/api/realtime/stream` sans session = 401 JSON, avec session = 204 (profil A) ; une page par module actif ; aucune 5xx dans `laravel.log`.
7. **Vérifications métier** — comptages identiques à l'étape 2 (sociétés, rôles personnalisés, presets publiés, factures, écritures comptables, documents, contrats) ; une facture et un bulletin consultables ; aucune relance déclenchée hors calendrier.
8. **Surveillance** — première heure puis J+1 : `scheduler:heartbeat`, `failed_jobs`, `jobs` sur `billing*` = 0, erreurs 401/419/429/5xx, temps de réponse, workers FPM libres.
9. **Critères de retour arrière** — 5xx persistantes, migration en échec, connexion impossible, écart de comptage métier, relance ou suspension anormale → `deploy/rollback.sh` (code) ; base : restauration du dump de l'étape 1 sur **décision humaine** (perte des écritures postérieures) ; temps réel : profil A.

## 3. Recette du profil A (banc isolé, 5 workers, clone marqué)

`docs/bmad/leezr-4-0/realtime/polling-session-recette.json` : 5 onglets, 204 uniquement, 1 worker actif sur 5, API ≤ 113 ms ; navigation et notifications relues ~toutes les 30 s ; notification visible sans rechargement en 9,5 s ; changement de module répercuté à la navigation ; changement de société sans flux ; session expirée : 7 réponses 401 en 70 s sur 5 onglets (pas de boucle) ; reconnexion → retour à l'écran d'origine ; déconnexion dans un onglet → les autres suivent et reviennent chacun à leur écran.
