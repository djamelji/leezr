# Leezr 5.0 · préflight DEV (ADR-862)

**Date** : 2026-11-22. Même mécanisme que pour 4.2 (`docs/bmad/leezr-4-2-audit/release/dev-preflight.sh`), adapté dans `dev-preflight-5-0.sh`. Seule écriture sur le serveur : une sauvegarde vérifiée. Aucune autre action sur DEV, aucune sur PROD.

| Point | Constat | Statut |
|---|---|---|
| Release en ligne | `releases/20261122035325_7432d80` (= `origin/dev` 7432d80a, Leezr 4.2) | — |
| Diff à déployer | 29 commits locaux depuis `origin/dev`, de L0 à la clôture L7 (ADR-855 → ADR-862). Aucun changement de `composer.lock`, `pnpm-lock.yaml`, `package.json` ni du pipeline `.github/workflows`. | OK |
| Migration | Une seule : `2026_12_20_000001_add_module_obligation_scope_to_company_document_settings`. Elle est additive : colonne `string(16)` nullable, gardée par `hasColumn`, avec un `down()`. | OK |
| Compatibilité des données historiques | Répétition sur une **copie MariaDB 11.8 de la sauvegarde DEV** (`leezr_devclone862`) : migration en 43 ms. Ensuite `leezr:dev-bootstrap` puis `system:sync --force`, comme dans le pipeline. Les sommes de contrôle de 14 tables (sociétés, adhésions, utilisateurs, rôles, champs, collaborateurs, postes, abonnements, factures, grand livre, modules, expéditions, réglages documentaires) sont **identiques** avant et après. Les sociétés 1, 2 et 4 restent sur `NULL`, c'est-à-dire « everyone », le comportement historique. La simulation Flotte ne rend personne nouvellement obligatoire. | OK |
| SystemSync | 4 conflits connus, déjà présents en 4.2 : `jobdomains:logistique default_fields` et `default_roles`, `notification_topics:billing.payment_received` (libellé et icône). Les valeurs administrées sont préservées. | OK |
| Paramètres | `APP_ENV=staging`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `MAIL_MAILER=log`, `REALTIME_DRIVER=null`. Les deux drapeaux sensibles sont absents. | OK |
| `DOCUMENT_SCANNER=simulated` exclu | **Absent** sur DEV, donc pilote `none` : fail-closed, les pièces restent en quarantaine. Le pilote simulé n'est posé que par `scripts/audit842-serve.sh`, qui refuse toute base autre qu'un clone marqué, et il est refusé en production même s'il est configuré (`Adr862RecipeEnvironmentTest`). Le préflight **s'arrête** s'il le trouve. | OK |
| Cache applicatif | Le pipeline vide la configuration, les routes et les vues, puis lance `optimize`. Il ne vide pas le cache de données, et ce n'est pas nécessaire : les indicateurs de facturation ont une clé nouvelle, qui porte le dernier identifiant du grand livre ; le thème public a une clé nouvelle (`public:theme:v1`) oubliée à chaque modification. Aucune valeur périmée ne peut donc être servie. Les anciennes entrées expirent seules en 10 minutes. | OK |
| Workers et files | `leezr-queue-ai-web3` et `leezr-queue-default-web3` actifs (systemd par environnement). `jobs` = 0, `failed_jobs` = 0, 0 sur les dernières 24 h. | OK |
| Sauvegarde DEV | `shared/backups/pre-5-0_c1leezrdev_20261122T185153Z.sql.gz` : 201 tables, sha256 `40d5e8f8f667d3e6bf608b6b3f3bae069594f1daf4b476e5a7ccb3632016f913`, `gzip -t` OK, marqueur « Dump completed » présent. Copie sur le poste : `~/leezr-backups/dev-pre-5-0-20261122/`, même empreinte. Le pipeline ne sauvegarde que PROD : c'est cette sauvegarde qui protège DEV. | OK |
| Pipeline GitHub Actions | `deploy.yml` est inchangé. Sur la branche `dev` : build, envoi de l'artefact, `deploy_release.sh`, `migrate --force`, `leezr:dev-bootstrap`, `system:sync --force`, workers systemd par environnement, nettoyage des caches et contrôle `/up`. Le webhook historique est supprimé (OP-1). | OK |
| Compte Platform n° 1 | Accepte encore le mot de passe **public**. 2FA désactivée, seul compte Platform, DEV sans envoi d'e-mails. Procédure à suivre par le propriétaire : [PLATFORM-1-DEV-PASSWORD.md](PLATFORM-1-DEV-PASSWORD.md). | **intervention du propriétaire** |

## Après le déploiement (à faire par l'agent, sur GO)

1. Vérifier que `/up` répond et lire le résumé de `deploy.log` (migration DONE, résultat de la synchronisation).
2. Vérifier que les sociétés 1, 2 et 4 sont identiques à la sauvegarde : refaire les sommes de contrôle sur un dump post-déploiement.
3. Lancer `platform1-check.sh` : le mot de passe public doit être refusé si le propriétaire l'a changé.
4. En cas de retour arrière : `deploy/rollback.sh` **avec une cible explicite** (piège ADR-833 : sans cible, le script prend une release incomplète). Ensuite, si nécessaire, restaurer la sauvegarde ci-dessus.

## Résultat du déploiement (2026-11-22, lecture seule : `dev-postflight-5-0.sh`)

| Point | Constat |
|---|---|
| Commit et pipeline | `ce098d05` poussé sur `dev` sans force ; GitHub Actions run 45068689470 : Build artifact et Deploy to staging en **succès** |
| Release servie | `releases/20261122205231_ce098d0` ; `/up` = 200 ; le bundle servi (`main-CKInv51b.js`) est celui du manifeste de la release et contient les écrans 5.0 |
| Migration | `2026_12_20_000001` appliquée (lot 20), 0 en attente ; scope `NULL` pour les sociétés 1, 2 et 4 (comportement historique) |
| SystemSync | les 4 mêmes conflits, valeurs administrées préservées |
| Données historiques | sommes de contrôle des 14 tables **identiques** à la répétition sur la sauvegarde ; aucun poste avec activités déclarées (règles Flotte inchangées) |
| Workers et files | deux workers actifs ; `jobs` = 0, `failed_jobs` = 0 |
| Erreurs serveur | aucune depuis le déploiement (celles du jour datent de 03:57 et 07:12, avant) |
| Recette navigateur | pages publiques seulement (connexion Platform et Company, inscription : 200, sans erreur console) ; **aucune recette authentifiée** : pas de compte Platform de recette, et aucun compte Company de recette autorisé pour l'agent |
| Compte Platform n° 1 | accepte **encore** le mot de passe public : intervention du propriétaire toujours requise |
