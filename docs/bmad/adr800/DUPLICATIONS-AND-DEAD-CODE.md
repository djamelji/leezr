# ADR-800 : doublons, code mort, code suspect, mécanismes parallèles

> SHA `c3f468fa`.
>
> Chaque constat porte un niveau de confiance :
> - **Prouvé (exécution)** : vérifié par une recette navigateur en lecture seule ou par une requête sur la base DEV ;
> - **Prouvé (statique)** : zéro référence dans tout le dépôt, résolution dynamique exclue ;
> - **Fortement probable** ;
> - **À vérifier**.

## 1. Pages et écrans en double (8 routes redondantes prouvées)

| # | Écrans | Même objet | Preuve | Impact | Confiance | Recommandation |
|---|---|---|---|---|---|---|
| D1 | `/company/members` (`members/index.vue`, 1 224 l.) ↔ `/company/people` | Liste des membres | ADR-768 : « listes en alias » ; le détail `members/[id]` redirige déjà, pas la liste | Deux listes à maintenir ; d'anciens liens ouvrent l'ancienne | Prouvé (statique) | Rediriger la liste comme le détail, puis retirer |
| D2 | `/company/workforce/employees` (562 l.) ↔ `/company/people` | Liste des salariés | Le détail `employees/[id]` redirige vers `people/employee-{id}` ; la liste reste autonome | Idem | Prouvé (statique) | Idem |
| D3 | `/company/workforce/documents` ↔ `/company/documents/:tab` (onglet RH) | Registre RH | `documents/[tab].vue` importe `@/pages/company/workforce/documents/index.vue` | Page à double vie (route + composant) | Prouvé (statique) | Garder le composant, rediriger la route |
| D4 | `/company/workforce/signature` ↔ `/company/documents/:tab` | Signatures | Même mécanisme (import direct) | Idem | Prouvé (statique) | Idem |
| D5 | `/company/workforce/contract/templates` ↔ `documents?tab=templates` ↔ `workforce/settings` (`_ContractSettingsSection`) | Modèles de contrat | 3 points d'entrée ; la route standalone n'a aucun menu | Confusion sur « où sont les modèles » | Prouvé (statique) | Une seule entrée : Documents → Modèles |
| D6 | `/platform/organization-presets` + `/:id` ↔ Catalogue → Presets (kind `organization`) ↔ JobDomain → onglet Organisation | Presets d'organisation (**même table** `organization_presets`) | `PresetService::find` délègue à `OrganizationPreset` ; `JobdomainPresetWorkspace::draft` refuse `organization` (« dedicated editor ») ; un menu Platform dédié existe | 3 entrées, 2 éditeurs différents pour un même objet | Prouvé (statique) | Éditer l'organisation dans l'onglet JobDomain comme les autres familles ; retirer la page dédiée et son menu |
| D7 | Workforce → Paramètres → onglet `worktime` (`_WorkforceRulesSection`, API `/workforce/config/{work-time,breaks}`) ↔ Assistant → « Temps de travail » (`_WorkingTimeSetup`, API `/workforce/working-time-setup`) | Règles de temps de travail (une source : `WorkforceRuleResolver`) | Deux API et deux écrans, avec des formats et des statuts différents (l'assistant affiche « contrôlé / enregistré / non vérifié », l'onglet non) | Réglage modifiable à deux endroits, avec deux discours | Prouvé (statique) | Faire de `_WorkingTimeSetup` l'unique écran (assistant **et** paramètres) ; garder `/workforce/config` pour la lecture seule ou le mode avancé |
| D8 | Catalogue Platform → Presets (`_CatalogPresets` + `_PresetEditorDialog`) ↔ JobDomain → onglets Rôles / Champs & documents / Planning (`_JobdomainPresetTab`, même dialogue) | Presets versionnés | ADR-798 puis ADR-799 ont ajouté un 2ᵉ point d'entrée sans retirer le 1ᵉʳ | Deux chemins de publication. Le catalogue publie sans lier ; l'onglet publie **et** lie | Prouvé (statique) | Le catalogue devient une **lecture** (bibliothèque partagée) ; l'édition se fait dans le JobDomain |

Doublons de déclaration de menu, sans doublon d'écran :
- `company-people` est déclaré par 3 modules (`core.roles`, `core.members`, `workforce`) ;
- `company-documents-tab` par 3 entrées ;
- `company-workforce-leave` par 2 entrées ;
- `company-workforce-settings-tab` par 2 entrées.

`NavBuilder` les déduplique (ADR-766). C'est légitime, mais la règle « quel module possède l'entrée » est implicite.

## 2. Mécanismes parallèles

| # | Mécanisme | Branches | Bascule prévue | Confiance |
|---|---|---|---|---|
| M1 | Initialisation d'une société | `JobdomainGate::assignToCompany` (production) ∥ `VersionedCompanyInitializer` (flag off) | Aucune date ; DEV n'a **0** preset publié, **0** lien JobDomain → preset | Prouvé (base DEV) |
| M2 | Sources des rôles initiaux | `jobdomains.default_roles` ∥ overlays de marché ∥ preset `rbac` | Aucune | Prouvé |
| M3 | Sources des presets de planning | `jobdomains.workforce_presets` (importé par `/workforce/planning/presets/import`) ∥ preset `planning` | Aucune | Prouvé |
| M4 | Exigences documentaires | `DocumentResolverService` (production) ∥ `EffectiveRequirementResolver` (comparé) ∥ `CompanyRequirementResolver` (aperçu Platform) | Interdite par les lots précédents (« aucune bascule générale ») | Prouvé |
| M5 | Écran des exigences Company | `/company/workforce/requirements` expose à la fois `/requirements` (actuel), `/requirements/legacy` (historique) et `/requirements/modules` (contributions) | — | Prouvé (statique) |
| M6 | Dépendances de modules | `DependencyResolver` (tests seulement) ∥ `DependencyGraph` + `ModuleActivationEngine` | — | Prouvé (statique) |
| M7 | Écriture des permissions de rôle | `CompanyRoleController` (matrice) ∥ `ThemeRoleVisibilityController` (`theme.view`) | — | Prouvé (statique) |

## 3. Code mort

### 3.1 Prouvé (statique) : retirable après validation, sans compatibilité à prévoir

- **Classes PHP sans aucune référence** dans le dépôt (code, tests, ADR) :
  - `Workforce/UseCases/CompleteShiftUseCase`, `DetectAnomaliesUseCase`, `GrantLeaveUseCase`, `StornoLedgerEntryUseCase` ;
  - `Workforce/ReadModels/ContributionRulesReadModel`, `DocumentTemplateReadModel`, `TemplateVariableSchemaReadModel`.
- **Endpoint toujours en échec** : `PUT api/company/jobdomain`, qui répond 422 à chaque appel, avec l'action `jobdomain.store.setJobdomain` jamais appelée.
- **Composants Vue jamais importés** (18) :
  - `company/views/CompanySwitcher`, `DocumentTypesDrawer` ;
  - `core/components/SectionHeader` ;
  - `pages/company/billing/_BillingEmpty`, `documents/_DocumentsOverview`, `people/_PersonCreateEmployeeDialog`, `profile/_CompanyProfileDocuments`, `workflows/_WorkflowCreateDrawer`, `workforce/_WorkforceActionCard`, `workforce/_WorkforceModuleCard`, `workforce/documents/_PayslipActionCards` ;
  - `help-center/_HelpCenterQuickActions` ;
  - `platform/ai/_AiUsageTab`, `billing/_BillingMetricsWidget`, `jobdomains/_MarketOverlaySelector` (orphelin depuis ADR-799), `operations/_OperationsUsage` ;
  - `views/pages/authentication/AuthProvider`, `views/pages/company-settings/CompanyDocumentsSection`.

  Les 13 `_*.vue` de cette liste restent **routables** (voir P0-1).
- **Moteur `FeatureFlag`** complet (table, service, 7 endpoints, onglet Platform) : 0 flag en base DEV et **0 lecture** dans le code.

### 3.2 Mort probable (cité seulement par un ADR) : vérifier l'ADR, puis retirer

- `BulkGenerateDocumentUseCase`, `CreateDocumentTemplateUseCase`, `UpdateDocumentTemplateUseCase`, `DeleteGeneratedDocumentUseCase`, `UpdateSignatureStatusUseCase`.
- `Data/ApproveLeaveData`, `RequestLeaveData`.
- `Core/Email/EmailStatsService`.
- `Core/Modules/DependencyResolver` : cas M6, utilisé par les tests seulement.

### 3.3 Suspect (testé, non branché)

- `MultiPadesSignatureService`, `FieldConfigHealthCheck`.
- Endpoints orphelins de l'interface (BACKEND §2) : `world-settings`, `metrics-export`, `dashboard/health`, `organization-presets/{id}/simulate`, `reset-key`, `funnel-track`, `overlays` (×3), `checkout/status`, `billing-day`, `pay-now`.

### 3.4 Modules « coquilles »

- `logistics_fleet`, `logistics_tracking` et `logistics_analytics` sont de simple manifests : 0 route, 0 menu, 0 permission, statut `preview`.
- `logistics_fleet` est **actif** pour la société 1.
- C'est conforme à ADR-732 (non achetables), mais la page Modules les affiche.

## 4. Options visibles qui ne font rien, ou fonctions construites mais inaccessibles

| Constat | Détail | Confiance |
|---|---|---|
| 9 permissions cochables sans effet | `workforce.admin`, `workforce.leave_adjust`, `workforce_documents.manage_templates`, `workforce_documents.sign`, `shipments.manage_fields`, `shipments.delete`, `documentation.view`, `assign_support`, `manage_audience` (MODULE-RBAC-MAP §2) | Prouvé (statique) |
| `assign_support` : mauvaise garde | L'assignation de ticket exige `manage_support` | Prouvé (route:list) |
| Rythme, jours fixes, durée cible, jours de repos | Enregistrés, non contrôlés au planning. L'assistant l'affiche honnêtement (ADR-799) ; l'onglet Paramètres `worktime` ne le dit pas | Prouvé |
| Éditeur de revue des bulletins (ADR-764) | `split`, `regroup`, `period`, `preview`, `pages/exclude` et `revoke` existent et sont testés, mais l'écran n'offre que `exclude` et `assign` | Prouvé (statique) |
| Campagnes documentaires | `launch`, `cancel` et `recipients` existent, sans bouton | Prouvé (statique) |
| Partage de champs (ADR-796) | `profile/disclosures` et son retrait, sans écran | Fortement probable |
| Relance antivirus | `uploads/{upload}/rescan`, sans bouton | Fortement probable |
| Assistant Workforce, étape « roles » | Liste les rôles sans rien demander | Prouvé (statique) |

## 5. Hygiène des données DEV (constat seulement, rien n'est modifié)

- 12 rôles Platform `e2e_limited_*` / `e2e_plt_limited_*` laissés par d'anciennes recettes.
- 6 rôles `recette-*` dans la société 1.
- 2 brouillons de presets (`rbac`, `planning`).
- 9 presets d'organisation (5 publiés, 4 brouillons).

À purger seulement sur décision explicite, par identifiants exacts (règle M7 : jamais par préfixe).
