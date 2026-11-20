# Leezr 4.0 — clôture de l'incident ADR-833 : état de sécurité et opérations à autoriser

Date : 2026-11-20 · Complète `LEEZR-4-0-DEPLOYMENT-INCIDENT-FINAL.md`. Tout ce qui suit a été **préparé et testé sur clones**.
**Rien n'a été exécuté** sur GitHub, le DEV distant ou la production. Aucun secret dans ce document.

## 1. État de sécurité au 2026-11-20

| # | Risque | Niveau | État |
|---|---|---|---|
| R1 | Webhook `595692752` actif : chaque push lance l'ancien `deploy.sh` sur DEV (`migrate`, `SystemSeeder`, `DevSeeder`) **et sur la production** (`main`) | **critique** | ouvert — gardes prêtes en local (commit non poussé) |
| R2 | Mot de passe SSH commun DEV / PROD / ISPConfig, dans l'historique d'un **dépôt GitHub public** depuis 2026-04-19, toujours valide | **critique** | ouvert (ADR-663) |
| R3 | Authentification SSH par mot de passe active malgré `PasswordAuthentication no` (un fichier de `sshd_config.d`, chargé en premier, la réactive) | élevé | ouvert |
| R4 | 6 comptes DEV au mot de passe public, dont le **super-administrateur Platform**, sur `dev.leezr.com` exposé sur Internet | élevé | ouvert — script de verrouillage prêt |
| R5 | Administrateur Platform de **production** peut-être encore au mot de passe compromis (ancien `PlatformSeeder` public) | élevé | à vérifier |
| R6 | Effets de l'ancien pipeline sur la production inconnus (bascules ? suppressions de releases ? `SystemSeeder` historique ?) | élevé | à auditer (lecture seule) |
| R7 | `rollback.sh` sans cible choisirait la release héritée inachevée `20261119_162750` sur DEV | moyen | à neutraliser (cible explicite ou nettoyage) |
| R8 | Sociétés 1 et 2 modifiées par `DevSeeder` | moyen | restauration prête, revérifiée le 2026-11-20 |
| R9 | 2 clés SSH non identifiées sur le compte DEV | à qualifier | inventaire fait |

## 2. Ordre des opérations — chacune demande une autorisation distincte

### OP-1 (A1) — Supprimer le webhook GitHub `595692752`

Vérifié le 2026-11-20 :
- c'est le **seul** webhook du dépôt : type `web`, actif, événement `push`, URL `https://leezr.com/webhook.php`,
  secret défini, créé le 2026-02-11 ;
- les workflows officiels ne sont pas concernés : `deploy.yml`, `ssl-ops.yml`, `fix-apache.yml`, et 9 workflows
  diagnostiques liés à des branches `ci/*` supprimées.

Le token `gh` actuel n'a pas le scope `admin:repo_hook`.

1. Vous : `! gh auth refresh -h github.com -s admin:repo_hook`
2. **Préserver les preuves** avant suppression, car GitHub efface l'historique des livraisons avec le webhook :
   `gh api "repos/djamelji/leezr/hooks/595692752/deliveries?per_page=100" > ~/leezr-backups/webhook-595692752-deliveries.json`
3. Désactivation (réversible) : `gh api -X PATCH repos/djamelji/leezr/hooks/595692752 -F active=false`
4. Contrôle : `gh api repos/djamelji/leezr/hooks/595692752 --jq .active` → `false`
5. Suppression définitive : `gh api -X DELETE repos/djamelji/leezr/hooks/595692752` ; contrôle :
   `gh api repos/djamelji/leezr/hooks --jq length` → `0`

Équivalent interface : Settings → Webhooks → `leezr.com/webhook.php` → décocher Active, puis Delete.

### OP-2 (A4) — Rotation des identifiants et durcissement SSH (administrateur root, canal sécurisé)

Le nouveau secret n'apparaît jamais dans Git ni dans une session de l'agent.

1. Avant tout, vérifier un accès de secours par clé (clé administrateur root ou clé CI).
2. Poser trois secrets **distincts** via `passwd` (root) et le panneau ISPConfig :
   - `jliouidevleezr` (web3) ;
   - `jliouiprodleezr` (web2) ;
   - administrateur ISPConfig.
3. Dans `/etc/ssh/sshd_config.d/`, le fichier qui contient `PasswordAuthentication yes` :
   - le passer à `no` ;
   - ajouter `KbdInteractiveAuthentication no` ;
   - `sshd -t`, puis `systemctl reload ssh`, en gardant une session root ouverte pendant le test.
4. Clés : faire identifier `ED25519 SHA256:yltpSJSk…` et `RSA-3072 SHA256:xHJj8Bke…` (compte `jliouidevleezr`) et
   retirer celles que personne ne reconnaît. Faire tourner `VPS_SSH_KEY` (secret GitHub) en cas de doute.
5. Sessions : `who` et `ps -eo user,args | grep sshd-session` ; fermer celles qui ne sont pas reconnues.
6. Pour un futur accès de l'agent : une clé **dédiée au seul compte DEV**, ajoutée par vous. Ensuite, supprimer le
   mot de passe des fichiers de mémoire locaux. Je peux le faire sur votre demande, une fois la rotation confirmée.
7. Validation :
   - l'ancien mot de passe est refusé (`ssh -o PubkeyAuthentication=no …` → `Permission denied`) ;
   - la clé dédiée est acceptée ;
   - le panneau ISPConfig fonctionne avec le nouveau secret ;
   - le prochain run CI (OP-4) se connecte toujours (clé `VPS_SSH_KEY`).

### OP-3 (A3) — Audit de la production, lecture seule

`docs/bmad/leezr-4-0/incident-833/prod-legacy-audit.sh` (testé sur une copie du journal DEV : il retrouve les 489
démarrages, 9 bascules et 2 nettoyages hérités).

Il lit uniquement :
- les liens `current` et `web`, `APP_BUILD_VERSION` et `APP_ENV` ;
- la liste des releases avec leurs propriétaires ;
- le bilan des deux pipelines dans `deploy.log` : démarrages, dernière étape, bascules, suppressions ;
- `migrate:status` ;
- la **présence** des clés `.env`, jamais leur valeur.

Exécution, avec le compte du site de production, après OP-2 :
`ssh <compte-web2>@<vps> 'bash -s' < docs/bmad/leezr-4-0/incident-833/prod-legacy-audit.sh > ~/leezr-backups/prod-legacy-audit.txt`

Questions tranchées par l'audit :
- la production tourne-t-elle sur une release héritée ?
- l'ancien pipeline a-t-il basculé `current` ou supprimé des releases CI ?
- combien de passages de `SystemSeeder` historique (avant ADR-832) ont pu écraser des réglages Platform ?

R5 se vérifie séparément, par l'administrateur, en essayant de se connecter à Platform en production avec l'ancien
secret, puis en changeant le mot de passe.

### OP-4 (A2) — Pousser les protections sur `dev`

Préconditions :
- OP-1 fait (sinon l'ancien script tourne encore, mais il échouera dès `composer install`, sans écriture en base) ;
- OP-2 fait, ou au minimum engagé.

Commits à pousser (avance rapide depuis `origin/dev` = `82e35416`) :
- `c9a05518` : rapport de déploiement ;
- `66141aef` : ADR-833, gardes ;
- le commit de ce runbook et de ses scripts.

Vérifié :
- la garde artisan ne concerne que `releases/AAAAMMJJ_HHMMSS` ;
- la CI construit dans `/home/runner/work/…` et déploie dans `releases/AAAAMMJJHHMMSS[_sha]`, sans aucun appel
  artisan avant le renommage ;
- PHPUnit : 4791 tests, 3 échecs historiques `ControllerSizeInvariant` seulement ; build vert.

Commandes :
1. `git push origin dev` (jamais `main`, jamais en force) ;
2. suivre `gh run watch` ;
3. côté DEV, contrôler :
   - `/api/public/version` = SHA poussé ;
   - aucune nouvelle release `AAAAMMJJ_HHMMSS` ;
   - le dernier bloc de `deploy.log` ne contient que la CI, ou un « RETIRED » / « ADR-833 refused » si le webhook
     avait survécu.

Retour arrière : **toujours avec une cible explicite**,
`bash deploy/rollback.sh /var/www/clients/client1/web3 20261109191527_459a7d3` (R7). Jamais sans argument tant que la
release héritée existe.

### OP-5 (A5) — Restaurer les sociétés 1 et 2 sur DEV

Revérification du 2026-11-20 : instantané frais lu sur DEV (`~/leezr-backups/dev-recheck-20261120T170646Z/`),
comparé ligne à ligne à celui du 2026-11-19 17:05.
- Seul le planificateur a écrit entre-temps : `fx_rates`, `platform_alerts`, `usage_snapshots`.
- La simulation de restauration sur ce nouvel instantané est **verte** : 12 opérations, 6 postconditions.

Le jour de l'application :
1. Nouvelle sauvegarde avec `remote-backup.sh`.
2. Copier sur le serveur `restore-companies-1-2.php` et `restore-remote.sh` (même dossier, home du compte DEV).
3. `bash restore-remote.sh` (simulation, rollback) : doit afficher « SIMULATION VERTE ».
4. Seulement alors : `bash restore-remote.sh --apply`.
5. Contrôle : nouveau dump, puis `table-diff.py` contre `leezr_clone_devremote_20261119` (permissions par clé =
   0 écart).

Si la moindre précondition a changé (activité sur ces lignes), le script refuse tout et annule.

### OP-6 (A6) — Adopter les deux évolutions Logistique

Testé sur clone, en application réelle :
- `{"adopted":2}` ;
- diff ligne à ligne : seuls `jobdomains.default_fields` (+ `naf_code`) et `jobdomains.default_roles` (chauffeur :
  `theme.full` et `shipments.delivery` seulement) changent, plus 2 lignes de référence dans `system_sync_baselines` ;
- **aucune table des sociétés modifiée** ;
- second passage : aucune écriture.

L'effet ne porte que sur les **futures** sociétés et les futurs chauffeurs : pas de migration RBAC historique.

Sur DEV, après OP-4 : `cd /var/www/clients/client1/web3/current && php8.4 artisan system:sync --dry-run --adopt=jobdomains:logistique:default_fields --adopt=jobdomains:logistique:default_roles`,
puis la même commande sans `--dry-run`, avec `--force`. Les deux conflits cosmétiques de notification restent
conservés.

### OP-7 (R4) — Verrouiller les comptes DEV au mot de passe public

`docs/bmad/leezr-4-0/incident-833/lock-demo-accounts.php`, testé sur clone :
- 6 comptes verrouillés, 4 sessions fermées ;
- les comptes à mot de passe privé ne sont pas touchés ;
- un second passage ne trouve plus rien.

Chaque mot de passe devient un hash aléatoire jamais affiché ; les jetons et les réinitialisations en attente sont
purgés. L'accès se récupère par « mot de passe oublié » : sur DEV, `MAIL_MAILER=log`, donc le lien est dans
`laravel.log`, lisible par l'administrateur du serveur.

À exécuter **après** OP-4 : sinon un `DevSeeder` hérité pourrait remettre le mot de passe public.

Commandes : le même wrapper que OP-5, en remplaçant le script. Simulation d'abord, puis `--apply --confirm-database=c1leezrdev`.

### OP-8 (A7) — Recette navigateur sur une société fictive dédiée

Préconditions : OP-1, OP-4 et OP-7 faits, OP-5 décidé.

Déroulé (`docs/bmad/leezr-4-0/dev-sync/post-deploy-recette.md`) :
1. Inscription Logistique d'une société `Recette 833 <date>` avec une adresse de recette (le lien de vérification est
   dans `laravel.log` du DEV).
2. Avec ses propres comptes, créés dans la recette :
   - Platform : lecture, avec un compte administrateur réinitialisé (OP-7) ;
   - écritures uniquement dans la société fictive : Company, Modules, JobDomains, Plans, Planning, Workforce,
     Logistics, Documents, Permissions ;
   - isolation : `X-Company-Id` d'une autre société → 403 ou 404.
3. En fin de recette, suspension de la société fictive depuis Platform.

Les 465 E2E ne sont pas lancés sur le DEV distant. Aucune écriture dans les sociétés 1 à 3.

### OP-9 — Nettoyage (après OP-3 et OP-5)

- DEV : release orpheline `releases/20261119_162750` et `.pnpm-store` (propriétaire `web2` : suppression par root
  ou ISPConfig).
- Poste : clones `leezr_clone_*833*`, `devremote_20261119`, `devpost_20261119` et ceux de l'ADR-832, une fois le
  rapport archivé.
- Les sauvegardes `~/leezr-backups/` sont conservées.

## 3. Ce qui clôt l'incident

- OP-1 : 0 webhook.
- OP-2 : ancien secret refusé partout, SSH par mot de passe coupé.
- OP-3 : production qualifiée.
- OP-4 : gardes en ligne sur DEV.
- OP-7 : 0 compte au mot de passe public.
- OP-5 et OP-6 : décidés.
- OP-8 : recette verte.
