# Leezr Logistics — refonte Company et nettoyage « Logistics Only » (ADR-818)

- **Date** : 2026-11-17, DEV, base `b2653b7a` (ADR-817 terminé). Aucun push, aucune opération PROD ou staging.
- **Mission** : master plan de refonte Company + complément « Logistics Only », exécutés ensemble sans GO intermédiaire.
- **Méthode** :
  - un commit par résultat métier ;
  - tests ciblés à chaque chantier ;
  - deux recettes navigateur sur des clones isolés, supprimés ensuite ;
  - suite complète et build aux jalons.
- **Honnêteté** :
  - ce rapport distingue ce qui est **livré et recetté**, ce qui est **livré et testé seulement**, et ce qui est **conservé après vérification** ou **non fait** ;
  - aucun résultat de recette n'est extrapolé.

## 1. Commits (branche `dev`)

| Commit | Contenu |
|---|---|
| `827626b1` | Logistics Only, passe 1 : Logistique seul proposé à l'inscription (catalogue **et** serveur) ; laboratoires réservés au serveur de développement |
| `8f7a9749` | A : assistant unique « Embaucher », mode sur place, finalisation, autorité des exigences par société |
| `df70df85` | C : plus aucune demande écrite lors d'une lecture ; demandes des pièces exigées créées explicitement |
| `f246b155` | Correctifs de la recette du jalon 1 et preuves |
| `362e493d` | B/E : un formulaire d'identité, colonnes Responsable et Contrat, rôles opérationnels au moindre privilège |
| `d1924c17` | D : accueil unique `/home` (Moi, À traiter, Entreprise), `/dashboard` alias |
| `cc5712db` | F : clôture d'un oubli de sortie, refus traduits, écran de temps personnel pour le salarié |
| `daf4c25c` | G : soldes ouverts quand Congés est activé après les contrats |
| `380d01a4` | H : planifier ou replanifier une expédition tant qu'elle n'est pas partie |
| `8bfe9afb` | J/K : un nom par module, dates localisées, accord du pluriel ; plus de société vide pour un réinvité |
| `d317b394` | I : paie présentée comme une préparation ; lien contrat depuis l'embauche |
| `7b94cf85` | Logistics Only : modules non livrés retirés du catalogue Company |
| (dernier) | Langue des messages serveur (`X-Locale`), rapport final, preuves de la recette finale |

Au total, 105 fichiers modifiés depuis `b2653b7a` : environ 3 700 lignes ajoutées et 900 retirées.

## 2. Parcours terminés et recettés dans le navigateur

Recettes sur les clones `leezr_audit818` (jalon 1) et `leezr_auditfinal` (final), avec la société fictive 101 et une société créée par inscription.
- **Preuves** : `docs/bmad/redesign/acceptance-m1.json`, `acceptance-final.json`, captures dans `docs/bmad/redesign/shots/`.
- **Empreinte DEV identique avant et après chaque recette** : `adad2ea9…` puis `bf049e71…`.

| Parcours | Résultat |
|---|---|
| Inscription | Seul « logistique » est proposé ; « beta-services » est **refusé par le serveur** (422) ; la société créée reçoit un Répartiteur **sans** `documents.configure` |
| Embaucher sur place | Équipe → « Embaucher » ; champs manquants nommés ; poste obligatoire ; exigences du poste proposées (permis + CNI) ; portail ouvert sur l'appareil RH ; pièces déposées, analysées (adaptateur DEV) et soumises ; « À vérifier » → vérification → « À finaliser » → finalisation. Résultat : **fiche active, poste conservé, aucun compte créé**, aucune erreur API |
| Embaucher à distance | Sans e-mail, les modes à distance sont désactivés avec explication. Avec e-mail : lien affiché une fois, ouvert sur mobile, pièce déposée ; puis **reprise sur place du même dossier** (le dépôt distant est visible) |
| Autorité des exigences | Aperçu par personne (7 personnes, 2 concernées, pièces perdues listées) ; bascule par poste ; aucune demande écrite à la lecture d'une fiche ; retour au calcul par rôle |
| Expédition | Création ; **planification depuis l'interface** ; statut planifiée ; affectation ; le chauffeur (mobile) la voit, la met en route puis livrée ; statut final « livrée » |
| Pointage | Oubli de sortie : refus sans heure de sortie, **en français** (après correction `X-Locale`) ; clôture avec motif → `completed` |
| Salarié | Écran de temps sans onglet de supervision ni colonne collaborateur ; aucun indicateur collectif servi ; aucune erreur API |
| Chauffeur | Arrive sur `/home` ; « Embauches » refusé (403) |
| Accueil | Connexion → `/home` ; `/dashboard` → `/home` ; capture en mode sombre |
| Réinvité non accepté | Dirigé vers `/invitations` (avant : écrans vides et 400) |
| Profils manager, salarié, chauffeur | Ni Embauches ni bascule (le manager reçoit 422 sur la bascule car il détient `settings.manage` dans la 101 : bascule autorisée, mais refusée tant que des pièces seraient perdues) |

**Non recetté dans le navigateur** (testé par PHPUnit seulement, ou non couvert) :
- profils Administrateur et RH en consultation de la recette finale ;
- interface en anglais (la langue des messages serveur est testée par PHPUnit) ;
- contrat généré et signé, congés demandés et validés, préparation de paie, factures et abonnement, fin de collaboration et retrait d'accès. Ces parcours n'ont **pas été modifiés** par cette mission ; ils restent ceux des ADR-772/773, 804/805 et 632.
- La suite E2E Playwright du dépôt (`tests/e2e`) **n'a pas été lancée** : elle vise `leezr.test` avec les données DEV et y écrirait. Les deux specs touchées (`adr796-onboarding`, `adr792-requirements`) ont été mises à jour pour les nouveaux écrans.

## 3. Chantiers

### A — Embauche et intégration (priorité 1)
- **Un seul assistant** « Embaucher » (`people/_HireWizard.vue`, ancien dialogue « Inviter une recrue »). Il comporte trois étapes :
  1. personne + poste obligatoire ;
  2. exigences du poste, avec leurs sources ;
  3. déclaration de collecte et choix du mode.
- **Modes** : sur place, ou à distance (e-mail ou lien).
  - Mode **sur place** : la RH, authentifiée, ouvre **le même portail** sur son appareil (`POST onboarding-files/{id}/on-site`). La session est vérifiée en présence de la personne et l'ouverture est auditée. Il n'y a ni second moteur, ni seconde saisie.
  - Le lien à distance continue de fonctionner : un dossier commencé d'un côté se reprend de l'autre.
- **Espace « Embauches »** (`/company/workforce/hiring`, menu Équipe) :
  - étapes : dossier en cours, à vérifier, à finaliser, finalisée, close ;
  - avancement des informations et des pièces, pièces bloquées ;
  - actions : vérification (revue existante), reprise sur place, finalisation, historique (filtre « Toutes »).
- **Finalisation** (`POST onboarding-files/{id}/finalize`) : en une transaction, la fiche préparatoire devient active avec sa date d'entrée, son poste, son service et son responsable (salarié actif uniquement).
  - Aucun compte, droit ni contrat n'est créé : liens « Préparer le contrat » et « Donner un accès » (anti-escalade inchangée).
  - Les pièces exigées et absentes deviennent des demandes.
- **Formulaire bloqué sans explication** : corrigé. Les obligatoires sont marqués « * » et les champs manquants sont nommés à côté du bouton.
- **Transition ADR-817** :
  - **autorité des exigences par société**, `role` par défaut (`company_document_settings.requirements_authority`, migration additive) ;
  - **aperçu par personne** (lecture seule, transaction annulée) : pièces demandées en plus et pièces qui ne le seraient plus ;
  - **bascule explicite** (`settings.manage`, auditée, réversible), refusée tant que des pièces seraient perdues, sauf confirmation explicite.
  - Le résolveur (`DocumentResolverService::subjectMap/positionMap`) et la conformité suivent l'autorité.
  - Aucune demande régénérée ; les sociétés non basculées sont strictement inchangées ; la configuration historique est conservée.

### B — Équipe et fiche personne
- La section Emploi ne redemande plus l'identité quand le formulaire Identité existe : un sujet, un formulaire.
- La liste ajoute les colonnes **Responsable** et **Contrat**. Le statut d'intégration existait déjà (« en intégration »).
- `workforce.view_all` (L1) est préservée : la consultation globale reste distincte de la modification.
- **Conservé après vérification** : les autres sections de la fiche (Vue générale, Documents et contrats, Planning et temps, Congés, Accès, Historique), déjà servies par section selon les droits (M7).

### C — Documents
- **Plus aucune écriture métier lors d'une lecture.** L'ancien « lazy-create » (ADR-389) de `MemberDocumentWorkflowReadModel` est retiré ; une pièce exigée sans demande est présentée « à fournir ».
- **`RequiredDocumentRequests`** crée les demandes des pièces exigées, ni détenues ni déjà demandées. Elle est idempotente, utilise la même chaîne que les demandes manuelles, et ne régénère, ne rouvre ni n'annule rien.
- **Déclencheurs** :
  - embauche finalisée ;
  - changement de poste, pour les sociétés basculées (écouteur du modèle, qui couvre aussi les modifications en lot) ;
  - action RH « Demander les pièces manquantes » (`documents.manage`).
- **Conservés** : ADR-810 à 810.2, aucune approbation sans pièce, accès par personne (DMS).
- **Conservé volontairement** : le rattachement technique des lignes historiques à la lecture (`PersonDocumentContinuity`, ADR-770). C'est une réconciliation idempotente du sujet, pas une écriture métier ; la retirer exige une migration de données, hors périmètre sûr.

### D — Accueil
- `/home` est **le seul accueil**.
  - Un gestionnaire y voit : en-tête, intégration de la société, bloc **Moi** s'il est salarié, **À traiter** et **Actions**, puis **Vue d'ensemble** (indicateurs collectifs filtrés par ADR-816).
  - Un profil sans gestion y voit son accueil personnel.
- `/dashboard` redirige vers `/home`. L'entrée « Accueil personnel » de l'avatar, en doublon, est retirée.
- **Dispositions enregistrées : non modifiées** (aucune migration ; la grille de gestion et la grille personnelle restent deux dispositions distinctes, jamais partagées).

### E — Organisation et accès
- La fiche Poste ADR-817 est intégrée (Postes, exigences, personnes, reprise).
- **Droits documentaires** :
  - **nouveau groupe** `documents.processing` (traiter les pièces, sans configurer ni ouvrir les pièces confidentielles) ;
  - **nouvelles sociétés Logistique** : Répartiteur et Responsable d'exploitation passent de `documents.management` à `documents.processing`, par une migration ciblée et réversible du catalogue. Les autres réglages Platform des rôles par défaut, divergents du registre codé, ne sont pas écrasés ;
  - **preset versionné** : `logistique_rbac_v3` préparé en **brouillon**, non publié (l'initialisation versionnée est désactivée sur DEV) ;
  - **rôles existants : aucun pivot modifié.** Le rapport en lecture seule `rbac:least-privilege-report` (`docs/bmad/redesign/rbac-least-privilege-report.json`) propose 16 corrections ; 5 rôles ont des membres (société 1 : Dispatcher, 3 personnes ; société 101 : 2 rôles). Chaque correction est à appliquer par la société dans l'éditeur de rôles.
  - **Vigilance** : ne pas lancer `permissions:reconcile --apply` sans accord, il alignerait les rôles système.
- **Bloqué avec justification** : rattachement salarié ↔ site. Aucun modèle n'existe ; en créer un serait un nouveau modèle de données.

### F — Planning et pointage
- **Oubli de sortie** : un pointage resté ouvert se clôture par correction.
  - Motif obligatoire ; la pause ouverte est fermée à la sortie retenue et les totaux sont recalculés.
  - La sortie ne peut pas être dans le futur ; les feuilles validées ou verrouillées restent refusées ; l'opération est auditée.
- **Refus traduits** (`lang/{fr,en}/time.php`) à la place de l'anglais brut ; action visible « Clôturer le pointage » dans le tableau.
- **Salarié** : l'écran Temps devient personnel (pas d'onglet « Aujourd'hui », pas de colonne collaborateur).
  - Il n'est **pas** bloqué : le salarié y soumet ses feuilles de temps, et le bloquer créerait un 403 sur un parcours légitime.
- **Conservés** : règles légales, temps verrouillés, premier jour de semaine serveur (ADR-698), planning (ADR-735/736).

### G — Congés
- À l'activation de Congés **après** les contrats, les salariés sous contrat actif voient leur solde ouvert.
  - L'ouverture passe par le même moteur et la même clé idempotente que l'activation d'un contrat ; aucune règle de calcul n'est modifiée.
- Le message du salarié n'accuse plus le contrat à tort.
- Pour les sociétés déjà dans ce cas, `leave:accrue` (planifié chaque mois) ouvre aussi les soldes.

### H — Leezr Logistics
- **Planification** après création (`PUT /shipments/{id}`) : date, adresses, notes, tant que l'expédition est en brouillon ou planifiée. Droits existants (`shipments.view` + `shipments.create`) ; opération auditée.
- **Conservés** (ADR-806/807) :
  - affectation et statuts à portée explicite ;
  - un chauffeur n'agit que sur ses livraisons (le refus sur la livraison d'un collègue a été vérifié en recette) ;
  - l'écran du chauffeur est utilisable sur mobile (captures).

### I — Contrats et paie
- La paie est présentée comme une **préparation** : menu, page et module, avec des montants indicatifs exportés vers le logiciel ou le gestionnaire de paie, qui reste responsable.
- **Moteurs de paie et de contrat non modifiés.**

### J — Modules, finance, réglages
- **Un module, un nom** : nom du catalogue dans « Requis par » et dans la confirmation de désactivation.
- **Dates** dans la langue de l'interface (avant : en anglais).
- **Accord** : « 5 salariés inclus ».
- Finance et Billing : **non modifiés** (contrainte).
- Réglages : la porte unique existe depuis ADR-781 (hub). Conservée.

### K — Expérience personnelle et comptes
- **Réinvité non accepté** : une appartenance en attente n'est plus servie comme société active, et un compte sans société active est dirigé vers `/invitations`.
- Aucune vérification de sécurité n'est contournée.

### Cohérence transversale
- **Langue des messages serveur** : un middleware applique au serveur la langue d'interface que l'application envoie déjà (`X-Locale`), parmi les langues présentes dans `lang/`.
  - Avant, tous les refus et validations revenaient en anglais aux utilisateurs francophones.
  - Les notifications aux utilisateurs gardent leur langue (`preferredLocale`, ADR-813).

## 4. Logistics Only

### Métiers commercialisés
- **Colonne `jobdomains.signup_available`** (migration additive), désactivée par défaut et activée pour `logistique`. Elle est distincte de `is_active`, donc les sociétés historiques, leurs modules et la gestion Platform sont intacts.
- **Catalogue public, inscription et modules à l'inscription** refusent les métiers non proposés, **côté serveur**.
- Le **registre** fixe cette valeur à la création seulement et ne réécrase jamais le choix Platform ; un interrupteur « Proposé aux nouvelles inscriptions » existe sur la fiche métier Platform.
- **Aucun métier supprimé** (`beta-services` reste en base), aucun preset supprimé.

### Matrice des 25 modules Company
Le registre réel compte 25 modules ; ADR-815.2 en comptait 26. Données brutes : `docs/bmad/redesign/module-matrix-raw.json`.

| Module | Classe | Données (sociétés actives) | Action |
|---|---|---|---|
| `logistics_shipments` | A — Logistics opérationnel | 8 | Conservé, planification ajoutée |
| `workforce` | B — transversal | 12 | Conservé, Embauches ajouté |
| `workforce_documents`, `workforce_contract`, `workforce_signature` | B | 5 / 2 / 2 | Conservés |
| `workforce_leave` | B | 10 | Conservé, soldes à l'activation |
| `workforce_planning` | B | 10 | Conservé |
| `workforce_payroll` | B (préparation) | 6 | Conservé, présenté comme préparation |
| `core.members`, `core.documents`, `core.settings`, `core.theme`, `core.documentation` | C — infrastructure | 8 | Conservés |
| `core.dashboard` | C | 3 | Accueil unique |
| `core.roles`, `core.modules`, `core.billing`, `core.audit`, `core.notifications`, `core.support`, `core.workflows`, `core.jobdomain` | C | 3 | Conservés |
| `logistics_tracking`, `logistics_fleet`, `logistics_analytics` | **D — futur / non livré** (aucun écran, statut `preview`) | 2 / 2 / 0 | **Dépubliés** du catalogue des sociétés qui ne les ont pas ; achat déjà refusé par le serveur (ADR-732) ; les 2 sociétés qui les ont les gardent (rien de rétroactif) |
| E — doublon / F — code mort prouvé | — | — | Aucun module dans ces classes |

### Pages : statut de chacune des 57 pages inventoriées par ADR-815.2

| Statut | Pages |
|---|---|
| **Refaites** | `/home` (accueil unique) ; `/company/workforce/requirements` (carte du mode de calcul, éditeur partagé) ; `/company/people` (colonnes, action Embaucher, panneau retiré) ; `/company/people/:personRef` (identité unique, demandes explicites) ; `/company/workforce/time` (oubli de sortie, version personnelle) ; `/company/shipments/:id` (Planifier) ; `/company/modules` (noms, dates, pluriel) ; `/onboarding` (mode sur place, même portail) |
| **Nouvelles** | `/company/workforce/hiring` (Embauches) ; `/company/workforce/organization/positions/:id` (L1) |
| **Redirigées (URL historiques conservées)** | `/dashboard` → `/home` ; `/company/members`, `/company/workforce/employees`, `/company/workforce/documents`, `/company/plan`, `/company/workforce` (redirections préexistantes, vérifiées) ; `/organization/positions` (alias de l'onglet Postes) |
| **Fusionnées** | Panneau « Intégrations en cours » + dialogue « Inviter une recrue » → espace Embauches + assistant ; « Accueil personnel » → bloc Moi |
| **Dépubliées (développement seulement)** | `/ui-lab`, `/company/dev-lab`, `/platform/presentation-lab` (meta `devOnly`) |
| **Conservées après vérification** | Comptes (`/account-settings/:tab`, `/company/profile/:tab`, `/invitations`, `/company/my-space/:tab`, `/company/workforce/me`) ; Finance (`/company/billing*`, `consolidated-preview`, factures, paiement) ; `/company/documents/:tab` ; `/company/workforce/contract/templates` ; `/company/workforce/signature`, `/sign/:token` ; `/company/workforce/payroll*`, `/company/workforce/dsn*` ; `/company/workforce/leave` ; `/company/workforce/planning` ; `/company/workforce/time/:id` ; `/company/workforce/organization/:tab` ; `/company/workforce/settings/:tab` ; `/company/workforce/configuration` (hub) ; `/company/workforce/onboarding` (assistant de configuration du module, distinct de l'embauche) ; `/company/roles` ; `/company/settings` ; `/company/audit` ; `/company/notifications` ; `/company/support*` ; `/company/workflows*` ; `/company/shipments`, `/company/shipments/create` ; `/company/my-deliveries*` ; `/company/members/:id` et `/company/workforce/employees/:id` (redirections vers la fiche personne, ADR-768) ; `/company/403` |
| **Supprimées** | Aucune page routable. Composant supprimé : `people/_OnboardingPanel.vue` |

**Composants et services ajoutés** :
- `_HireWizard.vue` (renommé) ;
- `hiring/index.vue`, `_HireFinalizeDialog.vue`, `_RequirementAuthority.vue` ;
- `components/home/PersonalHome.vue` et `CompanyHome.vue` (anciennes pages déplacées) ;
- `RequirementAuthority`, `RequirementAuthoritySwitch`, `RequiredDocumentRequests`, `PositionChangeRequests`, `RescheduleShipment` ;
- `ApplyRequestLocale` ;
- commande `rbac:least-privilege-report`.

**Endpoints conservés pour compatibilité** : tous. Aucun endpoint retiré : aucun n'a été prouvé sans consommateur pendant la mission (une page Vue absente ne suffit pas).

**Code historique maintenu, avec justification** :
- calcul documentaire par rôle (`requirementMap`) : autorité par défaut de toutes les sociétés non basculées ;
- initialisation historique (`JobdomainGate::assignToCompany`) : seul chemin actif des inscriptions ;
- comparateur de parité : outil de la bascule ;
- presets v1 et v2 : historiques, utilisés ;
- `PersonDocumentContinuity` (§3 C) ;
- permissions inertes (ADR-807) : présentes dans des pivots, leur retrait relève d'un plan dédié.

## 5. Tests et build

**Tests ajoutés** :
- `LogisticsOnlySignupTest` (3) ;
- `Adr818HiringTest` (4) : sur place, finalisation, autorité, lecture sans écriture et changement de poste ;
- `Adr818AccountsTest` (1) ;
- `Adr818RequestLocaleTest` (1) ;
- oubli de sortie (`WorkforceTimeTrackingTest`), soldes à l'activation (`LeaveLifecycleTest`), replanification (`ShipmentWorkflowTest`).

**Tests adaptés** (ils figeaient un comportement volontairement changé) :
- `DocumentRequestWorkflowTest` : plus de création à la lecture ;
- `Adr809LogisticsSignupTest` : un métier non proposé est refusé ;
- `Ux0IntentNavigationTest` et `Adr767SecurityHardeningTest` : espace Équipe, accueil unique ;
- `WorkforceTimeTrackingTest` et `WorkforceFunctionalSmokeTest` : messages traduits ;
- `ModuleCommercialPolicyP0ATest` et `ModuleEntitlementEnforcementTest` : modules non livrés dépubliés.

**Suite complète** :
- **Jalon 1** : 4 657 réussis, 12 ignorés, **3 échecs historiques `ControllerSizeInvariant`** (identiques à la référence ; `EmployeeController` ramené à 343 lignes).
- **Finale** : voir l'addendum en fin de document.

**Build** : propre à chaque commit.

## 6. Risques restants

1. **Bascule par poste** : disponible, mais aucune société réelle n'est basculée (décision de chaque société, après reprise de ses postes). La conformité « par rôle » (widget de l'accueil) reste par rôle tant que la société n'a pas basculé.
2. **Droits documentaires des rôles existants** (Dispatcher de la société 1) : correction **proposée**, non appliquée.
3. **Preset RBAC v3** en brouillon : à publier par Platform avant d'activer l'initialisation versionnée.
4. **Site d'un salarié** : non modélisé (§3 E).
5. **Langue** : un message envoyé à une adresse sans compte (candidat) suit la langue de la personne RH qui l'envoie.
6. **Non recetté dans le navigateur** : la liste du §2 (contrats, congés de bout en bout, paie, facturation, fin de collaboration) ; ces parcours n'ont pas été modifiés.
7. **Suite E2E du dépôt** : non lancée sur DEV (elle y écrirait) ; deux specs mises à jour.

## Addendum — suite complète finale

- **Suite complète finale** (après le dernier changement de code) : **4 662 réussis, 12 ignorés, 3 échecs**.
  - Les 3 échecs sont les échecs historiques `ControllerSizeInvariant`.
  - La liste des contrôleurs en dépassement est inchangée ; aucun contrôleur modifié par la mission n'a grandi au-delà de sa taille de départ (`EmployeeController` 343, `WeekTemplateController` 316, contre 317 avant).
- **Build** : propre.
