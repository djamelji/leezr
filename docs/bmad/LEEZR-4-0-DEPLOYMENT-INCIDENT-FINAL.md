# Leezr 4.0 — incident webhook, sécurisation des déploiements, intégrité des données — rapport final (ADR-833)

Date : 2026-11-19 · Périmètre : analyse locale, lectures DEV (`web3`), simulations sur clones locaux.
**Aucun push, aucun déploiement, aucune écriture sur le DEV distant, aucun accès à la production, aucun secret dans ce document.**

## 0. Synthèse

| Sujet | État |
|---|---|
| Cause du double déploiement | **Établie** : webhook GitHub actif → `leezr.com/webhook.php` (site de production) → `current/deploy.sh` de la cible |
| Neutralisation | **Prête dans le code** (gardes artisan + coquilles inertes) ; suppression du webhook GitHub **à autoriser** |
| Protection DevSeeder | **Codée et prouvée sur clone** (refus, base inchangée) |
| Identifiants | Incident **ADR-663 toujours ouvert** ; secret présent dans l'historique d'un dépôt **public** depuis 2026-04-19 ; rotation **à autoriser** |
| Données sociétés 1 et 2 | Écarts établis ligne à ligne ; restauration ciblée **testée sur clone (verte)**, **non appliquée** |
| Conflits SystemSync | 4 analysés ; adoption simulée sans effet sur les sociétés ; **décision Platform** |
| Recette navigateur distante | **Non jouée** (préalable non rempli : webhook encore actif) |

## 1. Cause exacte du double déploiement

1. Le dépôt GitHub `djamelji/leezr` a un webhook **actif** (id `595692752`, créé le 2026-02-11, événement `push`) vers
   `https://leezr.com/webhook.php`.
2. `public/webhook.php` (servi par `web2`, le site de production) valide la signature HMAC, puis associe
   `refs/heads/dev` → `/var/www/clients/client1/web3` et `refs/heads/main` → `/var/www/clients/client1/web2`, et lance en
   arrière-plan `bash <cible>/current/deploy.sh <branche> <cible>`, **avec l'utilisateur PHP de la production (`web2`)**.
3. `deploy.sh` (ancien pipeline) : `git clone --depth=1 -b <branche>` dans `releases/AAAAMMJJ_HHMMSS`, `composer install`,
   **`migrate --force`**, **`db:seed SystemSeeder`**, puis **sur `dev` : `db:seed DevSeeder`**, `pnpm build`,
   réécriture de `APP_BUILD_VERSION` dans `shared/.env`, bascule de `current`, puis **`rm -rf` des releases au-delà des 3
   plus récentes**.
4. En parallèle, GitHub Actions (`deploy.yml`) déploie la même branche par artefact (`deploy/deploy_release.sh`).
5. Sur DEV, `DevSeeder` échoue toujours sur `Unable to create a directory … releases/<ts>/storage/app/private/documents/seed`
   (droits `web2` sur l'arborescence `web3`) : le script s'arrête **après** ses écritures en base et **avant** la bascule.
   Historique `deploy.log` DEV : **489 démarrages** de l'ancien pipeline, **9 complets** (2026-02-17/18), les autres
   arrêtés par cet échec. Chaque push sur `dev` depuis février a donc rejoué `DevSeeder` sur la base DEV réelle.
6. `docs/deploy.md` déclarait ce système « déprécié… n'est plus utilisé » : **le webhook n'a jamais été supprimé** (cause
   racine organisationnelle).

## 2. Déclencheurs identifiés

| Déclencheur | Événement | Cible | Rôle | Verdict |
|---|---|---|---|---|
| `.github/workflows/deploy.yml` « Build & Deploy » | push `dev`, `main` | web3 / web2 | build CI + artefact + `deploy_release.sh` (sauvegarde PROD, migrate, `system:sync`, workers, bascule) | **à conserver — mécanisme unique** |
| Webhook `595692752` → `leezr.com/webhook.php` → `deploy.sh` | push (toutes branches ; `dev` et `main` traitées) | web3 / web2 | ancien pipeline git-clone | **redondant — à supprimer** |
| `.github/workflows/ssl-ops.yml` | push `dev` limité à `scripts/ssl-ops-dev.sh` et au workflow | VPS (sudo) | opérations SSL DEV | hors déploiement applicatif ; conservé |
| `.github/workflows/fix-apache.yml` | manuel (`workflow_dispatch`) | VPS | correctif ponctuel marqué « DELETE after use » | à retirer (hors périmètre) |
| crontab `web3` | chaque minute | DEV | `schedule:run` + relance de secours des workers | pas un déploiement |

**Production :** aucun mécanisme n'est indispensable hors de GitHub Actions, qui déploie `main` → web2 (sauvegarde
`mysqldump` avant `migrate`, ADR-654). Le webhook n'apporte rien de plus. Risque non vérifié faute d'accès autorisé : à
chaque push sur `main`, l'ancien `deploy.sh` tourne sur la production. Si `pnpm` est présent et que les droits le
permettent, il peut **basculer `current` vers une release construite sur le serveur et supprimer des releases CI**
(`KEEP_RELEASES=3`). Sinon, il s'arrête après `migrate` et `SystemSeeder`. Ce point se lève en lisant
`web2/shared/storage/logs/deploy.log`, ce qui demande une autorisation (§10).

## 3. Stratégie de neutralisation

Ordre recommandé ; les étapes 2 à 4 demandent une autorisation :

1. **Code (fait, local)** :
   - `App\Core\System\DeploymentGuard` + écouteur `CommandStarting` : artisan refuse toute commande lancée depuis
     `releases/AAAAMMJJ_HHMMSS`. Les releases CI (`AAAAMMJJHHMMSS[_sha]`) ne sont pas concernées. L'ancien script
     exécute l'artisan **du code qu'il vient de cloner**, donc dès le prochain push il échoue à `composer install`
     (`package:discover`) avant toute écriture en base. Prouvé : `package:discover` → exit 1 depuis
     `…/releases/20261119_162750`.
   - `deploy.sh` → coquille inerte (journalise « RETIRED », exit 0).
   - `public/webhook.php` → 410, n'exécute rien. Ces deux fichiers prennent effet sur un environnement dès que sa
     release courante les contient.
   - `docs/deploy.md` mis à jour.
2. **Désactiver puis supprimer le webhook GitHub `595692752`** :
   - Settings → Webhooks → décocher « Active » (réversible), puis supprimer.
   - En ligne de commande, il faut le scope `admin:repo_hook` (`gh auth refresh -s admin:repo_hook`), puis
     `gh api -X PATCH repos/djamelji/leezr/hooks/595692752 -F active=false`.
   - Cette étape protège immédiatement **les deux** environnements, sans toucher au serveur de production.
3. Pousser le correctif sur `dev` (un seul run CI).
   - Si le webhook est encore actif à ce moment, l'ancien `deploy.sh` s'arrêtera au premier appel artisan. Seuls
     resteront un clone et un `composer install` inutiles, sans écriture en base.
4. Plus tard, après lecture autorisée du `deploy.log` de production, promouvoir sur `main` par le pipeline CI.
5. Nettoyage (autorisation) : release orpheline `web3/releases/20261119_162750` et `.pnpm-store` (propriétaire `web2`).

Une fois l'étape 2 faite, **un seul mécanisme reste actif par environnement** : GitHub Actions.

## 4. Protection contre DevSeeder

`DeploymentGuard::assertDemoSeedAllowed()` est la première instruction de `DevSeeder::run()`.

| Contexte | Décision |
|---|---|
| `APP_ENV=production` (quel que soit l'état de la base) | **refus** |
| `local`, `testing` | autorisé |
| base sans aucune société (première population via `leezr:dev-bootstrap`, `dev:reset` après purge) | autorisé |
| clone de recette marqué (`RecipeDatabaseGuard`) | autorisé |
| **staging / DEV distant peuplé** (cas de l'incident) | **refus** |

Preuves :
- `tests/Unit/Adr833DeploymentGuardTest.php` : 3 tests, 16 assertions.
- Clone `leezr_clone_guard833` (3 sociétés, marqueur ne le nommant pas), `APP_ENV=staging` : `db:seed DevSeeder` → exit 1,
  « ADR-833: DevSeeder refused », `CHECKSUM` de toutes les tables **identique** avant et après.
- Défense en profondeur : même un ancien `deploy.sh` encore en place échoue avant `DevSeeder` grâce à la garde de
  release (§3).

## 5. Rotation des identifiants

**Services concernés** (aucun secret reproduit) :

| Service | Source | État |
|---|---|---|
| SSH `jliouidevleezr` (web3, DEV) | mémoire locale de l'agent | **valide** (connexion réussie le 2026-11-19) |
| SSH `jliouiprodleezr` (web2, PROD) | même mot de passe d'après la mémoire locale | non testé (production interdite) — à considérer compromis |
| Panneau ISPConfig (admin) | même mot de passe d'après la mémoire locale | non testé — à considérer compromis |
| Compte administrateur Platform **de production** | un ancien `PlatformSeeder` (commit `3302e090`, public) contenait ce secret comme mot de passe admin ; le seeder actuel ne réécrit plus le mot de passe d'un admin existant | **à vérifier** ; changer le mot de passe s'il n'a jamais été modifié |

**Exposition** :
- **Dépôt GitHub public** : 6 commits de l'historique (2026-04-19 → 2026-10-30) contiennent le secret :
  - `database/seeders/PlatformSeeder.php` ;
  - `docs/bmad/04-decisions.md` ;
  - `docs/audit-2026-10/*` ;
  - `docs/refactor-2026-10/registre-progression.md`.

  HEAD n'en contient plus aucun. Le secret doit être considéré comme **public**, bien au-delà de l'incident d'aujourd'hui.
- Poste local :
  - deux fichiers de mémoire de l'agent ;
  - `~/.claude/history.jsonl` et `~/.claude/file-history/*` ;
  - 3 transcripts de session.

  Absent de `~/.ssh` et de l'historique shell.
- Le transcript de cette session (sortie de commande du 2026-11-19).

**Scripts et automatisations utilisant ces identifiants** :
- Aucun dans le dépôt.
- GitHub Actions utilise la clé `secrets.VPS_SSH_KEY`, pas de mot de passe.
- Seul l'outillage local de l'agent lit le mot de passe : la mémoire et le script `scratchpad/rdev.sh`, qui le lit sans
  le contenir.

**Constats SSH (DEV, lecture)** :
- `sshd_config` déclare `PasswordAuthentication no`, mais l'`Include sshd_config.d/*.conf` est placé **avant**. Un
  fichier de ce dossier, illisible pour le compte du site, réactive manifestement l'authentification par mot de passe :
  avec sshd, c'est la première valeur qui l'emporte.
- `authorized_keys` de `jliouidevleezr` : 2 clés, `ED25519 SHA256:yltpSJSk…` et `RSA-3072 SHA256:xHJj8Bke…`.
  Propriétaires à confirmer.
- Une seule session ouverte : celle de l'agent.

**Procédure de rotation** (canal sécurisé, administrateur root ; nouveau secret jamais dans Git, jamais dans une session
de l'agent) :
1. Vérifier un accès de secours par clé (clé CI / clé administrateur) **avant** tout changement.
2. Pour `jliouidevleezr`, `jliouiprodleezr` et le panneau ISPConfig, poser **des secrets distincts** via `passwd` et
   le panneau.
3. Corriger le fichier de `/etc/ssh/sshd_config.d/` qui réactive le mot de passe :
   `PasswordAuthentication no`, `KbdInteractiveAuthentication no`, puis `sshd -t` et `systemctl reload ssh`. Ne jamais
   couper la dernière méthode d'accès.
4. Révocation :
   - lister les sessions (`who`, `ps -eo user,args | grep sshd-session`) et les fermer si besoin ;
   - retirer les clés non identifiées de `authorized_keys` ;
   - faire tourner `VPS_SSH_KEY` en cas de doute ;
   - changer le mot de passe de l'administrateur Platform de production.
5. Après la rotation, **supprimer le mot de passe des fichiers de mémoire locaux** et utiliser une clé dédiée au compte
   DEV. Envisager `git filter-repo` uniquement par une opération coordonnée : force-push, hors périmètre.

**Validation après rotation** :
- l'ancien mot de passe est refusé (`ssh -o PubkeyAuthentication=no` doit échouer) ;
- la clé dédiée est acceptée ;
- un run CI `workflow_dispatch` ou le prochain push passe ;
- le panneau ISPConfig est accessible avec le nouveau secret ;
- la connexion Platform de production fonctionne avec le nouveau mot de passe.

## 6. Différences des données (sociétés 1 et 2)

**Méthode** : comparaison ligne à ligne de **toutes** les tables entre deux états.
- **Attendu** : la sauvegarde d'avant déploiement, migrée puis synchronisée, soit ce que GitHub Actions seul aurait
  produit (`leezr_clone_devremote_20261119`).
- **Réel** : un instantané lu sur le DEV à 17:05 (`leezr_clone_devpost_20261119`, dump
  `~/leezr-backups/dev-post-incident-20261119T170556Z/`).

Comparaison par clé primaire ; les permissions sont comparées par clé métier, car leur `id` diffère selon l'ordre
d'insertion. Le JSON est comparé sémantiquement (MariaDB le conserve tel qu'écrit, MySQL le normalise) et les colonnes
sensibles par empreinte uniquement. Outil : `docs/bmad/leezr-4-0/incident-833/table-diff.py` ; preuves :
`storage/app/recipe-clones/incident-833/diff/`.

### 6.1 Modifications introduites par DevSeeder (16:28:09 → 16:28:21)

| # | Donnée | Avant (sauvegarde) | Après (seeder) |
|---|---|---|---|
| S1 | Rôles `dispatcher`, `ops_manager` des sociétés 1 et 2 | `documents.configure` | retiré |
| S2 | Rôles `manager`, `owner` des sociétés 1 et 2 | sans `documents.view_confidential` (permission créée le jour même) | ajoutée |
| S3 | Abonnement id 2, société 2 (`pro`) | `expired`, `is_current` NULL, essai terminé le 2026-11-16, expiré par le planificateur le 2026-11-17 | `trialing`, `is_current`=1, essai jusqu'au 2026-11-26 |
| S4 | Pièces de la société 2 : `id_card`, `insurance_certificate`, `kbis` | obligatoires | facultatives |
| S5 | `field_activations` société 1 | — | +1 : `naf_code` activé (aucune valeur saisie) |
| S6 | `company_modules` | — | +2 : `workforce_referral` **désactivé** pour les sociétés 1 et 2 |
| S7 | `company_preset_snapshots` | — | +2 instantanés `trigger=registration` (sociétés 1 et 2) |
| S8 | `users` 1–4, `platform_users` 1 | mot de passe de démonstration public | **même valeur**, re-hachée (`password_set_at` mis à jour) |
| S9 | `field_activations` (21), `document_type_activations` (4), `jobdomains`, `platform_audit_logs` | — | `updated_at` touchés sans changement de valeur ; 2 journaux `jobdomain.preset_recommendations_updated` |

Rôle chauffeur : **inchangé**. Aucune suppression de ligne. Rien de touché côté paie, pointage, documents déposés,
expéditions ou contrats.

### 6.2 Modifications légitimes intervenues depuis (conservées)

| Donnée | Origine |
|---|---|
| `company_audit_logs` `auth.login` à 16:39 et 16:40 | connexions de recette de l'agent (propriétaire, chauffeur) |
| `auth.login` à 16:53, `shift.created` à 16:55 (créneau 20, opération de planning 6), disposition du tableau de bord (16:54) | **activité d'un utilisateur réel** sur la société 1 |
| `member_documents` / `document_requests` `origin_employee_id` (10) | réconciliation applicative à la lecture (`PersonDocumentContinuity::reconcile`) |
| `company_document_settings` +1 (société 1) | réglages créés à la lecture par l'application |
| `scheduled_task_runs`, `scheduler_heartbeats`, `billing_job_heartbeats`, `platform_alerts`, `cache`, `sessions` | fonctionnement normal |

### 6.3 Données inchangées et artefacts

- Catalogue système (modules, permissions, presets, contributions, documentation, exigences) : mêmes valeurs. Seuls
  diffèrent `created_at`, `uuid` et `published_at`, car chaque côté a créé les lignes à son heure.
- Tarifs, TVA, sociétés et membres : inchangés.
- Société 3 : aucune modification.

### 6.4 Dépendances

Vérifié sur l'instantané :
- 0 valeur pour `naf_code` ;
- 0 configuration, 0 réglage et 0 parrainage `workforce_referral` ;
- 0 facture et 0 événement de facturation pour la société 2 depuis 16:27.

Toutes les restaurations S1 à S6 sont donc sans dépendance. Note : l'état d'avant déploiement est lui-même le produit
des passages antérieurs de `DevSeeder` (l'essai du 2026-11-09 venait déjà de lui). La restauration vise l'état du
**2026-11-19 16:19**, pas un état « d'origine ».

## 7. Restauration ciblée (testée sur clone)

`docs/bmad/leezr-4-0/incident-833/restore-companies-1-2.php` : une seule transaction, localisation par clés métier.
- **Préconditions** : les lignes sont exactement dans l'état laissé par le seeder, sinon refus.
- **Postconditions** : l'état d'avant déploiement.
- Simulation par défaut (rollback).
- Application seulement avec `--apply --confirm-database=<base réelle>`.
- Refus de toute base de production.
- Le wrapper serveur `restore-remote.sh` (non exécuté) lit les identifiants de `web3/shared/.env` dans un fichier 600
  temporaire.

| Élément | Restauré | Gardé (raison) |
|---|---|---|
| S1, S2 permissions | ✔ | |
| S3 abonnement société 2 | ✔ retour à `expired` | |
| S4 pièces obligatoires | ✔ | |
| S5 `naf_code` société 1 | ✔ | |
| S6 lignes `workforce_referral` | ✔ | |
| S7 instantanés de preset | | historique |
| S8 mots de passe démo | | même valeur |
| S9 horodatages et journaux | | cosmétique / audit |
| §6.2 | | légitime |

Résultats sur clone (`leezr_clone_restore833`, copie de l'instantané post-incident) :
1. Simulation : 12 opérations OK, 6 postconditions OK, rollback. `CHECKSUM` des 5 tables identique à celui de la source.
2. `--apply` : transaction validée.
3. Comparaison à l'état d'avant déploiement :
   - permissions par clé : **0 écart** ;
   - `subscriptions` et `company_modules` : **identiques** ;
   - restent uniquement des `updated_at` (4 activations de documents, 21 activations de champs), conservés comme prévu.
4. 2ᵉ exécution : **refus en bloc**, rollback (préconditions non remplies), aucune double application.

## 8. Conflits SystemSync

| Conflit | Valeur actuelle (Platform/DEV) | Valeur du code | Conséquence d'une adoption |
|---|---|---|---|
| `jobdomains:logistique:default_fields` | 34 champs | **+ `naf_code`** (ordre 2), rien d'autre | les **nouvelles** sociétés Logistique auront le champ code NAF ; aucune société existante touchée |
| `jobdomains:logistique:default_roles` | identique sauf le chauffeur | chauffeur sans `documents.access`, `members.team_access`, `settings.company_info` (moindre privilège, ADR-823) | les **nouveaux** chauffeurs naissent en moindre privilège ; rôles existants inchangés (pas de migration RBAC historique) |
| `notification_topics:billing.payment_received:label` | « Payment Received » | « Payment Confirmed » | libellé du sujet de notification dans Platform et dans les préférences |
| `…:icon` | `tabler-cash` | `tabler-receipt` | icône seule |

Simulation sur clone (`system:sync --dry-run` avec les 4 `--adopt`) : `{"adopted":4}`, aucune autre écriture. Seuls
`jobdomains` et `notification_topics` sont concernés, jamais les tables des sociétés. Recommandation : adopter les
deux conflits Logistique (cohérents avec ADR-823 et le catalogue des champs). Les deux conflits cosmétiques sont au
choix de Platform. **Rien n'a été adopté.**

## 9. Recette distante

Le préalable n'est pas rempli : webhook encore actif et gardes non déployées, car le push est interdit. La recette
navigateur (Platform, Company, Modules, JobDomains, Plans, Planning, Workforce, Logistics, Documents, Permissions,
isolation) **n'a pas été jouée**. Contrôles API déjà faits lors du déploiement :
- P0 vert ;
- Platform 8/8 et Company 15/15 en 200 ;
- isolation : 404 pour une société inconnue, 403 pour une société non membre ;
- chauffeur → paie : 403.

Ils figurent dans `LEEZR-4-0-DEV-DEPLOYMENT-FINAL.md`. Les 465 E2E n'ont pas été lancés contre le DEV distant. Constat
de sécurité DEV : les comptes de démonstration, **dont l'administrateur Platform**, ont un mot de passe public alors que
`dev.leezr.com` est exposé sur Internet. À traiter avec la rotation (restriction d'accès DEV ou mots de passe
non publics).

## 10. Actions nécessitant autorisation

| # | Action | Où | Effet |
|---|---|---|---|
| A1 | Désactiver puis supprimer le webhook `595692752` | GitHub (paramètres du dépôt) | coupe l'ancien pipeline pour DEV **et** PROD, sans toucher aux serveurs |
| A2 | Pousser le commit ADR-833 sur `dev` | GitHub → CI | déploie les gardes ; 1 run CI |
| A3 | Lire `web2/shared/storage/logs/deploy.log` et l'état des releases | **production**, lecture seule | établit ce que l'ancien pipeline a fait en production |
| A4 | Rotation des identifiants (3 comptes + admin Platform PROD), correction de `sshd_config.d` | serveur (root) + ISPConfig | clôt ADR-663 |
| A5 | Restauration S1–S6 sur le DEV : `bash restore-remote.sh`, puis `--apply` | DEV `web3` | remet les sociétés 1 et 2 à l'état d'avant déploiement |
| A6 | Adopter tout ou partie des 4 conflits (`system:sync --adopt=…`) | DEV (Platform) | aligne le catalogue sur le code |
| A7 | Recette navigateur avec écritures dans une société fictive dédiée | DEV | lot 5 |
| A8 | Supprimer la release orpheline `20261119_162750` et `.pnpm-store` (propriétaire `web2`) | DEV | nettoyage |
| A9 | Supprimer les clones locaux de l'incident (`leezr_clone_devremote_20261119`, `devpost`, `guard833`, `restore833`) et ceux de l'ADR-832 | poste | après décision |

## 11. Livrables

- Code :
  - `app/Core/System/DeploymentGuard.php` ;
  - `app/Providers/AppServiceProvider.php` (écouteur) ;
  - `database/seeders/DevSeeder.php` (garde) ;
  - `deploy.sh` et `public/webhook.php` (coquilles inertes) ;
  - `tests/Unit/Adr833DeploymentGuardTest.php`.
- Outils : `docs/bmad/leezr-4-0/incident-833/` (`table-diff.py`, `restore-companies-1-2.php`, `restore-remote.sh`).
- Docs : ADR-833 dans `04-decisions.md`, `docs/deploy.md`, ce rapport.
- Sauvegardes poste :
  - `~/leezr-backups/dev-20261119T161942Z/` (avant déploiement) ;
  - `~/leezr-backups/dev-post-incident-20261119T170556Z/` (après incident).
