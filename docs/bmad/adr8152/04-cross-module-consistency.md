# ADR-815.2 — 04. Cohérence transversale entre modules

> **Statut des constats**
> - **[P]** : prouvé par le code, la base ou une réponse HTTP. La référence est donnée.
> - **[R]** : observé en recette navigateur, sur un clone isolé (ADR-815 ou parcours d'ADR-815.2).
> - **[C]** : choix de conception proposé.

## 1. Matrice concept → source de vérité → consommateurs → contradictions → cible

| Concept | Propriétaire métier | Source de vérité actuelle | Consommateurs | Contradictions | Solution cible [C] |
|---|---|---|---|---|---|
| **Personne** | RH | Aucune table. `workforce_employees` (fiche RH) **ou** `users` (compte) ; `personRef` = `user-{id}` ou `employee-{id}` [P] | Équipe, documents, planning, expéditions, conformité | Une même personne a 2 références d'URL possibles. Les expéditions s'affectent à un **compte** (`shipments.assigned_to_user_id`) [P], le planning à un **salarié**. Les demandes créées à la lecture visent un **compte** (`MemberDocumentWorkflowReadModel.php:90`) [P]. | La fiche salarié est la personne ; le compte est un attribut facultatif. Toute affectation métier vise le salarié. |
| **Poste** | RH / direction | `workforce_job_roles` [P] | Intégration (`OnboardingPlanner`), organisation | Nom du rôle RBAC = poste (« Chauffeur-livreur »), archétype = poste (`field_worker`) [P] | Le poste est l'unique porteur du métier et des exigences. |
| **Rôle d'accès** | Administrateur | `company_roles` + permissions [P] | Navigation, routes, widgets, **pièces**, **champs**, **arrivée**, **conformité** [P] | Porte 5 responsabilités qui ne sont pas des droits [P] | Droits uniquement. |
| **Responsable** | RH | `workforce_employees.manager_id` **et** `workforce_departments.manager_id` [P] | Périmètre (`WorkforceAccess`), validation des feuilles | Deux supports. Modifiable seulement **en lot** (`employees.bulk.actions.set_manager`) [P] | Un champ unique dans la fiche ; le service propose une valeur par défaut. |
| **Service / département** | Direction | `workforce_departments` [P] | Planning, organisation | Libellé « Départements » dans l'organisation et « Services » dans d'autres écrans [P] | Un seul terme : « Service ». |
| **Site / lieu** | Exploitation | `workforce_work_locations` [P] | Planning | Pas de notion d'établissement (SIRET secondaire) [P] | Lieux = sites ; l'établissement est hors périmètre actuel (décision). |
| **Exigence (pièce ou champ)** | RH + règles applicables | **3 autorités** : activation société, `doc_config` / `field_config` du rôle, `requirements` par poste [P] | Fiche, Mon espace, conformité, intégration, campagnes | La page des exigences par poste se dit « sans effet » [R]. La conformité est par rôle [P]. | `requirements` par poste, en plus des règles de la société. |
| **Demande de document** | Gestionnaire documentaire | `document_requests` [P] | À traiter, fiche, Mon espace, widgets | **6 chemins de création**, dont une écriture lors d'une lecture [P] | Création à l'affectation d'un poste, à une embauche, à une campagne ou à un renouvellement. |
| **Conformité** | RH | `DocumentComplianceReadModel` (par rôle) [P] | Widgets, Documents | « 1 document à régulariser » contre « Aucun document requis configuré » sur le même écran [R] (cause non établie) | Une seule fonction de calcul, par poste, réutilisée par tous les affichages. |
| **Intégration / « onboarding »** | RH | 4 objets : checklist société (`/dashboard/onboarding`), assistant RH (`workforce_onboardings`), dossier recrue (`onboarding_invitations`), checklist de la fiche (`file.onboarding`) [P] | Accueil, Réglages, Équipe, fiche | Même mot pour 4 choses [P] | « Démarrage » (société), « Configuration du module », « Embauche » (dossier + checklist). |
| **Contrat** | RH | `workforce_employment_contracts` [P] | Planning (exige un contrat actif), paie, signature | Absent du parcours d'ajout et du dossier d'intégration [P] | Étape de l'embauche. |
| **Statut d'une personne** | RH | `workforce_employees.status` (active, inactive, on_leave, suspended, terminated) + `preparatory_at` [P] | Listes, planning | « Préparatoire » n'est pas un statut mais une date [P] | Statut « en cours d'embauche » visible. |
| **Statut d'accès** | Administrateur | `memberships` : `suspended_at`, `acceptance_pending_at`, `invitation_*` ; calcul « connecté / invité / expiré / suspendu / sans compte » [P] | Équipe, fiche | Statut calculé, pas stocké ; libellés cohérents depuis ADR-772 | Inchangé. |
| **Niveau de gestion** | — | `memberships.role` (owner) + `is_administrative` + `roleLevel` + archétype [P] | Routeur (`surface: structure`), navigation, arrivée | 4 indicateurs [P] | `is_administrative` + permissions. |
| **« Voir les salariés »** | — | `workforce.view` = soi + rapports directs ; + `workforce.manage` = tous ; `members.view` = annuaire complet [P] | Équipe, fiche | Un rôle « RH » ne voit personne, un chauffeur voit tout l'annuaire [R] | Périmètre explicite dans l'éditeur de rôles (soi / équipe / entreprise). |
| **Accueil** | — | `/home` et `/dashboard`, même grille [P] [R] | Tous | Deux accueils ; widgets collectifs sous droits personnels [R] | Un seul accueil à blocs gardés. |
| **Annulé** | — | `cancelled` (contrats, congés, créneaux, demandes) contre **`canceled`** (expéditions) [P] | Filtres, traductions | Orthographe différente pour un même état [P] | Un vocabulaire unique d'états, partagé. |
| **Validé / approuvé** | — | `approved` (congés, feuilles, documents), `validated` (dossier d'intégration), `completed` (signature), `delivered` [P] | Listes | Termes différents pour « accepté par un responsable » [P] | Libellé UI commun « Validé » ; les codes restent. |
| **Brouillon** | — | `draft` : contrats, congés, feuilles, créneaux, expéditions, dossiers [P] | Listes | Cohérent [P] | Inchangé. |

| **Activation tardive d'un module** | — | Activer Congés après un contrat actif ne crée pas les soldes [R parcours 15] | Mon activité, Absences | Message « activez votre contrat » alors qu'il est actif | Ouverture des soldes à l'activation |
| **Pointage en cours** | Manager | `time_entries.status = working` [P] | Correction | Correction refusée avec un message anglais brut [R parcours 14] | Clôture corrigible avec motif |
| **Nom d'un module** | Platform | Nom du catalogue (carte) **et** nom de la plateforme (« Requis par », désactivation, rôles : « Workforce Leave ») [R] | Modules, Rôles | 3 noms pour un même module | Un seul nom traduit |

## 2. Plusieurs écrans de configuration pour une même règle [P]

| Règle | Écrans |
|---|---|
| Champs requis d'une personne | Équipe → réglages des champs ; Rôles → tiroir (`field_config`) ; Exigences par poste ; sélection de chaque dossier |
| Pièces requises | Documents → Paramètres ; `doc_config` du rôle (sans écran société) ; Exigences par poste ; campagnes et demandes en lot par rôle ; sélection de chaque dossier |
| Postes | Organisation → Postes ; presets d'organisation (Réglages → Sites, services & postes) |
| Temps de travail | Assistant RH (`/company/workforce/onboarding`) ; Règles RH (`/company/workforce/settings/worktime`) ; assistant du module (`/company/modules/workforce`) |
| Droits d'un module | Rôles ; assistant du module (étape « accès ») ; accès en attente (Équipe) |

## 3. Données personnelles mêlées aux données collectives [P][R]

- **`/home` « Accueil personnel »** : grille de l'entreprise sous « Mes widgets ».
- **Salarié** : il reçoit des agrégats de l'entreprise (présents, anomalies, congés en attente des autres).
- **`/company/workforce/time`** (« le travail de l'équipe ») : servi au salarié avec un onglet « Mon activité » au milieu des onglets de gestion.
- **`/company/workforce/leave`** : sert à la fois au demandeur et au valideur (1 577 lignes).

## 4. Parcours qui supposent un compte alors qu'il n'existe qu'une fiche salarié [P]

- **Expéditions** : l'affectation exige un compte ; un chauffeur sans accès au logiciel ne peut pas recevoir de livraison.
- **Demandes de documents implicites** : créées uniquement pour les comptes. Les 35 demandes des salariés sans compte de la société 1 sont manuelles.
- **Mon espace, signatures, bulletins** : exigent un compte. Pour une personne sans compte, seul le portail d'intégration existe, pendant l'embauche.

## 5. Modules qui exposent des pages sans leurs prérequis [P][R]

- **Planning** : sans contrat actif, la création d'un créneau est refusée (message traduit, ADR-812) ; l'écran n'indique pas en amont qui est planifiable.
- **Paie** : dépend des congés (`workforce_payroll` exige `workforce_leave`) ; ses prérequis métier (contrats, feuilles validées, profil légal SIRET/NAF) ne sont pas présentés avant l'entrée.
- **`logistics_tracking`, `logistics_fleet` et `logistics_analytics`** : modules sans permission ni page. Le module « flotte » alimente un widget ; les deux autres n'ont aucune interface (ADR-806 : capacités inertes).
- **Exigences par poste** : page active mais « sans effet » [R].

## 6. Pages qui mélangent exploitation et administration [P]

- **Équipe** : personnes (exploitation RH) + onglet « Rôles & autorisations » (administration des accès) + « Gestion RH » (outils en lot).
- **Documents** : traitement quotidien (À traiter) + paramétrage (types de pièces) + modèles + campagnes, dans 15 onglets détectés.
- **Modules** : catalogue commercial + assistants de configuration + droits.

## 7. Écriture métier pendant une lecture [P]

| Lecture | Écriture | Référence |
|---|---|---|
| Consultation des documents d'un membre | création de `document_requests` | `MemberDocumentWorkflowReadModel.php:90` |
| Ouverture de `/home` sans disposition (administratif) | `PUT /dashboard/layout` | `home.store.js:loadDashboard` |
| Ouverture de `/dashboard` sans disposition | construction et enregistrement de la disposition « intelligente » | `dashboard.store.js` (`buildSmartDefaultLayout`) |

## 8. Liens morts, champs et boutons inactifs, actions sans permission

- **Liens morts** : aucun lien mort relevé par la recette ADR-815. 9 profils × 41 URL : toutes les URL interdites mènent à « Accès refusé ». `/company/members/2` mène à la liste (404 sur `people/resolve` : identifiant de membre inexistant, comportement voulu).
- **Boutons inactifs** :
  - « Appliquer la proposition » (ADR-811 C3) : corrigé en ADR-812 ;
  - la page des exigences permet d'ajouter des éléments **sans effet** [R].
- **Actions accessibles sans permission** :
  - réglages, devis et impact de désactivation des modules (R2) ;
  - agrégats des widgets (R0) ;
  - écran de gestion du temps ouvert au salarié (R5).

  Détail : `docs/bmad/adr815/company-routes-permissions.md`.
