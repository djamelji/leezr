# Leezr 4.0 RC2 — Plan de synchronisation du DEV distant (dev.leezr.com)

> **Audit et préparation uniquement.** Aucun push, aucun fetch/pull, aucun reset, aucune connexion distante, aucun
> déploiement n'ont été effectués. Tout ce qui concerne le serveur est **à vérifier** avec les commandes en lecture
> seule de la section 3 ; rien n'est déduit des références locales au-delà de ce qu'elles prouvent.
> DEV distant ≠ production : `dev` → `web3` (`dev.leezr.com`, base `c1leezrdev`) ; `main` → `web2` (`leezr.com`).

## 0. Point capital

**Pousser `dev` = déployer.** `.github/workflows/deploy.yml` se déclenche sur tout push vers `dev` (et `main`) :
build sur GitHub Actions, envoi de l'artefact, puis `deploy/deploy_release.sh` sur `web3` (migrations, workers,
caches, bascule du lien `current`). Il n'existe pas de « push sans déploiement » sur cette branche. Le workflow
n'exécute **aucun test** avant de déployer.

## 1. État Git local

| Élément | Valeur |
|---|---|
| Branche | `dev` |
| HEAD | `17f5178a` (docs ADR-831, 2026-11-19) |
| `origin/dev` (référence locale) | `459a7d3c` — mise à jour **par push** le 2026-11-09 20:13 (reflog) |
| Avance / retard | **212 commits en avance, 0 en retard** ; `origin/dev` est ancêtre de `dev` → push en **avance rapide** |
| Dernier fetch | 2026-11-08 (`FETCH_HEAD`) |
| `main` local | = `origin/main` = `7d49e784` (non concerné) |
| Arbre de travail | propre hors fichiers protégés : `CLAUDE.md`, `components.d.ts`, `typed-router.d.ts` (modifiés), `docs/bmad/DMS-ARCHITECTURE.md` (non suivi), 14 captures `docs/bmad/ux2-777` (modifiées) — **à préserver, ne pas commiter** |

Limite : sans fetch, rien ne prouve que `origin/dev` n'a pas avancé depuis le 2026-11-09 (autre poste, interface
GitHub). Si c'est le cas, le push sera **refusé** (non fast-forward) : ne jamais forcer ; faire constater la
divergence d'abord (§7, étape 1).

## 2. Commits à synchroniser

212 commits, ADR-475 → ADR-831 (premier : `ef8803b6` ADR-748b, 2026-11-09 ; dernier : `17f5178a`). Contenu majeur :
DMS M0→M6, M7 (consolidation Company), L3→L7 (presets versionnés, contributions, champs salarié, onboarding,
JobDomain), ADR-800→824 (rationalisation, assistants modules, inscription Logistique, refonte Company 2.0/3.x),
3.5→4.0 RC2 (gardes de recette, SSE/polling, relances à une tentative par échéance, limiteurs par route,
qualification E2E).

Changements d'exploitation à connaître :

| Sujet | Changement |
|---|---|
| Migrations | **44 ajoutées, 1 modifiée** (`2026_10_30_150000_add_signer_identity_to_signers`) |
| Dépendances | `composer.lock` et `pnpm-lock.yaml` **inchangés** ; `package.json` : script `test:js` seulement |
| `.env.example` | `BILLING_ASYNC_QUEUES_ENABLED=false` |
| Nouvelles clés (toutes avec défaut sûr) | `REALTIME_MAX_CONCURRENT_STREAMS` (0), `DOCUMENT_SCANNER` (`none`), `CLAMAV_SOCKET`, `DOCUMENT_SCANNER_TIMEOUT/MAX_ATTEMPTS`, `DOCUMENTS_GENERATED_DISK` (`local`), `JOBDOMAIN_VERSIONED_INIT` (`true`), `JOBDOMAIN_VERSIONED_INIT_JOBDOMAINS` (`*`), `JOBDOMAIN_LEGACY_EDITING` (`false`), `MEMBERS_INVITATION_TTL_DAYS` (14), `ONBOARDING_RETENTION_AUTO_APPLY` (`false`), `SIGNATURE_EXTERNAL_*` (désactivé), `WORKFORCE_EMPLOYEE_NUMBER_PREFIX` (`EMP-`) |
| Tâches planifiées nouvelles | `workforce:signature-expire` (horaire), `members:invitations-expire` (horaire, trace d'audit), `onboarding:expire` (horaire), `onboarding:scan-uploads` (10 min), `onboarding:retention` (03:30, sans `--apply` par défaut), `referrals:purge` (03:40) |
| Déploiement | `deploy/templates/leezr-queue-billing.service.example` (modèle seulement, non installé) |
| Route | `/api/_recipe/identity` non enregistrée si `APP_ENV=production` ; 404 hors clone marqué sinon |

## 3. État distant connu et informations manquantes

Connu (notes et historique, **non vérifié aujourd'hui**) : dernier push `dev` = `459a7d3c` (2026-11-09) ; PHP 8.4
sur le VPS (CI en 8.3) ; Redis via `predis` ; cron ISPConfig = source unique du planificateur (ADR-739) ;
workers systemd namespacés par site (ADR-738) ; le DEV distant tournerait en `APP_ENV=production` (mention ADR,
à confirmer) ; comptes SSH de déploiement sans `sudo`.

Inconnu — **à relever en lecture seule** (`APP=/var/www/clients/client1/web3`, `PHP=/usr/bin/php8.4`), commandes
détaillées et interprétation : `docs/bmad/leezr-3-8/PRODUCTION-READONLY-CHECKS.md` en remplaçant `web2` par `web3`
et `c1leezr` par `c1leezrdev` :

| # | Vérification | Commande (lecture seule) | Pourquoi |
|---|---|---|---|
| R1 | Révision déployée | `readlink -f $APP/current` ; `grep '^APP_BUILD_VERSION=' $APP/shared/.env` ; `ls -1t $APP/releases \| head -3` | confirmer `459a7d3c` (ou autre) et l'existence d'une release de retour |
| R2 | Dernier déploiement | `tail -60 $APP/shared/storage/logs/deploy.log` | savoir si le déploiement du 09/11 a réussi |
| R3 | Environnement | `cd $APP/current && $PHP artisan config:show app.env` | `production` ⇒ `leezr:dev-bootstrap` ignoré, recettes refusées ; sinon voir §5 |
| R4 | Migrations | `$PHP artisan migrate:status --no-ansi \| grep -c Pending` ; état de `2026_10_30_150000` et de la table `workforce_signature_enrollments` (requêtes §2 du document 3.8) | identifier l'état (1, 2 ou 3) de la migration modifiée |
| R5 | PHP | `$PHP -v \| head -1` ; `$PHP -m \| grep -iE 'gd\|imagick\|zip\|intl\|bcmath\|pdo_mysql\|mbstring'` | compatibilité |
| R6 | Files | `systemctl list-units 'leezr-queue-*' --all --no-pager` ; `$PHP artisan config:show queue.default` ; SQL `SELECT queue, COUNT(*) FROM jobs GROUP BY queue` et idem `failed_jobs` | aucun job `billing*` en attente |
| R7 | Planificateur | `crontab -l 2>/dev/null \| grep schedule:run` ; `systemctl list-units 'leezr-scheduler*' --all --no-pager` | une seule source (cron) |
| R8 | PHP-FPM | `grep -hE '^(pm\|pm\.max_children\|request_terminate_timeout)' /etc/php/8.4/fpm/pool.d/web3.conf` | profil polling recommandé tant que non mesuré |
| R9 | Redis | `$PHP artisan config:show database.redis.options.prefix` ; `config:show cache.default` ; `config:show realtime.redis_prefix` | préfixes **différents** de `web2` |
| R10 | Variables (présence) | boucle §8 du document 3.8 + `DOCUMENT_SCANNER JOBDOMAIN_VERSIONED_INIT MEMBERS_INVITATION_TTL_DAYS` | aucune valeur secrète affichée |
| R11 | Stockage | `df -h $APP` ; `du -sh $APP/shared/storage` ; `ls -ld $APP/shared/storage/app/private` | place pour release + sauvegarde |
| R12 | Sauvegardes | `ls -lt $APP/shared/backups 2>/dev/null \| head` | **le déploiement DEV ne sauvegarde pas la base** (§6) |
| R13 | SSE / polling | `$PHP artisan config:show realtime.driver` ; `config:show realtime.max_concurrent_streams` | conserver polling (`null`) |
| R14 | Signatures échues | `$PHP artisan workforce:signature-expire --dry-run` | compter ce que la nouvelle tâche horaire expirera après déploiement |

## 4. Compatibilité des migrations

**Prouvé localement (2026-11-19)** sur une base jetable `leezr_audit831sync` :
1. application des **303 migrations exactes d'`origin/dev`** (extraites par `git show`, sans checkout) : la version
   historique de `2026_10_30_150000` échoue sur MySQL (`Identifier name … too long`) → état partiel reproduit
   (table créée, index absent, migration non enregistrée) — c'est l'un des trois états possibles du DEV distant ;
2. puis `migrate` du code RC2 : **61 migrations appliquées, 0 échec, 347/347 enregistrées**, index réparé
   (`wf_sig_enroll_signer_created_idx`) ;
3. schéma obtenu **identique** au DEV local (2 563 colonnes, index identiques).

Analyse des 44 nouvelles migrations : additives (création de tables et colonnes, `->change()` vers nullable) ; les
suppressions sont dans les `down()`. Écritures de données dans `up()` : remplissage de nouvelles colonnes (DMS sujets,
`preparatory_at`), catalogue Platform (`jobdomains` logistique : rôles recommandés, `signup_available`,
`allow_custom_fields`), nouvelle version publiée du preset RBAC logistique, ajout de permissions Platform
(`insertOrIgnore`). **Aucune migration ne modifie les rôles ou permissions des sociétés existantes** (remédiation
RBAC historique non appliquée, conformément à D-8).

Reste à prouver : le passage sur les **données** du DEV distant → clone de `c1leezrdev` (§6, étape S3).

## 5. Configurations nécessaires (DEV distant)

| Clé | Valeur recommandée | Motif |
|---|---|---|
| `BILLING_ASYNC_QUEUES_ENABLED` | `false` | aucun worker `billing` ; `--async` refusé (ADR-828) |
| `REALTIME_DRIVER` | `null` (polling, profil A) | capacité FPM non mesurée (D-10) |
| `REDIS_PREFIX` / `CACHE_PREFIX` | propres à `web3`, différents de `web2` | isolation |
| `QUEUE_CONNECTION` | inchangé (`database`) | workers existants |
| `DOCUMENT_SCANNER` | `none` (défaut) **ou** `clamav` si installé | `none` = échec fermé : les dépôts d'onboarding restent en quarantaine (recette de l'onboarding candidat incomplète) ; `simulated` interdit hors DEV/tests |
| `ONBOARDING_RETENTION_AUTO_APPLY` | `false` | pas de purge automatique |
| `SIGNATURE_EXTERNAL_ENABLED` | `false` | aucun prestataire réel |
| `JOBDOMAIN_VERSIONED_INIT` | `true` (défaut) | nouvelles sociétés initialisées par les presets publiés (ADR-820) |
| `APP_ENV` | à confirmer (R3) | `production` : bootstrap DEV ignoré, gardes de recette actives |

Après déploiement, sur un DEV distant existant, **les registres ne sont pas resynchronisés** : la branche DEV de
`deploy_release.sh` ne lance pas `SystemSeeder` (seule la production le fait). Les nouvelles permissions,
nouveaux modules et métiers des 212 commits n'apparaîtraient donc pas. Correction proposée (autorisation requise) :
`php artisan db:seed --class=SystemSeeder --force` — idempotent, exécuté à chaque déploiement de **production** ;
il ne réécrit jamais un mot de passe hors `local/testing`, ne crée les comptes de test qu'en `local/testing` ou avec
`LEEZR_SEED_TEST_ADMINS`. À exécuter d'abord sur le clone S3 et comparer les tables des sociétés avant/après.

Si `APP_ENV` n'est pas `production` : `leezr:dev-bootstrap` activera (idempotent) 7 modules Workforce sur
`leezr-logistics` et marquera `workforce_contract/signature` disponibles en test — à accepter explicitement ou à
éviter en confirmant `APP_ENV=production`.

## 6. Sauvegardes à réaliser (avant tout push)

Le déploiement DEV **ne sauvegarde pas** la base (la sauvegarde vérifiée ADR-737 ne concerne que `main`).

| # | Sauvegarde | Commande indicative (sur `web3`, sans secret en ligne de commande) |
|---|---|---|
| S1 | Base `c1leezrdev` | `mysqldump --defaults-extra-file=<fichier 600 hors webroot> --single-transaction --quick --routines --no-tablespaces c1leezrdev \| gzip > $APP/shared/backups/pre-rc2_$(date -u +%Y%m%dT%H%M%SZ).sql.gz` puis `gzip -t`, `grep -c 'CREATE TABLE'`, marqueur `Dump completed` |
| S2 | Fichiers | `tar czf $APP/shared/backups/pre-rc2-storage_<ts>.tgz -C $APP/shared storage/app` ; copie de `shared/.env` (chmod 600, hors webroot) |
| S3 | Clone de test | restaurer S1 dans une base jetable (hors serveur public ou locale, marquée `leezr_clone_*`), y jouer `migrate`, `SystemSeeder`, `workforce:signature-expire --dry-run`, et comparer les comptages métier avant/après |
| S4 | Copie hors serveur | rapatrier S1/S2 (chiffrés si possible) |

## 7. Procédure de push (après autorisation)

1. Constater l'état distant : `git fetch origin` (**autorisation requise**), puis `git log --oneline dev..origin/dev`
   doit être vide et `git merge-base --is-ancestor origin/dev dev` vrai. Sinon : arrêt, analyse, aucun `--force`.
2. Vérifier l'arbre : `git status` = fichiers protégés seulement ; ne pas les ajouter.
3. `git push origin dev` (**déclenche le déploiement**, §8).
4. Suivre GitHub Actions (build puis deploy) ; ne pas pousser autre chose avant la fin (file `deploy-dev`).

Ne pas toucher `main` (production).

## 8. Procédure de déploiement DEV distant

Prérequis : R1–R14 relevés, S1–S4 faits et vérifiés, S3 vert, configuration §5 validée, fenêtre annoncée.

Déroulé automatique (`deploy_release.sh`, branche `dev`) : dépendances système → release `releases/<ts>_<sha>` →
artefact → liens `shared/.env` et `shared/storage` → `APP_BUILD_VERSION` → `migrate --force` (additif) →
`leezr:dev-bootstrap` (ignoré si `production`) → workers AI et default (systemd namespacés) → suppression d'un
éventuel planificateur systemd redondant → caches (`optimize`) → contrôle de santé **avant** bascule → bascule
atomique de `current`. Tout échec avant la bascule laisse l'ancienne release en ligne.

Étapes manuelles après bascule (autorisation requise) :
1. `php artisan db:seed --class=SystemSeeder --force` (§5), si validé sur S3 ;
2. `php artisan config:show billing.async_queues_enabled` → `false` ; `realtime.driver` → `null` ;
3. `php artisan migrate:status --no-ansi | grep -c Pending` → 0 ;
4. surveiller la première exécution des nouvelles tâches (`workforce:signature-expire` : comparer à R14).

## 9. Smoke tests

**Lecture seule** (aucune écriture hors sessions de connexion) :

| Parcours | Contrôle |
|---|---|
| Santé | `/up` = 200 ; `APP_BUILD_VERSION` = `17f5178a` |
| Platform | connexion ; tableau de bord sans 5xx ; JobDomains (logistique « proposé à l'inscription ») ; presets publiés présents (familles logistique : catalogue, planning, RBAC, exigences) ; modules et dépendances ; plans et droits commerciaux |
| Company (compte existant) | connexion ; accueil `/home` ; Équipe (liste, fiche, rubriques) ; Planning (4 modes) ; Temps & pointages ; Absences ; Préparation de la paie (lecture) ; Expéditions et livraisons ; Documents |
| Billing | onglets facturation sans erreur ; **aucun** paiement, aucune relance déclenchée |
| RBAC | livreur : pas de paie collective, pas de section accès d'un collègue ; profil exploitation : listes limitées à son périmètre ; paie : seulement la paie |
| Multi-tenant | changement de société : données et menus suivent ; `X-Company-Id` d'une société non membre → refus |
| Temps réel | `/api/realtime/stream` : 401 JSON sans session, 204 avec session (polling) |
| Journaux | aucune 5xx dans `storage/logs/laravel.log` ; `failed_jobs` stable |

**Avec écritures — uniquement sur une société fictive dédiée** (créée pour la recette, jamais une société existante),
après autorisation : onboarding Company (inscription Logistique) ; Équipe et embauche (collaborateur fictif, contrat
brouillon) ; Planning (créneau, publication, confirmation de sureffectif) ; pointage (entrée/sortie) ; congés (demande
puis approbation) ; préparation de paie (période sans calcul définitif) ; expéditions (création, affectation) ;
documents (dépôt) ; Billing en sandbox sans moyen de paiement réel. Les commandes `e2e:*` restent refusées sur ce
serveur (non marqué) : la recette passe par l'interface.

Recette automatisée : **impossible sur le DEV distant par construction** (ADR-831 : la campagne exige un clone
marqué). Elle a été faite localement (460/0/5) ; sur le serveur, la recette est manuelle ou par specs en lecture
seule adaptées, sur autorisation distincte.

## 10. Procédure de rollback

| Situation | Action |
|---|---|
| Échec avant bascule | rien à faire : l'ancienne release reste active (vérifier `readlink -f current`) |
| Régression applicative après bascule, migrations additives sans perte | `deploy/rollback.sh` (bascule du lien vers la release précédente) ; les colonnes et tables ajoutées restent (compatibles avec l'ancien code) |
| Données dégradées | restauration de S1 sur **décision humaine** (perte des écritures postérieures au dump) |
| Tâche planifiée indésirable | suspendre via l'écran Platform des tâches planifiées ou commenter la ligne de crontab, sans redéploiement |
| Temps réel | rester/revenir en profil A : `REALTIME_DRIVER=null` puis `php artisan config:cache` |

Ne jamais lancer `leezr:dev-reset` ni `migrate:fresh` pour « réparer ».

## 11. Risques résiduels

| Risque | Niveau | Traitement |
|---|---|---|
| `origin/dev` a pu avancer depuis le 09/11 | moyen | fetch autorisé + contrôle d'ascendance ; jamais de `--force` |
| Le déploiement du 09/11 a pu échouer (état distant inconnu) | moyen | R1/R2 |
| État de la migration `2026_10_30_150000` sur le DEV distant | faible | convergence prouvée localement pour l'état partiel ; R4 + S3 |
| Pas de sauvegarde automatique en DEV | élevé si oublié | S1–S4 obligatoires |
| Registres non resynchronisés en DEV | moyen | `SystemSeeder` après validation S3 |
| `APP_ENV` du DEV distant différent de `production` | moyen | R3 ; conséquences §5 |
| Nouvelles tâches planifiées agissant sur des données existantes (signatures échues) | faible | R14 ; données de recette, pré-lancement |
| Scanner de documents `none` : dépôts d'onboarding en quarantaine | faible | décision : ClamAV (installation root via ISPConfig) ou accepter |
| Workflow sans tests avant déploiement | faible | tests faits localement sur le même SHA |
| Identité SSH partagée DEV/production (constat 2026-11-08) | moyen | séparer les clés de déploiement (hors de ce plan) |

## 12. Opérations nécessitant autorisation

1. **Lecture seule distante** : SSH sur `web3` pour R1–R14 (aucune écriture).
2. **Sauvegardes** S1, S2, S4 sur le serveur (écritures dans `shared/backups`, hors données applicatives).
3. **Clone de test** S3 (restauration du dump dans une base jetable, locale ou isolée).
4. `git fetch origin` (constat de divergence).
5. **`git push origin dev`** = déploiement automatique sur `dev.leezr.com`.
6. `db:seed --class=SystemSeeder --force` après déploiement.
7. Choix `DOCUMENT_SCANNER` (et installation ClamAV si retenu — opération root via ISPConfig).
8. Recette avec écritures sur une société fictive dédiée.
9. Toute restauration de S1 (rollback de données).

Restent hors de ce plan et inchangés : production (`main`, D-11), Company 1 (D-13), remédiation RBAC historique
(D-8), SSE en production (D-10), Billing asynchrone (D-17), suppression des clones locaux (dont
`leezr_audit831sync`, créé par ce plan).
