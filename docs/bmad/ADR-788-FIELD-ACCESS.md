# ADR-788 — Accès terrain · matricules historiques · confidentialité documentaire

> Base `2e8f3a26` · DEV uniquement · aucun push, aucune PROD · **aucun rôle modifié, aucun matricule historique écrit** (simulations seulement, en attente du GO).
> Preuves : tests `tests/Feature/Adr788FieldAccessTest.php` · sorties de simulation reproduites ci-dessous.

## 1. Matrice des permissions terrain

| Fonction | Livreur — rôle existant (avant GO) | Livreur — après GO / nouvelles sociétés | Salarié | Rôle de gestion avec `documents.view` | Propriétaire |
|---|---|---|---|---|---|
| Mes documents, répondre aux demandes (`/profile/documents`) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Mes bulletins (`/profile/payslips`), mes documents générés | ✅ | ✅ | ✅ | ✅ (fiche requise) | ✅ |
| Coffre Company (liste, versions, téléchargement) | ⚠️ via `documents.view` | ❌ 403 | ❌ | ✅ | ✅ |
| Conformité globale, activité, recherche documentaire | ⚠️ via `documents.view` | ❌ 403 | ❌ | ✅ | ✅ |
| Documents **standard** d'un collègue (métadonnées, téléchargement) | ⚠️ via `documents.view` | ❌ 403 | ❌ | ✅ | ✅ |
| Pièces confidentielles d'un collègue (identité, RIB, judiciaire) | ❌ (politique ADR-757/758 : métadonnées masquées, 403) | ❌ | ❌ | selon `documents.view_confidential` / `workforce.sensitive_read` | ✅ |
| Bulletins d'un collègue | ❌ (domaine paie) | ❌ | ❌ | selon droits paie | ✅ |
| Inviter un membre | ⚠️ `members.invite` hérité | ❌ | ❌ | selon rôle | ✅ |
| Annuaire (nom, poste, téléphone) | ✅ | ✅ | ❌ | selon `members.view` | ✅ |

⚠️ = accès accordé par une permission explicite du rôle hérité, retiré seulement sur GO (§2 et §4).

## 2. Retrait de `members.invite` — analyse

- **Périmètre exact** : 7 rôles de clé `driver`, tous **système**, **non administratifs**, avec un jeu de permissions **identique** (préréglage terrain + `members.invite`) — aucune personnalisation.

| Société | Rôle | Adhésions |
|---|---|---|
| 1 — Leezr Logistics E2E | 7 | 12 : 4 `bob@leezr.test`, 23-26 `lucie.*@leezr-test.invalid`, 28-34 `thomas.bernard.*@leezr.invalid` |
| 2 — Trial Logistics | 2 | 0 |
| 3 — Pending Approval Co | 12 | 0 |
| 31, 32, 33, 34 — [M6E LOAD] | 29, 34, 39, 44 | 0 |

- Aucun autre rôle opérationnel ne détient `members.invite`.
- **Aucune invitation n'a jamais été envoyée par un livreur** : aucun usage ni responsabilité ne dépend de ce droit.
- Origine : préréglage antérieur à ADR-777 ; ADR-777 a corrigé le préréglage pour les nouvelles sociétés sans toucher aux rôles existants.
- Effet du retrait : disparition de « Ajouter une personne » et « Gérer l'accès » pour ces 12 adhésions ; `POST /company/members` → 403 ; aucune autre permission touchée (l'invitation d'un rôle administratif était déjà refusée, ADR-752).
- Simulation exécutée : `php artisan roles:revoke-permission driver members.invite` → `apply: false`, 7 rôles, 12 adhésions listées (identifiants et e-mails) ; aucune écriture vérifiée (8 permissions sur le rôle 7).
- **Commande à lancer sur GO** : `php artisan roles:revoke-permission driver members.invite --apply` (audit `role.permission_revoked` par rôle). `permissions:reconcile --apply` ne doit pas être utilisé : il réalignerait tous les rôles du préréglage.

## 3. Matricules historiques — simulation

Commande `employees:assign-missing-numbers [--dry-run] [--company=…]` :
- uniquement les fiches sans numéro (`NULL` ou vide) ; jamais d'écrasement (écriture conditionnelle `WHERE employee_number IS NULL`) ;
- numéros tirés de la séquence par société (`EmployeeNumbering::next`, ADR-787 : verrou de ligne, reprise sur interblocage, jamais de réutilisation, départ après les numéros existants, numéros pris sautés) — le même chemin que les nouvelles fiches ;
- création concurrente : une fiche numérotée entre-temps est sautée (le numéro tiré reste inutilisé, jamais réattribué) ;
- audit `employee.number_assigned` (avant/après) ; idempotente ; `--dry-run` n'écrit **rien**, pas même le compteur (`EmployeeNumbering::preview`).

Simulation DEV (`--dry-run`) : **22 fiches, toutes dans la société 1**, toutes actives ; numéros prévus `EMP-000024` → `EMP-000045` (le plus grand existant est `EMP-000023`) :

| Fiches (id) | Numéros |
|---|---|
| 4, 5, 6, 7, 8, 10, 11, 12 | EMP-000024 → EMP-000031 |
| 1218 → 1223, 1225 → 1231 | EMP-000032 → EMP-000044 |
| 1732 | EMP-000045 |

Après simulation : 0 compteur créé, 22 fiches toujours sans numéro. **Commande à lancer sur GO** : `php artisan employees:assign-missing-numbers` (ou `--company=1`).

## 4. Confidentialité documentaire

**Politique existante vérifiée** (ADR-757/758, DMS M1/M1.1) :
- soi-même : tous ses documents, toute catégorie, **sans permission** (`/profile/documents`, `/profile/payslips`, `/workforce/me/documents`, Mon espace) — c'est le chemin des salariés, demandes comprises ;
- `documents.view` : permission de **supervision** (« documents, conformité, journal d'activité, téléchargements ») — documents **standard** d'autrui, coffre Company, conformité, activité, recherche ;
- pièces confidentielles et bulletins d'autrui : jamais avec `documents.view` seul (métadonnées masquées « Accès réservé », téléchargements 403) — vérifié par `DmsM1FoundationsTest`, `DmsM11FoundationsTest`, `M7Lot4DmsRecetteTest`.

**Constat** : le livreur n'a aucun usage légitime de `documents.view` (ses usages personnels n'en ont pas besoin ; le widget « conformité » de son préréglage n'est déjà pas servi aux rôles terrain ; le menu ne lui propose pas « Documents »). Il l'obtient uniquement par le préréglage terrain (`documents.access`). Parmi les rôles **non administratifs**, seuls les 7 rôles Driver le détiennent.

**Ce n'est pas une fuite au regard de la politique** (la permission est explicitement attribuée), mais un **droit injustifié** pour la fonction : conformité de l'entreprise, coffre Company et documents standard des collègues.

**Piste écartée (testée puis annulée)** : exiger en plus un rôle administratif sur les routes de supervision. Elle cassait un usage légitime et testé — un rôle *lecteur documentaire* non administratif (« manager view-only », ADR-757 ; 7 tests DMS/ADR-772 en échec) — ou aurait obligé à donner à ces lecteurs toutes les surfaces de gestion. Annulée intégralement (routes et fiche restaurées).

**Correctifs retenus**
1. **Préréglage Driver** : `documents.access` retiré (avec `members.invite`, déjà absent depuis ADR-777). S'applique aux **nouvelles** sociétés uniquement ; aucun rôle existant modifié.
2. **Rôles existants** : retrait ciblé préparé, **en attente du GO** — simulation exécutée : `php artisan roles:revoke-permission driver documents.view` → `apply: false`, mêmes 7 rôles, 12 adhésions (société 1). Commande sur GO : `… driver documents.view --apply`.
3. Aucun changement serveur des routes ni de l'interface.

## 5. Tests

| Niveau | Résultat |
|---|---|
| `Adr788FieldAccessTest` (7) : nouveau rôle Driver sans `documents.view` ni `members.invite` (annuaire conservé) ; rôle terrain sans `documents.view` : son espace et ses bulletins accessibles, 4 routes de supervision refusées ; rôle Driver hérité : pièce confidentielle et bulletins d'un collègue refusés ; matricules : simulation sans aucune écriture (ni compteur) et identique à l'application, idempotence, aucun écrasement, audit, séquence existante préservée ; simulations de révocation (`members.invite` avec adhésions listées, `documents.view`) sans écriture | 7/7 |
| Suites DMS existantes (`DmsM1`, `DmsM11`, `M7Lot4Dms`, `M7Lot4Performance`, `Adr772`, préréglages, navigation) | vertes après annulation de la piste écartée |
| Simulations DEV (§2, §3, §4) | aucune écriture vérifiée (rôle 7 : 8 permissions ; 0 compteur ; 22 fiches sans numéro) |
| Suite PHPUnit complète | voir l'ADR-788 dans `04-decisions.md` |
| Build | non requis : aucun fichier d'interface modifié |

Profils : livreur (Bob, rôle hérité), salarié, manager, RH, propriétaire, multi-société et Employee sans compte couverts par les tests ADR-786/787/788 et les suites DMS existantes ; aucune recette navigateur nouvelle (aucune interface modifiée).

## 6. Risques résiduels

1. Jusqu'au GO, les 12 adhésions Driver de la société 1 gardent `members.invite` **et** `documents.view` (coffre Company, conformité, documents standard des collègues ; jamais les pièces confidentielles ni les bulletins).
2. Les 22 fiches restent sans matricule jusqu'au GO.
3. Un rôle personnalisé auquel une société attribue `documents.view` obtient la supervision : c'est la définition de la permission.
4. Les rôles terrain d'autres JobDomains futurs devront suivre la même règle (pas de `documents.access`).

## 7. Commandes à exécuter sur GO

```
php artisan roles:revoke-permission driver members.invite --apply
php artisan roles:revoke-permission driver documents.view --apply
php artisan employees:assign-missing-numbers
```
