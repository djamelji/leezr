# Leezr 4.0 RC2 — Plan de synchronisation du DEV distant (dev.leezr.com)

> **Audit et préparation uniquement** (révisé le 2026-11-19, mission « audit pré-déploiement »). Aucun push, aucun
> fetch/pull, aucun reset, aucune connexion SSH, aucune migration ni seeder distants, aucun déploiement.
> DEV distant ≠ production : `dev` → `web3` (`dev.leezr.com`, base `c1leezrdev`) ; `main` → `web2` (`leezr.com`).
> Outils prêts : `docs/bmad/leezr-4-0/dev-sync/` — `remote-audit.sh` (R1–R14, lecture seule), `remote-backup.sh`
> (S1–S2), `clone-test.sh` (S3, poste local), `post-deploy-recette.md` (recette courte).

## 0. Tableau de bord

| Statut | Éléments |
|---|---|
| **Vérifié (local)** | état Git ; contenu des 212 commits ; chaîne de migrations depuis l'état exact d'`origin/dev` (y compris l'échec historique `2026_10_30_150000`) → 347/347, schéma identique ; workflow et script de déploiement lus ligne à ligne ; effets réels de `SystemSeeder` (audit du code + exécution sur clone simulé) ; **synchronisation système corrigée (ADR-832) : configurations Platform préservées, prouvé sur clones (ancien code : 8 décisions sur 10 écrasées ; nouveau : 10/10 préservées, idempotent, installation vierge OK)** ; trois scripts exécutés localement (audit : aucune fuite de secret ; clone : vert) ; aucune écriture sur le DEV local (journal binaire) |
| **Attend l'accès distant (lecture seule)** | R1–R14 : révision et dernier déploiement, `APP_ENV`, migrations, PHP, files et workers, planificateur, FPM, Redis, variables, stockage, sauvegardes, temps réel, signatures échues, catalogue Platform éventuellement édité |
| **Attend une sauvegarde** | S1 base `c1leezrdev`, S2 fichiers + `.env`, S4 copie hors serveur |
| **Attend un clone** | S3 : restauration d'essai, migrations sur **données réelles** du DEV distant, `system:sync` avec rapport des conflits, `--dry-run` des signatures |
| **Exécutable dès autorisation** | `remote-audit.sh`, puis `remote-backup.sh`, puis `clone-test.sh` (dans cet ordre) |
| **Bloquant avant le push** | (1) R1–R14 relevés ; (2) S1/S2 vérifiés et rapatriés ; (3) S3 vert ; (4) arbitrages des conflits signalés par `system:sync` sur le clone (§5.3) ; (5) `fetch` autorisé et `origin/dev` ancêtre de `dev` ; (6) `APP_ENV` confirmé ; (7) autorisation explicite du push = déploiement |

## 1. État Git local

| Élément | Valeur |
|---|---|
| Branche / HEAD | `dev` / `355a8c18` (plan initial ; ce document en ajoute un) |
| `origin/dev` (référence locale) | `459a7d3c`, mise à jour **par push** le 2026-11-09 20:13 ; dernier fetch : 2026-11-08 |
| Avance / retard | **214 commits en avance (212 RC2 + 2 commits de plan), 0 en retard** ; `origin/dev` ancêtre de `dev` → avance rapide |
| `main` | = `origin/main` = `7d49e784` (non concerné) |
| Fichiers à préserver, ne pas commiter | `CLAUDE.md`, `components.d.ts`, `typed-router.d.ts` (modifiés), `docs/bmad/DMS-ARCHITECTURE.md` (non suivi), 14 captures `docs/bmad/ux2-777` |

Sans fetch, rien ne prouve que `origin/dev` n'a pas avancé : en cas de divergence le push est refusé ; jamais de `--force`.

## 2. Commits à synchroniser

ADR-748b → ADR-831 : DMS M0→M6, M7, L3→L7, ADR-800→824, 3.5→4.0 RC2. Exploitation : **44 migrations ajoutées, 1
modifiée** ; `composer.lock` et `pnpm-lock.yaml` inchangés ; nouvelles clés d'environnement toutes avec défaut sûr
(`BILLING_ASYNC_QUEUES_ENABLED=false`, `REALTIME_MAX_CONCURRENT_STREAMS=0`, `DOCUMENT_SCANNER=none`,
`SIGNATURE_EXTERNAL_ENABLED=false`, `ONBOARDING_RETENTION_AUTO_APPLY=false`, `JOBDOMAIN_VERSIONED_INIT=true`…) ;
**6 nouvelles tâches planifiées** : `workforce:signature-expire` (horaire), `members:invitations-expire` (horaire,
trace), `onboarding:expire` (horaire), `onboarding:scan-uploads` (10 min), `onboarding:retention` (03:30, sans
`--apply`), `referrals:purge` (03:40). Seule `signature-expire` modifie des données existantes (demandes échues →
expirées, liens révoqués) : mesurée par R14 et par le clone.

## 3. Audit distant (LOT 1) — `remote-audit.sh`

**Accès nécessaire** : session SSH avec le **compte shell ISPConfig propre au site web3** (sans `sudo`, droits
limités au site), clé dédiée à cet audit. **Ne pas utiliser** l'identité de déploiement CI (`secrets.VPS_USER`),
partagée avec la production et capable de `sudo` (constat du 2026-11-08). Si ce compte ne peut pas lire
`/etc/php/*/fpm/pool.d` ou `/etc/cron.d`, R7/R8 indiquent « illisible » : relevé via le panneau ISPConfig.

**Exécution** (une seule commande, sortie à rapatrier) :
`bash remote-audit.sh > remote-audit-<horodatage>.txt 2>&1`

**Revue ligne à ligne** :

| Contrôle | Commandes | Lecture seule | Secret | Job / config | État vérifiable obtenu |
|---|---|---|---|---|---|
| R1 révision | `readlink`, `grep APP_BUILD_VERSION`, `ls releases` | oui | non (version) | non | release active, SHA, releases de retour |
| R2 déploiement | `tail deploy.log` filtré (`password/secret/token/key=/credential` exclus) | oui | filtré | non | succès/échec du 09/11 |
| R3 environnement | `artisan config:show app.env/app.debug/app.url` | oui | non | non | `production` ou non (conséquences §5, §8) |
| R4 migrations | `artisan migrate:status` ; SQL `migrations`, index et colonnes de signature | oui (`SELECT`, session `READ ONLY`) | non | non | en attente ; état 1/2/3 de la migration modifiée |
| R5 PHP | `php -v`, `php -m`, `command -v gs tesseract convert` | oui | non | non | version, extensions, outils |
| R6 files | `systemctl list-units/cat` (sans `sudo`), `config:show queue.default`, SQL `jobs`/`failed_jobs` par file | oui | non (`ExecStart` sans secret) | non (aucun `queue:*` exécuté) | workers, files `billing*` vides |
| R7 planificateur | `crontab -l`, `/etc/cron.d`, `systemctl list-units leezr-scheduler*`, logs scheduler | oui | non | non | source unique cron |
| R8 FPM | lecture du pool, `nproc`, `free` | oui | non | non | `pm.max_children` (profil polling maintenu) |
| R9 Redis/cache | `config:show` préfixes, client, cache, session | oui | non (préfixes) | non | isolation web2/web3 |
| R10 variables | **présence** de 21 clés ; valeurs de 8 clés non secrètes | oui | non | non | configuration à compléter |
| R11 stockage | `df`, `du`, `ls -ld` | oui | non | non | place pour release + sauvegarde |
| R12 sauvegardes | `ls shared/backups` | oui | non | non | sauvegardes existantes |
| R13 temps réel | `config:show realtime.*` | oui | non | non | polling ou SSE |
| R14 signatures échues | SQL `COUNT(*)` équivalent au `--dry-run` (la commande n'existe pas dans la version en ligne) | oui | non | non | volume que la tâche horaire expirera |
| Référence recette | `COUNT(*)` de 23 tables ; plans, métiers (empreinte SHA2 des rôles), marchés, presets publiés | oui | non (aucune donnée personnelle) | non | base de comparaison après déploiement ; détection d'éditions Platform |

Garanties techniques : identifiants DB écrits dans un fichier temporaire 600 supprimé à la sortie (jamais en argument
ni à l'écran) ; aucune commande artisan d'écriture ; effet de bord possible : une ligne de journal applicatif.
**Validation locale** : exécuté contre une arborescence simulée et la base DEV locale → sortie complète, aucune
valeur de `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD` retrouvée dans la sortie ; deux corrections faites
(lecture de `config:show`, `MD5` indisponible → `SHA2`).

**Rapport attendu** (à reporter dans ce document) : SHA en ligne et date ; `APP_ENV` ; nombre de migrations en
attente et état de `2026_10_30_150000` ; workers actifs ; source du planificateur ; `pm.max_children` ; préfixes
Redis ; clés absentes ; espace libre ; sauvegardes existantes ; `REALTIME_DRIVER` ; demandes échues ; comptages
de référence ; plans/métiers/marchés tels qu'en base.

## 4. Processus de déploiement (LOT 2)

| Point | Constat (`.github/workflows/deploy.yml`, `deploy/deploy_release.sh`, `deploy/rollback.sh`) |
|---|---|
| Déclenchement | **automatique** sur tout push vers `dev` (et `main`) ; file `deploy-dev` sans annulation ; **aucun test** dans le workflow |
| Build (GitHub) | PHP 8.3, `composer install --no-dev`, Node 22, pnpm 10.28.1 `--frozen-lockfile`, build front, artefact `tar.gz` |
| Cible | `web3` (`/var/www/clients/client1/web3`), contrôle `/up` après déploiement |
| Étapes serveur | [0] dépendances système → [1] release `releases/<ts>_<sha>` → [2] artefact → [3] liens `shared/.env` et `shared/storage` → [4] `APP_BUILD_VERSION` → **[5] `migrate --force` (base partagée, AVANT la bascule)** → [5.5] `leezr:dev-bootstrap` (ignoré si `APP_ENV=production`) → [6/6.5] unités systemd `queue:work` `ai` et `default` réécrites et activées → [6.7] suppression d'un planificateur systemd redondant → [7] caches + `optimize` → [8] contrôle de santé → **[9] bascule atomique** de `current` et du webroot, `reload` PHP-FPM, `restart` des deux workers, purge des releases au-delà de 5 |
| Migrations | exécutées sur la base partagée **avant** la bascule : pendant la fenêtre, l'ancien code tourne sur le nouveau schéma (compatible : migrations additives) ; si le contrôle de santé échoue, l'ancienne release reste en ligne **mais les migrations restent appliquées** |
| Services | PHP-FPM rechargé ; workers `ai` et `default` redémarrés ; aucun worker `billing` (modèle seulement) |
| Planificateur | non touché : le cron ISPConfig appelle `current/artisan schedule:run` → les **nouvelles tâches démarrent dès la bascule** |
| Sauvegarde | **aucune en DEV** (la sauvegarde vérifiée ADR-737 est réservée à `main`) |
| Registres | avant ADR-832 : **non resynchronisés en DEV** (`SystemSeeder` réservé à `main`) ; **désormais** : étape [5.6] `system:sync --force --json` en DEV, [5.5] `system:sync` en production (§5.3) |
| Retour arrière | `rollback.sh` : bascule du lien vers une release précédente + `reload` FPM ; **ne redémarre pas les workers** ; suggère `migrate:rollback` (à proscrire, §7) |
| Suspendre le déclenchement | (a) **commit de tête contenant `[skip ci]`** : GitHub n'exécute pas le workflow pour ce push → code poussé sans déploiement ; (b) désactiver le workflow (`gh workflow disable "Build & Deploy"` ou interface Actions) — **attention : suspend aussi `main`** ; (c) ne pas pousser. Aucune modification du workflow faite ici. |

## 5. `SystemSeeder` (LOT 3)

> **Mise à jour ADR-832 (2026-11-19)** : les écrasements décrits en §5.1 sont **corrigés**. §5.1–5.2 restent comme
> trace de l'ancien comportement ; la conduite à tenir est en §5.3. Rapport : `docs/bmad/LEEZR-4-0-SYSTEMSEEDER-FINAL.md`.

### 5.1 Effets réels (audit du code, avant ADR-832)

| Composant | Écriture | Écrase une configuration existante ? |
|---|---|---|
| `PlatformSeeder` | `ModuleRegistry`, `JobdomainRegistry`, `DocumentTypeCatalog`, `PlatformPermissionCatalog` ; rôles `super_admin`/`admin` | **oui** : rôle Platform `admin` ← **toutes** les permissions Platform (`sync`) ; mot de passe admin jamais réécrit hors `local/testing` ; comptes de test seulement en `local/testing` ou `LEEZR_SEED_TEST_ADMINS` |
| `PlanRegistry::sync` | `plans` | **oui : nom, description, prix mensuel/annuel, essai, limites** remis aux valeurs du code |
| `ModuleRegistry::sync` | `platform_modules` | nom, description, ordre ; **activation globale préservée** |
| `JobdomainRegistry::sync` | `jobdomains` | **oui : libellé, description, modules, champs, rôles recommandés, documents, presets** ; disponibilité commerciale préservée |
| `CompanyPermissionCatalog::sync` | `company_permissions` | libellé, module, `is_admin` des clés existantes ; ajoute les nouvelles |
| `FieldDefinitionCatalog`, `ModuleContributionCatalog` | définitions Platform (`company_id` nul), propositions modèles | n'impose rien aux sociétés |
| `MarketRegistry::sync` | marchés, langues, statuts juridiques | **oui : devise, taux de TVA, locale, fuseau** ; actif/défaut préservés |
| `NotificationTopicSeeder` | thèmes de notification | ajoute |
| `ModuleProductConfigSeeder` | tarification des modules | **initialise seulement** (jamais d'écrasement) |
| `PaymentModuleSeeder` | module interne, règle « manuel » | remet le module interne actif et la règle manuelle inactive ; Stripe et profils de paiement **seulement en `local/testing`** (appel API Stripe dans ce cas) |
| `MarketRuleSetSeeder`, `ConventionCollectiveSeeder`, `WorkforcePayrollRuleSeeder` | règles de marché, conventions, règles de paie | **oui** si édités dans Platform (valeurs du code) |
| Modèles de bulletins et de contrat | modèles Platform (`company_id` nul) | créés si absents / `updateOrCreate` par code+version |

Données des sociétés existantes : **aucune écriture** hors `local/testing` (vérifié par le code et par le clone).
Les définitions des plans, marchés, règles de paie et conventions **n'ont pas changé** depuis `origin/dev` : ces
composants ne modifient donc quelque chose que si une **édition Platform** a eu lieu sur le DEV distant — c'est ce que
détectent la section « catalogue » de R-audit et l'étape 5 du clone. Les définitions qui ont changé (métiers, manifestes
de 27 modules → permissions, navigation) sont précisément ce que le DEV distant doit recevoir.

Constat transversal (production comprise) : ce comportement s'applique **à chaque déploiement de production**, où
`SystemSeeder` tourne systématiquement — toute édition Platform de plans, marchés ou métiers y serait remise aux
valeurs du code. À traiter par une décision distincte (non modifié ici).

### 5.2 Exécution sur clone (`clone-test.sh`, étape 5, avant ADR-832)

Le script prend un instantané des tables de catalogue (**hors horodatages**) et des `CHECKSUM TABLE`, exécute
`SystemSeeder` (`APP_ENV=production`, cache et mail en mémoire, files sur le clone, temps réel coupé), puis classe :
- **KO** : table hors catalogue modifiée (donnée de société) ; valeur existante modifiée/retirée dans `plans`,
  `markets`, `market_rule_sets`, `convention_collective_rules`, `platform_role_permission`, `platform_payment_method_rules` ;
- **ARB** (à arbitrer avant exécution distante) : valeur existante remplacée dans `jobdomains`, `platform_modules`,
  `company_permissions`, `field_definitions`, `document_types` ;
- **OK** : inchangé ou ajouts seulement.

**Exécution de validation** (clone simulé du DEV local, `leezr_clone_devsim831d`, 2026-11-19) : **vert** — aucune table
hors catalogue modifiée ; plans, marchés, règles de paie, conventions, règles de paiement **inchangés** ; ajouts :
4 permissions société (`workforce.view_all`, `workforce_referral.refer/review/manage`), module `workforce_referral`,
thème de notification de parrainage, 2 permissions Platform pour le rôle `admin` ; **ARB** : `jobdomains` logistique —
rôle **chauffeur recommandé** pour les nouvelles sociétés passant de `theme.full, members.team_view,
settings.company_info, documents.access, shipments.delivery` à `theme.full, shipments.delivery` (moindre privilège,
ADR-823 ; ne touche pas les rôles des sociétés existantes, sujet D-8). Le DEV local lui-même n'avait pas ces
permissions et ce module : confirmation qu'aucun déploiement DEV ne resynchronise les catalogues.

~~Arbitrages requis avant `SystemSeeder` distant~~ — remplacés par §5.3.

### 5.3 Synchronisation système corrigée (ADR-832) — conduite pour le DEV distant

| Question | Réponse fondée sur les tests |
|---|---|
| La synchronisation peut-elle écraser une décision Platform ? | **Non** : une valeur administrée n'évolue que si elle est restée égale à la dernière valeur écrite par le code ; sinon conservée et signalée (`conflict`). Sur une base sans référence (le DEV distant), **toute** valeur différente du code est conservée. Prouvé : clone du DEV local, 10 décisions fictives → 10 préservées ; même scénario avec l'ancien code → 8 écrasées |
| Installe-t-elle les nouveautés ? | Oui : module `workforce_referral`, 4 permissions Company, thème de notification, permissions Platform nouvelles offertes au rôle `admin` |
| Touche-t-elle aux sociétés, presets, historiques ? | Non : empreintes `CHECKSUM TABLE` identiques hors catalogue ; presets identiques |
| Idempotente ? | Oui : 2ᵉ passage sans aucune écriture (clone DEV et installation vierge MySQL) |
| **Doit-elle tourner automatiquement au déploiement DEV ?** | **Oui** (étape [5.6] ajoutée à `deploy/deploy_release.sh`, effective au premier push autorisé) : elle ne peut plus effacer une configuration et le DEV n'a jamais reçu les nouvelles définitions. Condition : `clone-test.sh` vert sur la copie réelle (étape 5 = exactement ce que fera le déploiement) |

`clone-test.sh` étape 5 exécute désormais `system:sync --force --json` sur le clone, puis : **KO** si une table hors
catalogue change, si une valeur administrée est remplacée (`evolved`/`adopted` sans décision) ou si le 2ᵉ passage
écrit quoi que ce soit ; **ARB** pour chaque conflit (valeur conservée, alignement possible par `--adopt`).
Validation locale (dump simulé du DEV local, `leezr_clone_devremote_sim832b`, référence de schéma = clone du DEV local
migré `leezr_clone_sync832b`) : **vert** ; 3 ARB, qui sont les vrais écarts historiques du DEV local :

| ARB | Valeur conservée | Valeur du code | Décision à prendre |
|---|---|---|---|
| `jobdomains:logistique:default_roles` | chauffeur recommandé avec `members.team_view`, `settings.company_info`, `documents.access` | `theme.full`, `shipments.delivery` (ADR-823) | adopter (recommandé : ne touche que les **nouvelles** sociétés) ou conserver |
| `platform_role_permission:admin:member:view_system_health` | absente du rôle `admin` | déclarée | adopter ou laisser retirée (super_admin non concerné) |
| `platform_role_permission:admin:member:view_usage_monitoring` | idem | déclarée | idem |

Adopter sur le DEV distant, après déploiement et seulement sur décision :
`php artisan system:sync --force --adopt=jobdomains:logistique:default_roles` (simulable avec `--dry-run`).
Le clone de la copie réelle peut révéler d'autres conflits (éditions Platform réelles) : ils seront conservés par défaut.

**Note de méthode** : la référence de schéma de l'étape 4 (`REFDB`) doit être le DEV local **migré** (ou un clone
migré de celui-ci) : une base installée à neuf diffère du DEV par le nom d'un index historique
(`wf_sig_enroll_company_idx`), écart sans rapport avec ADR-832.

## 6. Sauvegardes et clone (LOT 4)

| # | Étape | Outil | Contrôle d'intégrité |
|---|---|---|---|
| S1 | Dump `c1leezrdev` (routines, triggers, `--single-transaction`) | `remote-backup.sh` | `gzip -t` ; `CREATE TABLE` du dump = tables de la base ; marqueur `Dump completed` ; `sha256` ; comptages par table (`.counts.tsv`) |
| S2 | `shared/storage/app` + copie `.env` (600) | `remote-backup.sh` | `tar -tzf` ; `sha256` |
| S4 | Rapatriement sur le poste | `scp` (compte web3) | `shasum -a 256 -c` côté poste |
| S3.1 | Restauration d'essai **hors serveur** dans un clone marqué `leezr_clone_devremote_*` | `clone-test.sh` étapes 1–2 | comptages identiques à `.counts.tsv` ; clone reconnu par la garde de recette |
| S3.2 | Migrations RC2 sur le clone | étape 3 | `migrate` sans échec ; 0 en attente |
| S3.3 | Schéma et données | étape 4 | colonnes et index identiques au schéma RC2 de référence ; **aucune table n'a perdu de lignes** |
| S3.4 | `system:sync` (ADR-832) | étape 5 | §5.3 : aucune valeur administrée remplacée ; 2ᵉ passage sans écriture |
| S3.5 | Tâches nouvelles | étape 6 | `signature-expire --dry-run` |

`clone-test.sh` validé localement de bout en bout (4 exécutions, 3 corrections : lignes GTID ignorées à l'import,
limite `GROUP_CONCAT`, horodatages exclus ; garde testée en `APP_ENV=local`). Clones de validation conservés :
`leezr_clone_devsim831`, `…831b`, `…831c`, `…831d` (suppression soumise à autorisation).

**Ce qu'une restauration de code n'annule pas** : les migrations. Elles s'exécutent avant la bascule ; un retour à
l'ancienne release laisse le schéma RC2 en place. C'est sans risque ici (migrations additives, ancien code compatible) ;
le retour des **données** n'est possible que par restauration de S1, sur décision humaine, avec perte des écritures
postérieures.

## 7. Retour arrière

| Situation | Action |
|---|---|
| Échec avant bascule | rien : ancienne release active (vérifier `readlink -f current`) ; migrations éventuellement appliquées, compatibles |
| Régression applicative | `deploy/rollback.sh` **puis** redémarrer les workers `ai`/`default` du site (le script ne le fait pas) ; schéma conservé |
| Données dégradées | restauration de S1 (décision humaine) |
| Tâche planifiée indésirable | suspension depuis l'écran Platform des tâches planifiées ; sinon ligne de cron commentée (panneau ISPConfig) |
| Temps réel | `REALTIME_DRIVER=null` + `config:cache` |

**Interdit** : `migrate:rollback` (les `down()` suppriment tables et colonnes avec leurs données), `migrate:fresh`,
`leezr:dev-reset`.

## 8. Procédure (après autorisations)

1. Autoriser et exécuter `remote-audit.sh` ; reporter le rapport (§3).
2. Configuration : `BILLING_ASYNC_QUEUES_ENABLED=false`, `REALTIME_DRIVER=null`, préfixes Redis propres à web3,
   `DOCUMENT_SCANNER` décidé ; `APP_ENV` confirmé (si différent de `production` : `leezr:dev-bootstrap` active 7 modules
   Workforce sur `leezr-logistics` et `PaymentModuleSeeder` appellerait Stripe — à éviter).
3. `remote-backup.sh` ; rapatriement S4.
4. `clone-test.sh` sur le dump rapatrié (`REFDB` = DEV local migré) → **vert** obligatoire ; arbitrages des conflits §5.3.
5. `git fetch origin` autorisé ; `git merge-base --is-ancestor origin/dev dev` vrai ; `git log dev..origin/dev` vide.
6. `git push origin dev` = déploiement (suivre GitHub Actions jusqu'à « DEPLOY OK »).
7. Sur le serveur : `migrate:status` → 0 en attente ; lire `shared/storage/logs/system-sync-<horodatage>.json` (même conflits que le clone) ; `--adopt` seulement sur décision (§5.3).
8. Recette `post-deploy-recette.md`.

## 9. Recette après déploiement (LOT 5)

`docs/bmad/leezr-4-0/dev-sync/post-deploy-recette.md` : P0 santé (5 contrôles), Platform A1–A8 (JobDomains, presets,
modules, plans, sociétés, facturation, tâches), Company C1–C7, RBAC et isolation R1–R4 — **en lecture seule** ;
écritures P1–P10 **uniquement** dans une société fictive dédiée créée pour la recette (inscription Logistique,
onboarding, embauche, planning avec confirmation de sureffectif, pointage, congés, préparation de paie sans calcul
définitif, expéditions, document, suspension finale). Aucun paiement réel ; aucune campagne E2E sur le serveur.

## 10. Risques résiduels

| Risque | Niveau | Traitement |
|---|---|---|
| `origin/dev` avancé depuis le 09/11 | moyen | étape 5 |
| Déploiement du 09/11 échoué / état inconnu | moyen | R1/R2 |
| Pas de sauvegarde automatique en DEV | élevé si oublié | S1–S4 bloquants |
| Édition Platform écrasée par la synchronisation | **traité (ADR-832)** | conservée et signalée ; clone étape 5 |
| Même écrasement à chaque déploiement de production | **traité (ADR-832)** | production passe à `system:sync` ; premier passage : conflits `unknown` conservés |
| Conflits signalés mais non traités | faible | visibles à chaque déploiement (journal, JSON) ; décision `--adopt` |
| `APP_ENV` ≠ `production` | moyen | R3 |
| Migrations appliquées avant la bascule, non annulées par un retour de code | faible (additives) | S1 pour les données |
| `rollback.sh` sans redémarrage des workers | faible | étape manuelle §7 |
| Workflow sans tests | faible | tests faits localement sur le même SHA |
| Identité SSH CI partagée DEV/production avec `sudo` | moyen | audit avec le compte du site ; séparation des clés à décider |

## 11. Opérations nécessitant autorisation

1. SSH en lecture seule (compte web3) pour `remote-audit.sh`.
2. `remote-backup.sh` (écritures dans `shared/backups` uniquement) et rapatriement.
3. `clone-test.sh` sur le dump rapatrié (poste local, clone marqué).
4. Arbitrages des conflits `system:sync` (§5.3).
5. `git fetch origin`.
6. `git push origin dev` = déploiement automatique.
7. `--adopt` éventuels sur le DEV distant (la synchronisation elle-même fait partie du déploiement).
8. Choix `DOCUMENT_SCANNER` (ClamAV = opération root via ISPConfig).
9. Recette avec écritures sur la société fictive.
10. Toute restauration de S1.
11. Suppression des clones locaux (`leezr_audit831sync`, `leezr_clone_devsim831*`, `leezr_recipe831*`, et ADR-832 : `leezr_clone_sync832`, `…832b`, `…832old`, `…832old2`, `leezr_clone_blank832`, `leezr_clone_devsim832`, `leezr_clone_devremote_sim832`, `…832b`).

Hors périmètre et inchangés : production (D-11), Company 1 (D-13), remédiation RBAC historique (D-8), SSE en production
(D-10), Billing asynchrone (D-17).
