# Leezr 4.0 — déploiement DEV distant (dev.leezr.com) — rapport final

Date : 2026-11-19 · Cible : `web3` (`/var/www/clients/client1/web3`, base `c1leezrdev`, MariaDB 11.8) · **Production : aucun accès.**

## 0. Verdict

**DEV déployé et sain au SHA `82e35416`.** Un **défaut d'infrastructure pré-existant** a cependant été mis en évidence : un
second déploiement, hérité, est déclenché par chaque push (webhook GitHub → `https://leezr.com/webhook.php`, exécuté par le
**site de production `web2`**) et lance `DevSeeder` sur la base DEV réelle. Il a modifié des données des sociétés 1 et 2 (§9).
Rien n'a été restauré (interdit sans autorisation). La recette en écriture (société fictive) **n'a pas été jouée** tant que ce
point n'est pas décidé.

## 1. Version avant / après

| | Avant | Après |
|---|---|---|
| Release `current` | `20261109191527_459a7d3` | `20261119163003_82e3541` |
| `APP_BUILD_VERSION` | `459a7d3c…` | `82e35416ca21b8d8422d1980d8ca53293d551718` |
| `/api/public/version` | — | `82e35416…` |
| Migrations | 303, 0 en attente | 348, 0 en attente |

## 2. Accès

Compte du site `jliouidevleezr` (shell → `web3`), mot de passe existant passé par variable d'environnement (`sshpass -e`),
jamais en argument ni affiché. Aucun accès au compte `web2`, à la clé CI ni à la production. Incident de traçabilité : lors du
repérage de la ligne d'identifiants dans la mémoire locale, un masquage `sed` défaillant a laissé le mot de passe apparaître
dans une sortie d'outil de la session (rien envoyé à l'extérieur). **Recommandation : faire tourner ce mot de passe**, qui est
de plus commun aux comptes DEV et PROD et au panneau ISPConfig.

## 3. Audit R1–R14 (lecture seule)

Preuve locale : `scratchpad/deploy/remote-audit-20261119T161900Z.txt`. Points saillants : `app.env=staging`, `debug=true` ;
0 migration en attente ; workers systemd `leezr-queue-{default,ai}-web3` actifs et distincts de `web2` ; planificateur =
cron ISPConfig ; cache/session/file = `database` (isolés par base) ; `REALTIME_DRIVER=sse` (à passer à `null`) ;
`BILLING_ASYNC_QUEUES_ENABLED` absent (= false) ; 3 sociétés, plans `starter 0 / pro 2900 / business 7900`, TVA FR 2000 bps ;
0 demande de signature échue.

## 4. Sauvegardes

| | Fichier | Contrôle |
|---|---|---|
| S1 base | `shared/backups/pre-rc2_c1leezrdev_20261119T161942Z.sql.gz` (2,1 Mo) | gzip OK, 171/171 tables, « Dump completed », sha256, comptages |
| S2 fichiers | `pre-rc2_storage-app_20261119T161942Z.tgz` (16 Mo) + `pre-rc2_env_…` | tar OK, sha256 |
| Copie hors serveur | `~/leezr-backups/dev-20261119T161942Z/` (poste, 700/600) | **sha256 identiques** aux empreintes serveur |
| `.env` avant changement temps réel | `shared/backups/env-before-realtime-null_*` | copie 600 |

## 5. Clone-test (copie de la base réelle, poste local)

`REFDB=leezr_clone_sync832b CLONE_APP_ENV=staging bash docs/bmad/leezr-4-0/dev-sync/clone-test.sh <dump> <cnf> leezr_clone_devremote_20261119`
— preuves `storage/app/recipe-clones/leezr_clone_devremote_20261119/`.

Verdict brut ROUGE, **3 KO tous qualifiés non critiques** :

| KO | Cause | Qualification |
|---|---|---|
| comptage après restauration | `scheduled_task_runs` 134852 vs 134851 : le cron DEV a écrit une ligne entre le dump et le comptage | environnement (base vivante) |
| 185 écarts de colonnes | toutes `json`↔`longtext` : MariaDB stocke `json` en `longtext` | moteur |
| 1 écart d'index | MariaDB conserve l'index auto de FK `…_signature_signer_id_foreign` en plus de l'index composite réparé (ADR-793.1) | redondant, sans effet |

Contrôles critiques **verts** : 45 migrations OK, 0 en attente, aucune table n'a perdu de lignes ; `system:sync` : 0 valeur
administrée remplacée, aucune table hors catalogue modifiée, 2ᵉ passage sans écriture ; 0 signature échue. Revue statique des
45 migrations : **aucune suppression dans un `up()`**, aucun SQL brut ; seuls 3 `->change()` (nullable), supportés par MariaDB.

## 6. Git et push

`git fetch` : `origin/dev` = `459a7d3c`, ancêtre de `dev` (0 derrière, 216 devant, dont RC2 ADR-831 et ADR-832
`8ecda678`/`82e35416`). `git push origin dev` : `459a7d3c..82e35416` (fast-forward). Aucun push vers `main`, aucun force push,
aucun reset. Fichiers protégés locaux (CLAUDE.md, captures ux2-777, `DMS-ARCHITECTURE.md`, modifications en cours de
`typed-router.d.ts`/`components.d.ts`) non commités, intacts.

## 7. GitHub Actions

Run `44566375454` « Build & Deploy » : **success**. Journal serveur `[0/9]→[9/9]` 16:30:03 → 16:30:21 : migrate (rien à faire,
voir §9), `leezr:dev-bootstrap`, `system:sync`, workers reconfigurés et redémarrés, caches, health check, bascule, `DEPLOY OK`.
Un seul déploiement CI lancé.

## 8. Migrations, SystemSeeder, éléments installés

- 45 migrations appliquées (jusqu'à `2026_12_13_000001_create_system_sync_baselines_table`).
- Synchronisation (1ʳᵉ passe) : `{"code_updated":1,"conflict":4,"created":13}` ; passe CI suivante et `--dry-run` après
  déploiement : `{"conflict":4}`, aucune écriture. Rapport : `shared/storage/logs/system-sync-20261119163003.json`.
- Conflits conservés (décision Platform requise, `--adopt=…`) : `jobdomains:logistique:default_fields`,
  `jobdomains:logistique:default_roles`, `notification_topics:billing.payment_received:label`, `…:icon`.
- Installés : module `workforce_referral` ; permissions Platform `presets.view/edit/publish/preview_company` (offertes au
  rôle `admin`) ; permissions Company `workforce_referral.refer/review/manage`, `workforce.view_all`,
  `documents.view_confidential`, `payslips.import/review/publish/download` ; +1 champ, +2 sujets de notification, documentation.
- Inchangés (comparés à l'audit) : prix et essais des 3 plans, TVA FR/GB, nombre de sociétés (3).

## 9. Anomalie : second déploiement hérité (webhook → production → `deploy.sh dev`)

Constat (journal `deploy.log`, 16:27:50) : avant le run CI, `deploy.sh` (racine du dépôt, ancien pipeline git-clone) a créé
`releases/20261119_162750` (propriétaire **`web2`**), lancé `composer install`, **`migrate`** (les 45 migrations ont en réalité
été appliquées ici, avec le même code), `db:seed SystemSeeder`, puis **`db:seed DevSeeder`**, qui a échoué
(`Unable to create a directory … storage/app/private/documents/seed`) après avoir écrit. Déclencheur : webhook GitHub actif
`push` → `https://leezr.com/webhook.php` (site de production). Le même chemin a déjà tourné le 2026-11-09.

Écritures de `DevSeeder` (16:28:09–16:28:21), comparées au clone restauré depuis la sauvegarde :

| Donnée | Effet |
|---|---|
| `company_role_permission` sociétés 1 et 2 | preset de rôles réappliqué : `documents.configure` retiré à `dispatcher` et `ops_manager` ; `documents.view_confidential` ajouté à `manager` et `owner`. **Rôle chauffeur inchangé.** |
| `company_preset_snapshots` | +2 (`trigger=registration`, sociétés 1 et 2) |
| `subscriptions` id 2 (société 2) | `expired` → `trialing`, nouvel essai jusqu'au 2026-11-26 |
| `document_type_activations` société 2 | `required` 1 → 0 sur 3 types |
| `field_activations` société 1 | +1 (champ 46), 21 lignes ré-horodatées sans changement de valeur |
| `company_modules` | +2 `workforce_referral` désactivé (sociétés 1 et 2) |
| `users` 1–4, `platform_users` 1 | mot de passe ré-haché (valeur de démonstration du seeder) |

Aucune suppression de ligne, aucune donnée de paie, de pointage ou de document touchée. **Non restauré.** Restauration
possible ligne à ligne depuis le clone `leezr_clone_devremote_20261119` ou la sauvegarde S1, sur autorisation.

Recommandations (à décider, rien n'a été modifié) : désactiver le webhook `595692752` (doublon du pipeline CI, exécuté par
l'identité de production, `DevSeeder` sur données réelles) ; retirer l'étape `DevSeeder` de `deploy.sh` ou supprimer ce script
obsolète ; supprimer la release orpheline `releases/20261119_162750` (propriétaire `web2`, non courante).

## 10. Configuration

`REALTIME_DRIVER=sse` → `null` dans `shared/.env` (copie préalable), `config:cache`, `queue:restart`. Vérifié :
`realtime.driver=null`, `billing.async_queues_enabled=false`, `queue.default=database`, `session.driver=database`,
`cache.default=database` (isolation par base `c1leezrdev`) ; préfixe Redis `leezr-database-` non spécifique au site, sans effet
tant que ni cache, ni session, ni file n'utilisent Redis. Aucun worker billing async, aucun job `billing*`, `failed_jobs` = 0.

## 11. Recette après déploiement

| # | Contrôle | Résultat |
|---|---|---|
| P0.1 | `/up` | 200 |
| P0.2 | `/api/public/version` | `82e35416…` |
| P0.3 | `/api/realtime/stream` sans session | JSON 401 |
| P0.4 | `/api/_recipe/identity` | 404 |
| P0.5 | migrations en attente / billing async / temps réel | 0 / false / null |
| P0.6 | rapport de synchronisation + `--dry-run` | 4 conflits identiques au clone, aucune écriture |
| A1–A7 (API) | Platform : santé, attention, plans, jobdomains, modules, sociétés, presets, factures | 8/8 en 200 |
| C1–C6 (API) | Company 1 (propriétaire) : nav, membres, rôles, modules, expéditions, salariés, contrats, congés, soldes, statistiques, documents, conformité, me, départements, postes | 15/15 en 200 |
| R1 | chauffeur (bob) → paie | 403 ; propriétaire → 200 |
| R4 | `X-Company-Id` 999 / société non membre 2 et 3 | 404 / 403 / 403 |
| A8 | tâches planifiées depuis le déploiement | 5 tâches, toutes `success` |
| Journal | erreurs depuis 16:27 | 1 seule : l'échec `DevSeeder` du déploiement hérité |
| P1–P10 | écritures dans une société fictive | **non jouées** (en attente de décision sur §9) |

Recette navigateur (écrans) non jouée ; contrôles faits par l'API avec les comptes de démonstration (connexion = mise à jour
de `last_login_at`). Les 465 E2E n'ont pas été lancés contre le DEV distant.

## 12. Anomalies restantes

1. Webhook hérité + `DevSeeder` sur données réelles (§9) — **à décider avant tout nouveau push** sur `dev` (et à vérifier
   pour `main` : le même webhook déclenche `deploy.sh main` sur la production).
2. Écarts sociétés 1 et 2 laissés en l'état (§9).
3. 4 conflits de synchronisation à arbitrer (`--adopt`).
4. Mot de passe SSH commun DEV/PROD/ISPConfig, apparu dans une sortie de session : rotation recommandée.
5. Clone-test : prévoir la tolérance MariaDB (`json`/`longtext`, index FK redondant) et un comptage pris dans la même
   transaction que le dump.

## 13. État final

DEV `dev.leezr.com` = `82e35416`, migrations à jour, synchronisation système stable (4 conflits préservés), temps réel coupé,
billing async désactivé, workers et planificateur actifs. Sauvegardes vérifiées sur le serveur et sur le poste. Production
non touchée. Ce rapport est commité localement, **non poussé** (un push redéclencherait le webhook hérité).
