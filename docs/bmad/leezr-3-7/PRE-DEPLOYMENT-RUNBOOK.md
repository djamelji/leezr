# Leezr 3.7 — Préparation de la levée des bloquants de déploiement (ADR-827)

> Préparé uniquement. Rien n'a été installé, migré ni modifié sur un serveur. Les vérifications marquées
> « lecture seule PROD » demandent une autorisation explicite (accès SSH ou workflow de diagnostic).

## 0. Base de comparaison corrigée

Le rapport 3.6 comparait `dev` à la production `15656c4` (199 commits). C'est inexact : les références
locales `main` et `origin/main` pointent sur **`7d49e784`** (ADR-740), qui contient ADR-738 (workers
séparés par environnement) et ADR-739 (planificateur = cron ISPConfig par site). Les notes d'exploitation
indiquent `7d49e78` comme déployé en production le 2026-11-08 — **non revérifié à distance** (aucun fetch).
Écart actuel : `main..dev` = 200 commits, 45 migrations.

## 1. Planificateur

| Point | Constat (local) | Statut |
|---|---|---|
| Source de `schedule:run` | cron ISPConfig par site (web2 PROD, web3 DEV), ADR-739 ; le timer systemd redondant est retiré par `deploy_release.sh` [6.7/9] | Prêt sous condition (vérifier en lecture seule PROD que le cron existe toujours) |
| Doubles exécutions | 30 tâches, **toutes** `->withoutOverlapping()` ; une seule source par environnement | Prêt |
| Verrous | `CACHE_STORE=database` (gabarit) → verrous dans la base de chaque environnement, pas de collision DEV/PROD | Prêt sous condition (vérifier `CACHE_STORE` du `.env` PROD) |
| Journalisation | `appendOutputTo(storage/logs/scheduler/*.log)` + instrumentation cockpit (`SchedulerInstrumentation`) + `scheduler:heartbeat` chaque minute | Prêt |
| Multi-tenant | les commandes planifiées parcourent les sociétés elles-mêmes ; isolation hors HTTP (P0-D, ADR-733) | Prêt |
| Nouvelles tâches depuis `main` | `onboarding:expire`, `onboarding:scan-uploads`, `onboarding:retention`, `referrals:purge`, `documents:*`, `leave:*` | Décision : activer en PROD au déploiement (elles modifient des données à échéance) |

Vérification lecture seule PROD proposée : `crontab -l -u web2`, `grep schedule:run /etc/cron.d/ispconfig`,
`php artisan schedule:list` dans `current`, dernière ligne de `storage/logs/scheduler/*.log`.

## 2. Workers

| Point | Constat (local) | Statut |
|---|---|---|
| Unités systemd | `leezr-queue-ai-{web2,web3}`, `leezr-queue-default-{web2,web3}` (ADR-738) ; anciennes unités partagées supprimées | Prêt |
| Isolation des files | `QUEUE_CONNECTION=database` (gabarit) → table `jobs` de chaque base ; DEV et PROD ne peuvent pas consommer les jobs l'un de l'autre | Prêt sous condition (vérifier `QUEUE_CONNECTION` PROD) |
| Redémarrage | `--max-jobs=100 --max-time=3600` + `queue:restart` en fin de déploiement | Prêt |
| Échecs | `--tries=3`, table `failed_jobs`, `billing:check-dlq` horaire | Prêt |
| **Files sans consommateur** | `billing` (renouvellement, relances) et `billing-slow` (rapprochement) ne sont consommées par aucun worker. Le planificateur lance ces commandes **en ligne** (sans `--async`) : sans effet aujourd'hui, mais un `--async` manuel laisserait les jobs en attente indéfiniment | Risque latent — décision D-17 |
| Jobs historiques DEV | 77 jobs en attente sur le DEV local (`ai` 20, `default` 57), non lancés | Hors périmètre (ADR-814) |

Proposition D-17 (non appliquée) : soit ajouter `--queue=default,billing,billing-slow` au worker default de
chaque environnement, soit interdire `--async` tant qu'aucun worker billing n'existe.

## 3. Migration modifiée après livraison

- Fichier : `database/migrations/2026_10_30_150000_add_signer_identity_to_signers.php`.
- Version initiale `109d95cd` (2026-10-30, dans `main`) : noms d'index automatiques de 68 caractères —
  refusés par MySQL et MariaDB (limite 64). La création échoue **après** la table et ses clés étrangères :
  la table `workforce_signature_enrollments` existe sans ses deux index et la migration n'est pas enregistrée.
- Version actuelle `9d1bf431` (ADR-793.1, 2026-11-15, absente de `main`) : noms explicites courts.
- Réparation additive déjà présente : `2026_11_23_000002_repair_signature_enrollment_indexes.php` (n'ajoute
  que l'index absent, détecté par ses colonnes ; rejouable ; ne supprime rien).
- La migration est **rejouable** : colonnes protégées par `hasColumn`, table par `hasTable`.
- État DEV (lecture seule) : enregistrée (lot 21) ; index `wf_sig_enroll_signer_created_idx` présent ;
  `company_id` couvert par l'index de la clé étrangère.
- Conséquence par état de production possible : non enregistrée + table présente → rejouée sans effet puis
  réparée ; enregistrée → réparation seule ; absente → création complète avec les noms courts. **Aucune
  migration corrective supplémentaire n'est nécessaire.**
- Vérification lecture seule PROD : `SELECT * FROM migrations WHERE migration LIKE '2026_10_30_150000%'` et
  `SHOW INDEX FROM workforce_signature_enrollments`.

## 4. Rôle chauffeur (historique)

Mécanisme conservé tel quel : `rbac:driver-role-remediate` (simulation par défaut ; `--apply` exige
`--confirm-database` ; lot d'audit ; `--restore=<lot>` ; rôles personnalisés exclus sauf `--role` explicite ;
permissions de livraison conservées). Dernière simulation DEV (3.6) : 8 rôles à corriger.
Procédure progressive proposée (non appliquée, D-8) :
1. simulation sur la base cible, archivée ;
2. sociétés sans chauffeur (2, 3, 31 à 34) ; contrôle d'accès d'un chauffeur fictif ;
3. société 101 (fictive) ; recette des parcours chauffeur ;
4. société 1 ; contrôle ; restauration par lot si régression.

## 5. Temps réel (SSE / PHP-FPM) — prérequis de configuration

Mesuré sur une pile isolée (ADR-827) : **un flux ouvert = un worker PHP-FPM occupé** pendant toute sa vie.
Quand les onglets ouverts atteignent `pm.max_children`, toute l'application attend (17 s mesurées pour une
requête ordinaire avec 4 flux sur 4 workers). À fixer avant déploiement (D-10) :
- `REALTIME_MAX_CONCURRENT_STREAMS` < `pm.max_children` moins une réserve pour les requêtes ordinaires ;
- `REALTIME_STREAM_TIMEOUT` cohérent avec `max_execution_time` du pool (le flux s'arrête désormais seul
  5 s avant une limite verrouillée) ;
- le relevé de `pm.max_children`, `max_execution_time`, `request_terminate_timeout` du pool PROD
  (lecture seule) conditionne ces valeurs.
