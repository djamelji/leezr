# ADR-815 — Personne, salarié, poste et rôle : modèle réel et modèle cible

> Audit en lecture seule. Données : base DEV au 2026-11-17 (`evidence/`). Code : `70ec3dbe`.

## 1. Modèles réels et responsabilités constatées

| Concept produit | Support réel | Contenu clé | Responsabilité constatée |
|---|---|---|---|
| **Compte** | `users` | identité de connexion, e-mail, mot de passe, 2FA | Accès au logiciel. Il porte aussi des valeurs de champs « personne » (`field_values.model_type=User`). |
| **Appartenance à la société** | `memberships` | `user_id`, `company_id`, `role` (`owner` / `user`), `company_role_id`, suspension, invitation | Lien compte ↔ société. Porte l'invitation et le rôle RBAC. |
| **Personne** | **aucune table** | `people/{personRef}` : `user-{id}` ou `employee-{id}` (`PersonController`) | Notion **virtuelle** : un compte, une fiche salarié, ou les deux reliés. |
| **Salarié** | `workforce_employees` | identité RH, `user_id` facultatif, `job_role_id`, `department_id`, `manager_id`, `hire_date`, `status`, `preparatory_at` | Fiche RH. Elle **peut exister sans compte** (société 1 : 36 sur 52). |
| **Contrat** | `workforce_employment_contracts` | type, convention, heures, dates, `status`, `is_current` | Condition d'emploi. Exigé pour planifier (`CreateShiftUseCase` I4). |
| **Poste** | `workforce_job_roles` | `title`, `level` (agent / manager), `department_id`, taux horaire | Poste métier. **Peu utilisé** : 10 lignes sur toute la base ; société 1 : 4 postes, 7 salariés sur 52 en ont un ; société 101 : aucun. |
| **Rôle RBAC** | `company_roles` (+ `company_role_permission`) | `key`, `name`, `is_administrative`, **`archetype`**, **`required_tags`**, **`field_config`**, **`doc_config`** | Droits d'accès, **mais aussi** l'atterrissage, les widgets, les pièces obligatoires, les champs affichés ou requis, et la conformité « par rôle ». |
| **Organisation** | `workforce_departments` (`manager_id`, `parent_id`), `organization_presets` | arbre de services | Structure. Société 1 : 3 services, 2 salariés rattachés ; société 101 : aucun. |
| **Lieux** | `workforce_work_locations` | sites, clients | Planification. |
| **Métier** | `companies.jobdomain_key` **et** `company_jobdomain` (pivot) | `logistique` | Assemble modules, champs, pièces, rôles et presets par défaut. Le métier est stocké deux fois (colonne et pivot). |

## 2. Invariants produit : état réel

| # | Invariant | État | Preuve |
|---|---|---|---|
| 1 | Une personne peut exister sans compte | **Respecté** | `workforce_employees.user_id` facultatif ; 36 sur 52 sans compte dans la société 1. |
| 2 | Employé sans accès au logiciel | **Respecté** | Même support. « Ajouter une personne » propose « Non, pas pour le moment ». |
| 3 | Un poste n'est pas un rôle RBAC | **Violé dans les usages** | L'**archétype** du rôle RBAC décrit un métier (`JobdomainRegistry` : `field_worker` = « Conducteur / Chauffeur », `operations_center` = « Exploitant / Dispatcher », `management` = « Manager / Direction »). Il décide de l'atterrissage (`WorkspaceResolver`), des widgets (`DashboardCatalogService::forArchetype`) et des pièces obligatoires (`required_tags`). |
| 4 | Le rôle RBAC ne détermine pas à lui seul les pièces RH | **Violé** | `DocumentResolverService::requirementMap($companyId, $roleKey)` : pièces = catalogue obligatoire ∩ `required_tags` **du rôle**, plus activation société, puis surcouche `doc_config` **du rôle**. Le poste n'est jamais lu. Les demandes en lot se font **par rôle** (`BatchRequestByRoleUseCase`), la conformité est agrégée **par rôle**. 40 rôles RBAC sur 49 portent `doc_config` et `field_config`. |
| 5 | Un responsable peut être salarié | **Respecté** | Le propriétaire a une fiche salarié (pointage visible sur `/home`). |
| 6 | Un seul métier par société | **Respecté fonctionnellement** | Le stockage est doublé (`companies.jobdomain_key` + `company_jobdomain`). |
| 7 | Le métier recommande, il n'impose pas | **Partiellement** | Les rôles, champs et pièces par défaut sont **copiés** à la création (`JobdomainGate::assignToCompany`). Les presets publiés sont des propositions (ADR-808). La garde « jamais impératif sans base légale » existe (`Requirement.php:95-98`). |
| 8 | La société personnalise ses postes et exigences | **Partiellement** | Les postes sont modifiables (Organisation → Postes). Les exigences par poste sont modifiables (`/company/workforce/requirements`), mais **en mode parallèle** : elles ne pilotent que l'onboarding, pas la conformité du dossier. |

## 3. Confusions identifiées

| Notion | Aujourd'hui portée par | Problème |
|---|---|---|
| **Poste** (« Chauffeur-livreur ») | `workforce_job_roles.title`, **et** le nom du rôle RBAC (« Driver », « Chauffeur-livreur » depuis ADR-813), **et** l'archétype (`field_worker`) | Trois représentations pour un même poste. Les pièces suivent le rôle RBAC, l'onboarding suit le poste. |
| **Fonction / niveau** | `workforce_job_roles.level` (agent / manager) **et** l'archétype `management` **et** `is_administrative` | « Manager » est un niveau de poste, un archétype et un nom de rôle RBAC. |
| **Responsable hiérarchique** | `workforce_employees.manager_id`, `workforce_departments.manager_id`, permission `timesheet_approve_team` | Deux supports pour le responsable. Le périmètre managérial suit `manager_id` ; un manager sans `members.view` reçoit 404 sur une fiche qu'un chauffeur peut lire (voir `company-routes-permissions.md`, R1). |
| **Contrat** | `workforce_employment_contracts` | Bien isolé, mais absent du parcours « Ajouter une personne » (il se crée ensuite dans la fiche). |
| **Groupe d'autorisations** | `company_roles` | Il porte aussi l'affichage des champs (`field_config`, `visibility_roles` des définitions de champs) et les pièces (`doc_config`, `required_tags`). |
| **Statut d'accès** | `memberships.role` (owner / user), `is_administrative`, `roleLevel` (management / operational, calculé) | Trois indicateurs différents de « peut gérer ». |

## 4. Modèle fonctionnel cible (simple, sans nouvelle table)

```
Personne  = fiche salarié (workforce_employees), même sans compte
   ├─ Compte (users + memberships) ......... facultatif : accès au logiciel
   │     └─ Rôle d'accès (company_roles) .... UNIQUEMENT des permissions + niveau de gestion
   ├─ Poste (workforce_job_roles) .......... ce que la personne fait → exigences d'intégration
   │     └─ Service (workforce_departments) et responsable (employees.manager_id)
   └─ Contrat(s) ........................... conditions d'emploi
```

Règles cibles :
1. **Le rôle RBAC ne décrit que des droits.** `archetype`, `required_tags`, `doc_config` et `field_config` cessent de piloter quoi que ce soit (sauf comme données de compatibilité lues une fois, voir le plan). L'atterrissage et les widgets découlent des permissions.
2. **Le poste porte les exigences d'intégration** : champs, pièces et documents à produire, par la table `requirements` existante (`subject_scope = job_role`). Il n'y a ni nouveau moteur, ni nouvelle table.
3. **Un seul responsable** : `workforce_employees.manager_id`. `workforce_departments.manager_id` sert de valeur par défaut à l'affectation.
4. **Le niveau de gestion** vient de `is_administrative` du rôle d'accès. `archetype` reste en lecture, pour compatibilité.
5. **Le métier (JobDomain)** fournit des postes recommandés et leurs exigences recommandées (presets organisation et exigences existants). La société adopte, modifie ou ignore.

## 5. Ce qui existe déjà pour porter la cible

- `workforce_job_roles` et l'écran Organisation → Postes.
- La table `requirements`, avec ses colonnes `subject_scope`, `stage` (`hire`, `onboarding`), `effect`, `level`, `source`, `derived_from_id`, `customized_at`, ainsi que `EffectiveRequirementResolver::subjectForJobRole`. C'est déjà la source de l'onboarding.
- `RequirementParityComparator` : il compare déjà l'ancien calcul (rôle) et le nouveau (poste). C'est l'outil de bascule.
- Presets `logistique_base_v1` (organisation, postes) et `logistique_requirements` (exigences), déjà liés au métier.

## 6. Décisions produit nécessaires

1. Les pièces obligatoires d'une personne **avec compte et sans poste** suivent-elles le poste « par défaut » de la société, ou seulement les règles de la société ?
2. Faut-il imposer un poste à chaque salarié (aujourd'hui 45 sur 52 n'en ont pas dans la société 1) ?
3. Le nom du rôle RBAC doit-il cesser de ressembler à un poste (par exemple « Accès chauffeur » plutôt que « Chauffeur-livreur ») ?
