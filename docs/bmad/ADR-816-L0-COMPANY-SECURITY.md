# ADR-816 — L0 : sécurité Company (fuites de données)

- **Date** : 2026-11-17, DEV, base `05107040`. **Lot L0 uniquement** : L1 à L10 ne sont pas commencés.
- **Origine** : constats R0 (C2), R1 et R2 d'ADR-815, repris en S1 à S3 dans ADR-815.2 (`adr8152/09-data-security-compatibility.md`).
- **Principe** : corriger à la **source** de chaque autorisation, sans dupliquer de règle et sans rien élargir.
  - Aucun écran nouveau, aucune reconstruction du tableau de bord.
  - Aucune modification du modèle poste / rôle, aucune migration.

## 1. Widgets et indicateurs (S1, P0)

- **Cause** : quatre widgets **collectifs** étaient gardés par un droit **personnel**.
  - `workforce.clock_live`, `workforce.anomalies` et `workforce.hours_trend` exigeaient `workforce.timesheet_view` (« View own timesheets »).
  - `workforce.leave_pending` exigeait `workforce.leave_request`.
- **Correction (source unique)** : la règle « voir tout le monde » par domaine, déjà appliquée par les contrôleurs Workforce, est extraite dans `WorkforceAccess::viewAllWith()`.
  - Elle est indépendante de la requête HTTP.
  - Elle reçoit le domaine `planning`, jusqu'ici écrit en dur dans `ShiftController::planningScope()`, qui l'utilise désormais.
- **Déclaration des widgets** : un widget collectif déclare son domaine via la nouvelle interface `CollectiveWidget` (`time`, `leave`, `employee`, `planning`).
  - Widgets concernés : `clock_live`, `anomalies`, `hours_trend`, `leave_pending`, `headcount`, `planning.shifts`, `timesheet_pending`.
  - `DashboardCatalogService` les exclut si l'utilisateur n'a pas l'autorité du domaine. Ce catalogue alimente **à la fois** la liste des widgets **et** leur résolution (`POST /dashboard/widgets/data`) : la protection est côté serveur.
  - Le droit personnel n'est plus un prérequis des 4 widgets fautifs.
- **Disposition enregistrée** : `GET /dashboard/layout` ne renvoie plus que les tuiles du catalogue autorisé. La disposition stockée n'est pas modifiée. La réponse porte `stored` : l'interface ne remplace jamais une disposition existante par une disposition par défaut lors d'un affichage (surface engine, `home.store`, `dashboard.store`).
- **Profils conservés** : propriétaire (tout) ; manager (temps, congés, planning) ; RH « voir tout » (effectifs, congés) ; responsable d'exploitation avec `timesheet_approve` et `time_manage`.
- **Profils fermés** : salarié, chauffeur, utilisateur limité.

## 2. Annuaire (S3)

- **Cause** : `members.view` est l'**annuaire** (accordé aux rôles de terrain par le bundle `members.team_view`). Il ouvrait pourtant la **facette comptes** : e-mail de connexion, état du compte, rôle d'accès, indicateurs propriétaire et administrateur, identifiant d'appartenance.
- **Correction (source unique)** : `MembershipSecurityPolicy::canSeeAccountDetails()`. Les détails de compte sont réservés à l'administration des accès (`members.invite`, `members.manage`, `members.credentials`) ou des rôles (`roles.view`, déjà utilisé par ADR-786). Le propriétaire y a accès par le contournement habituel ; une appartenance suspendue n'y a jamais accès.
- **Lecteurs alignés** :
  - `GET /company/members` : la projection minimale existante (ADR-768) s'applique à l'annuaire seul ;
  - `GET /company/people` : nom et photo seulement, plus `facets.account_details` ;
  - `GET /company/people/{ref}` : la fiche reste ouvrable avec le nom ; la facette Accès, l'e-mail de connexion, le rôle et les indicateurs sont masqués.
- **Interface** :
  - la colonne « Accès » et le filtre « Compte » sont masqués quand les détails ne sont pas servis (sinon « Aucun compte » à tort) ;
  - le bandeau « profils incomplets » ne compte que les complétudes connues.
- **Permissions RH non élargies.** La règle de périmètre `workforce.view` / `manage` est inchangée (décision D4).

## 3. Modules (S2)

- `GET /modules/{key}/settings`, `GET /modules/quote` et `GET /modules/{key}/deactivation-preview` exigent désormais `modules.manage`, comme leurs écritures. Seules les pages Modules (`modules.manage`) les appellent.
- `GET /modules` (catalogue et états d'activation, lu par toute l'application) reste ouvert.

## 4. Preuves

**Tests** (`tests/Feature/Adr816CompanySecurityTest.php`, 7 tests, 8 profils et une seconde société) :
- **Avant correction** : 4 des 6 premiers tests en échec. Le salarié recevait `clock_live`, `anomalies`, `leave_pending` et `hours_trend` ; le chauffeur recevait les e-mails ; les routes de modules répondaient 200.
- **Après correction** : 7 sur 7.

**Recette navigateur** (clone isolé `leezr_audit816`, 8 profils de la société 1, puis supprimé) :

| Profil | Widgets collectifs | E-mails annuaire (`members` / `people`) | Fiche du propriétaire (e-mail / rôle) | Modules settings / quote / impact | Erreurs API nouvelles |
|---|---|---|---|---|---|
| Propriétaire | 7 | 22 / 17 | visibles | 200 | aucune |
| Alice (accès) | 0 (aucun droit RH) | 22 / 22 | visibles | 403 (pas `modules.manage`) | aucune |
| RH | 1 (congés) | 0 / 0 | 404 (périmètre, inchangé) | 403 | aucune (le 422 de sauvegarde a disparu) |
| Manager | 6 | 0 / 0 | 404 (inchangé) | 403 | aucune |
| Resp. exploitation | 5 | 0 / 0 | 404 (inchangé) | 403 | aucune |
| Salarié | **0** (avant : 4) | 0 / 0 | 403 (inchangé) | **403** (avant : 200) | aucune |
| Chauffeur | 0 | **0** (avant : 22) | 200, **sans e-mail ni rôle** | **403** (avant : 200) | aucune |
| Limité | 0 | 0 / 0 | 404 | 403 | aucune |
| Propriétaire de la société 101 sur la société 1 | — | 403 | 403 | 403 | — |

- **Dispositions** : intactes dans le clone (mêmes tailles et mêmes dates avant et après la recette).
- **Navigation** : aucune page autorisée n'est devenue un 403. Les 404 et 403 relevés sont préexistants (périmètre `workforce.view`, Équipe non ouverte au salarié).

**Tests adaptés** (ils figeaient la fuite ou un cas devenu impossible) :
- `CompanyMemberProfileTest` : annuaire seul = projection minimale.
- `PeoplePhase2Test` : la facette Accès = `members.view` + `members.invite`.
- `M7Lot2OperationalPersonFileTest` : la facette Accès passe par l'administration des accès ou des rôles ; l'annuaire seul reçoit la projection minimale.
- `DashboardGridV2Test` : des widgets **Platform** enregistrés dans une disposition Company ne sont plus renvoyés (ils ne pouvaient de toute façon pas s'y résoudre).
- `M7Lot4PerformanceTest` : budget `people.file` 90 → 92, pour une lecture d'autorisation constante.

## 5. Hors L0 (non traité, volontairement)

- `theme.manage` donné au chauffeur par défaut (S4) : relève de la décision D4/D3 et des rôles par défaut, pas d'une fuite de données.
- Indicateurs **d'équipe** pour un manager sans autorité « voir tout » : il n'y a pas de variante d'équipe des widgets ; à concevoir en L5.
- Erreur 422 du constructeur de disposition par défaut (cause préexistante, désormais sans effet) : L5.
