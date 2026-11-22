# Leezr 4.2 — Prêt pour le déploiement DEV (ADR-851 / ADR-852)

**Date** : 2026-11-21. **Cible** : `dev.leezr.com` (`web3`, base `c1leezrdev`, MariaDB 11.8).

Rien n'a été poussé ni déployé. Aucun accès à DEV distant ni à la production n'a eu lieu : toutes les vérifications ont été faites en local ou en lecture seule sur GitHub.

## 1. Commit à déployer

- `origin/dev` = `82e35416`. La branche `dev` locale le dépasse de **51 commits** et ne lui manque rien : c'est une avance rapide, sans force push.
- **Commit final** : le commit de ce rapport, qui suit `33bf7dfd` (promo) et contient ADR-852.
- Les 5 commits ADR-833 (gardes DevSeeder et deploy.sh, runbook) font partie du lot et partent donc **en premier** dans le même push.

## 2. MariaDB : résultats sur un clone isolé

Environnement :
- MariaDB **11.8.10** local, port 3307, base `leezr_audit851m` marquée `_recipe_clone_identity`.
- Contenu : le dump local `leezr-20261121T213542Z.sql` (200 tables).
- La base DEV distante n'a pas été utilisée.

| Contrôle | Résultat |
|---|---|
| Rejeu des 6 migrations `2026_12_14` → `2026_12_19` | toutes `DONE`, en partant de l'état d'avant `12_14` |
| Données historiques | **0 ligne modifiée** sur les 200 tables existantes (seule `migrations` change). Empreinte de `actor_type` identique ; 0 écart sur le remplissage de `actor_role` |
| Nouvelles tables | `platform_company_creations`, `jobdomain_company_fields`, `company_data_exports` |
| Nouvelles colonnes | colonnes de la procédure exceptionnelle, `actor_role`, `funnel_id` |
| Idempotence | deuxième `migrate` : « Nothing to migrate », 0 changement |
| JSON | `manifest` est en `longtext utf8mb4_bin CHECK (json_valid)`, ce qui est l'équivalent MariaDB du JSON |
| Index unique (`funnel_id`, `step`) | les doublons sont refusés (1062). Plusieurs parcours historiques sans identifiant (`NULL`) coexistent ; essai fait dans une transaction annulée, sans résidu |
| `system:sync` | simulation puis application : 7 créations, 1 attribution, 5 conflits préservés (valeurs administrées). **Second passage : 0 création, seuls les 5 conflits restent** |
| Export Company via file `database` + `queue:work --once` | statut `ready` / `complete` : 11 fichiers, 12 entrées zip, manifeste valide, 0 job échoué |
| Révocation, puis purge `company-exports:prune` | fichier supprimé ; statuts `revoked` / `expired` |

**Défaut trouvé et corrigé (ADR-852).** Le premier `system:sync` attribuait `exceptional_company_export` au rôle `admin`. Désormais, cette permission n'est jamais proposée automatiquement, et le correctif a été revérifié sur MariaDB : 0 rôle la détient.

Sur DEV, la simulation affichera les mêmes **5 conflits** (`logistique.default_roles` et 4 permissions admin). Ce sont des décisions déjà connues (ADR-832) : rien n'est écrasé sans `--adopt`.

## 3. Correctif des codes promo (ADR-851, `33bf7dfd`)

Le code est d'abord validé côté serveur, avant toute création, par le même moteur que Company (`CouponService`). Cette validation contrôle :
- la validité et la période ;
- l'offre et la périodicité ;
- la limite globale et la limite par société ;
- la condition « premier achat ».

Ensuite :
- Le tarif final est affiché avant confirmation, avec la même règle pour l'aperçu et l'estimation.
- Un code refusé est signalé avec son motif, et rien n'est créé.
- Contre la double utilisation, la ligne du coupon est verrouillée et revérifiée dans la transaction.
- Le code est rattaché à l'abonnement courant, et les abonnements historiques ne sont pas modifiés.

**Preuves** :
- PHPUnit : 5/5, plus 94 tests liés.
- Navigateur (`audit842-promo-recipe`) : 2/2. Code valide −5,80 € rattaché pour 3 mois. Code inconnu refusé. Code expiré refusé à l'écran **et** par l'API (422), sans compte créé.
- Captures : `docs/bmad/leezr-4-2-audit/release/`.

## 4. Sécurité : état factuel (ADR-833)

| Op. | État | Preuve |
|---|---|---|
| OP-1 webhook | **Fait.** | Exécuté le 2026-11-20 (runbook). Revérifié aujourd'hui en lecture seule : `gh api repos/…/hooks` renvoie **0**. `public/webhook.php` répond 410 et `deploy.sh` est neutralisé dans le lot. |
| OP-2 rotation SSH / ISPConfig | **Partiellement prouvé.** | Le 2026-11-20, la connexion SSH PROD a **refusé** l'ancien mot de passe. Pour **DEV SSH et ISPConfig, aucune preuve** : `verify-old-password-dead.sh` n'a pas été exécuté. **Non considéré comme fait.** |
| OP-2bis comptes PROD | **NON EXÉCUTÉ.** | Simulation verte le 2026-11-20 ; application bloquée, voir ci-dessous. |
| OP-4 déploiement DEV | **Non autorisé.** | — |
| OP-7 comptes DEV au mot de passe démo | À faire **après** OP-4. | — |

> ⚠️ **RISQUE CRITIQUE (P0), toujours ouvert selon les dernières preuves (2026-11-20).**
>
> Sur PROD (`c1leezr`), les comptes **Platform 2 et 3 (`admin`)** et les **utilisateurs 1 à 5** acceptaient le **mot de passe de démonstration public**. Le verrouillage n'a pas été appliqué.
>
> Aucun contrôle n'a été refait ici : aucun accès à la production, aucun secret compromis utilisé.
>
> **Action la plus courte et la plus sûre (par le propriétaire, depuis son terminal, avec son nouvel accès SSH) :**
> ```
> ssh jliouiprodleezr@213.32.20.37 'bash -s -- --only=platform_users:2,3 --only=users:1,2,3,4,5 --allow-production --apply --confirm-database=c1leezr' < docs/bmad/leezr-4-0/incident-833/prod-lock-bundle.sh
> ```
>
> Ce script :
> - remplace le mot de passe par une valeur aléatoire jamais affichée ;
> - révoque les sessions et les jetons ;
> - a été testé sur un clone ;
> - repose sur une sauvegarde déjà prise (`~/leezr-backups/op2bis-prod-20261120/`).
>
> **Preuve** : relancer la même commande **sans** `--apply --confirm-database=c1leezr`. Elle doit répondre « REFUSED: … no longer uses the public password ».
>
> Pour le compte Platform 1, changer le mot de passe dans *Compte*.

L'incident des comptes de démonstration (SEC-01/02/03) reste une urgence indépendante de ce déploiement.

## 5. Préparation du déploiement DEV : vérifications

| Point | État |
|---|---|
| Branche `dev`, avance rapide sur `origin/dev` | ✅ 51 commits, 0 en retard |
| Workflow | ✅ `.github/workflows/deploy.yml` « Build & Deploy », déclenché sur push `dev`/`main`. Dernier run DEV : `success` (2026-11-19). Le pipeline `deploy/deploy_release.sh` ne lance **aucun** `db:seed` |
| Ancien webhook | ✅ 0 hook GitHub ; `webhook.php` renvoie 410 ; `deploy.sh` est un stub |
| DevSeeder | ✅ `DeploymentGuard::assertDemoSeedAllowed()` refuse une base distante peuplée ; artisan refuse de tourner depuis `releases/AAAAMMJJ_HHMMSS` |
| Sauvegardes | ✅ `~/leezr-backups/dev-20261119T161942Z/`, `dev-post-incident-…`, `dev-recheck-20261120T170646Z/recheck_c1leezrdev.sql.gz`. Une **nouvelle** sauvegarde est à prendre juste avant le push (§7) |
| Migrations | ✅ 6 en attente, validées sur MariaDB 11.8 (§2) |
| Synchronisation système | ✅ conflits préservés ; permission exceptionnelle exclue (ADR-852) |
| Workers des exports | ✅ worker systemd `default` (`--timeout=60`, `retry_after=90`). Un job perdu ne bloque plus (seuil `timeout + 300`, `failed()`) |
| Billing async | ✅ `BILLING_ASYNC_QUEUES_ENABLED=false` par défaut (`--async` refusé) |
| `COMPANY_EXCEPTIONAL_EXPORT_ENABLED` | ✅ `false` par défaut ; à confirmer **absent ou à false** dans `shared/.env` DEV |
| Rôle Chauffeur | ⛔ **hors déploiement.** La correction des rôles existants exige une autorisation spécifique distincte |

**Comparaison des sociétés 1 et 2 (sans restauration automatique).** Après le déploiement, en lecture seule :
- faire un dump des tables des sociétés 1 et 2 ;
- le comparer avec `docs/bmad/leezr-4-0/incident-833/table-diff.py` à la sauvegarde pré-push et à `dev-20261119T161942Z`.

Un écart éventuel est consigné. Toute restauration (`restore-companies-1-2.php`) reste une décision séparée.

## 6. Préconditions manquantes

1. **Autorisation OP-4** (push `dev`).
2. **OP-2 prouvé** : exécution de `verify-old-password-dead.sh` par le propriétaire. Attendu : SSH DEV refusé, SSH PROD refusé, ISPConfig refusé. Le runbook demande au minimum qu'OP-2 soit « engagé ».
3. **OP-2bis appliqué** sur PROD (§4). Indépendant de DEV, mais critique.
4. Sauvegarde fraîche de `c1leezrdev` avec sha256, et copie hors serveur.
5. `shared/.env` DEV contrôlé : `COMPANY_EXCEPTIONAL_EXPORT_ENABLED` et `BILLING_ASYNC_QUEUES_ENABLED` absents ou `false` ; Mailpit ou SMTP de test.
6. Décision sur les 5 conflits de synchronisation : laisser en l'état (recommandé) ou `--adopt` ciblé.
7. OP-7 (comptes DEV au mot de passe démo), juste après OP-4.

## 7. Opérations exactes de déploiement (après autorisation)

1. Sauvegarde, par le propriétaire ou l'agent avec la clé dédiée :
   ```
   mysqldump --single-transaction c1leezrdev | gzip > shared/backups/pre-4-2_c1leezrdev_<UTC>.sql.gz
   ```
   Puis calculer le sha256 et faire une copie dans `~/leezr-backups/`.
2. Simulation de synchronisation avant code :
   ```
   php8.4 artisan system:sync --dry-run
   ```
   À comparer au résultat attendu en §2.
3. Push : `git push origin dev`, en avance rapide.
4. Suivre le run « Build & Deploy » : `gh run watch`. Le pipeline enchaîne `migrate --force`, `system:sync --force`, `optimize`, puis le redémarrage des workers.
5. Contrôles :
   - `migrate:status` : 0 en attente ;
   - `systemctl status` des workers `default` et `ai` ;
   - `failed_jobs` stable.
6. Comparaison des sociétés 1 et 2 (§5), puis recette distante (§8).
7. Ensuite seulement, OP-7.

## 8. Recette distante courte (comptes fictifs autorisés uniquement)

| # | Parcours | Attendu |
|---|---|---|
| 1 | Connexion Platform et Company | 200, pas de 500 (clé de rôle longue) |
| 2 | Création complète d'une société fictive (Platform › Créer) | société active, propriétaire invité, e-mail reçu |
| 3 | Modules et JobDomains | Logistique : modules et champs hérités ; page « module indisponible » |
| 4 | Abonnements et facturation | aperçu de changement d'offre ; MRR cohérent ; aucune facture historique touchée |
| 5 | Codes promo | code valide : tarif final affiché, puis rattaché ; codes invalide et expiré refusés |
| 6 | Permissions | chauffeur sans accès admin ; `exceptional_company_export` détenue par aucun rôle |
| 7 | Planning, pointage, livraisons | publication de planning, pointage, livraison suivie |
| 8 | Exports Workforce et Company | aperçu puis CSV ; archive Company `ready` / `complete` |
| 9 | Suspension et récupération des données | mode suspendu côté SPA ; export autorisé pendant la suspension |
| 10 | Erreurs | `storage/logs` sans erreur critique ; `failed_jobs` sans nouveau job |

## 9. Non-régression locale

- PHPUnit complet, lancé seul : **4893 passés, 0 échec, 13 ignorés**. Un premier passage a révélé une régression d'ADR-852 : `admin` passait au niveau « gestion ». Elle est corrigée, et le niveau est désormais calculé hors permissions explicites.
- JS : 42/42. Build propre (M10).
- Campagnes E2E : B 41/41 (ordre inverse) et C 43/43 (ordre mélangé).

