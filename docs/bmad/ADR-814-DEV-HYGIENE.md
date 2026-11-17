# ADR-814 — Hygiène DEV et préparation de livraison

- **Date** : 2026-11-17, DEV uniquement, base `ae47b8a0`.
- **Outils** : tous dans `docs/bmad/adr814/`. Ils sont en lecture seule ou en simulation par défaut ; `--execute` est obligatoire pour modifier.
- **Ce qui a été modifié dans ce lot** : uniquement la suppression de la société fictive 102. Rien n'a été exécuté dans la file d'attente, et la remise à zéro de la 101 n'a pas été lancée.

## 1. File d'attente DEV

La revalidation du 17/11 donne **73 tâches**, inchangées depuis ADR-813 : aucune nouvelle, aucune en `failed_jobs`. Le classement individuel est dans `adr814/jobs-classification.json` : société et preuve d'appartenance, cible présente ou non, catégories, verdict et empreinte sha256 de chaque payload.

| Catégorie | Nombre | Tâches |
|---|---|---|
| Vouées à l'échec (leur objet a été supprimé) | **61** | 26 invitations, 19 analyses IA, 14 e-mails de facture, **2 analyses antivirus** (pièces Onboarding 1 et 2 retirées, constat nouveau par rapport aux 59 d'ADR-813) |
| Encore utiles | 7 | 69 (facture VORTEX 58), 84 (planning société 1), 91–93 (factures 101), 98 (IA 101, exclue), 99 (planning 101) |
| Périmées | 5 | 15, 16, 17, 18 (planning de semaines passées), 29 (invitation à la société 1 d'un utilisateur qui n'en est plus membre : e-mail réel **trompeur**) |
| Potentiellement facturables | 1 | 98 seulement (appel au fournisseur IA). Les 19 autres analyses échouent avant tout appel. |
| Risque de notification externe | 25 | toutes les notifications dont l'envoi aboutirait |
| Appartenance indéterminée | 30 | 28 vouées à l'échec + 15 et 18 (destinataire membre de 6 sociétés) |

**Appartenance des 61 tâches vouées à l'échec** :
- **33 relèvent de la société 1** :
  - 26 invitations (`tenantCompanyId=1` dans le payload) ;
  - 5 analyses IA de documents d'entreprise : 5 à 9, retirés le 14/11 selon l'audit de la société 1 ;
  - 2 analyses antivirus.
- **28 ont un propriétaire non prouvé** :
  - 14 documents de membre supprimés sans trace ;
  - 14 factures supprimées, dont le journal d'e-mail a disparu ou n'a pas de société.

**Mise à l'écart** : préparée, mais **non appliquée**.
- Aucune des 61 tâches n'appartient de façon prouvée à une société fictive, et les règles interdisent de modifier les tâches d'une société réelle.
- **Sauvegarde préalable** : `adr814/jobs-backup-61.json`, avec les lignes complètes et une empreinte par payload. Fichier : sha256 `6d5545ef…24d7c`. Il ne contient aucun secret.
- **Procédure** (`queue-set-aside.php`) :
  - `park <ids> --execute` déplace les tâches vers la file `parked-adr814`. Rien n'est supprimé, et aucun worker n'écoute cette file.
  - Le script **refuse** toute tâche dont le verdict n'est pas `set_aside`, c'est-à-dire « propriétaire prouvé fictif et cible absente ». Il refuse aussi toute tâche réservée par un worker, et la tâche 98.
  - `restore <backup> --execute` remet chaque ligne à l'identique, ou la réinsère si elle a été supprimée, après vérification de son empreinte.
  - Vérifié en simulation : refus pour les tâches 30, 22 et 98 ; restauration prête pour les 61. Rien n'a été modifié : 73 tâches, files `ai` et `default`.

**Décision attendue** : écarter ou non les 33 tâches de la société 1 et les 28 au propriétaire indéterminé. Cela demande d'ajouter leurs identifiants, après accord, à une liste d'autorisation datée dans l'outil.

## 2. Société 102 : supprimée

**Vérifications** (gardes rejouées par `purge-company-102.php`, avant toute écriture) :
- **Identité** : « E2E Recette 813 667092 », créée le 17/11 par la régression d'inscription d'ADR-813.
- **Membres** : un seul membre, le propriétaire 179 `e2e.813.owner.667092@leezr.invalid`. Il n'a aucune autre appartenance.
- **Documents et événements** :
  - aucun fichier ni document ;
  - 1 événement d'audit, 1 événement d'entonnoir d'inscription ;
  - aucun e-mail, aucun journal Platform.
- **Abonnement** : n°53, Starter à 0 €, fournisseur interne, sans identifiant fournisseur, référencé nulle part.
- **Finances** : 0 facture, paiement, avoir, portefeuille, écriture de grand livre ou module payant.
- **Tâches** : aucune tâche ne référence la société 102 ou l'utilisateur 179.
- **Clés étrangères** : toutes les références sont dans le plan. Aucune ligne `company_id=102` n'est hors plan.

**Supprimé** : 182 lignes, par identifiants exacts, en une transaction.

| Données | Lignes |
|---|---|
| Droits des rôles | 101 |
| Champs | 36 activations, 7 valeurs |
| Modules | 6 + 6 motifs d'activation |
| Rôles 175 à 179 | 5 |
| Tableaux de bord | 5 |
| Documents activés | 5 |
| Membership, session, snapshot de preset, lien métier, audit, événement d'entonnoir, abonnement 53, société 102, utilisateur 179 | 1 chacun |

**Rapport** :
- `adr814/company-102-manifest.json` donne les identifiants exacts.
- La sauvegarde complète est hors Git, car elle contient le hachage du mot de passe et la session : `storage/app/private/adr814/company-102-backup.json`, sha256 `a4e70c0f…ba80`.
- La restauration se fait par `purge-company-102.php restore <backup> --execute`.

**Contrôle** : les sociétés 1 et 101 sont identiques avant et après (empreinte de toutes leurs lignes `company_id`). La file reste à 73 tâches.

## 3. Société 101 : référence de recette, remise à zéro préparée

Procédure : `reset-company-101.php`. Elle est **préparée, non lancée**.
- **Remis à zéro** (opérations d'une recette) :
  - expéditions ;
  - créneaux, activités planifiées, pointages et pauses ;
  - opérations et file de notification du planning ;
  - demandes de congé et **leurs** mouvements de compteur ;
  - demandes documentaires, leurs événements et les pièces déposées.

  Les soldes de congés sont recalculés par le domaine Congés (`LeaveLedger::computeBalance` et `LeaveBalanceCacheHealer`, comme `e2e:reset-leave`).
- **Conservés** :
  - identité, membres, salariés, contrats, rémunération, types de congés, soldes, rôles, modules et réglages ;
  - historique : audit, e-mails, notifications ;
  - **tout le financier** : factures (dont INV-2026-000051), lignes, grand livre, portefeuille, abonnements, modules payants, séquence de numérotation.
- **Gardes** :
  - **Empreinte financière** : calculée avant et après. Si elle change, le script le signale et s'arrête en erreur.
  - **Identité** : la société doit être « E2E Recette 8091 433828 ».
  - **Cascades** : les tables touchées par une suppression en cascade (pauses, activités planifiées) sont incluses et sauvegardées. Le script s'arrête si un document généré conservé serait modifié.
- **Sauvegarde** : lignes complètes et copie des fichiers dans un dossier hors Git. Restauration par `restore`.
- **Simulation du 17/11** : 15 lignes et 1 fichier ; empreinte financière `43ae0ae7…2fb3`.
- **À savoir** : la pièce 40 est celle de la tâche IA 98, qui échouerait après une remise à zéro.

## 4. Exploitation : conditions de fonctionnement

| Fonction | DEV (constaté) | Exigence de production |
|---|---|---|
| **Worker de queue** | `QUEUE_CONNECTION=database`, **aucun worker**, 73 tâches en attente. Ne jamais lancer de worker global : procédures d'isolement d'ADR-813 et ADR-814. | Workers supervisés (systemd ou Supervisor) sur les files `default` et `ai`, `--tries` et `--backoff` réglés, redémarrage à chaque déploiement (`queue:restart`). Workers **propres à l'environnement** : l'audit de livraison du 08/11 a relevé des workers non séparés par environnement. |
| **Scheduler** | Pas de cron. `routes/console.php` planifie environ 30 commandes : facturation (renouvellement, relances, rapprochement, encaissement), documents (échéances, relances), signatures, invitations, Onboarding (expiration, analyse antivirus), alertes, congés (acquisition, consommation), boîte e-mail toutes les 15 s, battement de cœur. | `* * * * * php artisan schedule:run`, une seule instance par environnement. Le battement de cœur `scheduler:heartbeat` prouve que le cron tourne. Le scheduler était **absent en PROD** lors de l'audit du 08/11 : c'est un prérequis avant toute livraison. |
| **Temps réel (« Reverb »)** | Reverb n'est **pas** dans le projet. Le temps réel passe par un pilote interne (`REALTIME_DRIVER=null`, transport `polling`, diffusion `log`). | Choisir explicitement : polling, ou SSE avec Redis (`realtime.php` : limites de flux, seuil d'inondation, coupe-circuit). Installer Reverb serait une nouvelle brique, non décidée. |
| **E-mails** | SMTP vers Mailpit (127.0.0.1:1025). Les e-mails en file ne partent qu'avec un worker. Langue par marché (ADR-813). | SMTP transactionnel authentifié (ou réglage Platform `smtp_host`), SPF, DKIM et DMARC, `MAIL_FROM` du domaine, worker actif. La relève IMAP passe par le scheduler. |
| **Stockage documentaire** | Disque `local` (`storage/app`), documents dans `documents/<société>/…`. | Disque persistant **hors release** (lien symbolique partagé lors d'un déploiement atomique), sauvegardé. Droits restreints. Quarantaine antivirus Onboarding active (fail-closed). |
| **Génération des documents** | DomPDF, FPDI et TCPDF (ancien) en PHP, sans service externe. L'analyse IA passe par la file `ai` (`AI_DRIVER=ollama`). | Mémoire PHP et polices DomPDF disponibles. Fournisseur IA configuré ou module IA désactivé : la santé « AI » est **Critique** sur le tableau de bord. |
| **Sessions** | `SESSION_DRIVER=database`, 120 min, nettoyage par loterie (2 %), 29 sessions. | Même pilote, ou Redis. Cookies `secure` et `same_site`, domaine Sanctum stateful correct. |
| **Logs et tâches échouées** | `stack` vers `single`, niveau `debug`. **`laravel.log` = 141 Mo**, sans rotation. 0 tâche en échec. Logs de scheduler dans `storage/logs/scheduler/`. | Logs `daily` avec rétention, niveau `warning` ou `error`, `APP_DEBUG=false`. Surveillance de `failed_jobs`, et `queue:prune-failed` planifié (non planifié aujourd'hui). `billing:check-dlq` est déjà planifié. |

**Conditions avant une livraison en production** :
1. Cron `schedule:run` actif en PROD.
2. Workers par environnement.
3. Disque documentaire persistant hors release.
4. SMTP authentifié.
5. `APP_DEBUG=false` et rotation des logs.
6. Fournisseur IA ou module IA désactivé.
7. Migrations en attente appliquées : `2026_12_07_000001` (ADR-813) n'est qu'en DEV.

Ces points relèvent des réserves déjà connues (sandbox Stripe, worker de staging). Aucune infrastructure PROD n'a été touchée.

## 5. Validation

Voir la fin du rapport et l'entrée ADR-814 de `04-decisions.md`.

**Résultats** (17/11) :
- **Vérifications ciblées** : gardes et simulations de chaque outil ; empreintes des sociétés 1 et 101 avant et après la purge (identiques) ; file inchangée (73 tâches, files `ai` et `default`).
- **Suite complète** : 4636 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariantTest`, inchangés.
- **Build** : propre.
- **Code** : aucun fichier applicatif ni migration modifié dans ce lot.
