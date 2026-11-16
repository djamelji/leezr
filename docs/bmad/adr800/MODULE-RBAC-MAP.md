# ADR-800 : carte Modules → capacités → permissions → rôles → écrans → API

> **Sources.** `ModuleRegistry::definitions()` (51 manifests), tables DEV `company_permissions` (75) et `platform_permissions` (43), middlewares de `route:list`, `definePage` et littéraux frontend. SHA `c3f468fa`.
>
> **Comptages.** Le nombre entre parenthèses est le nombre de routes API gardées par la permission, issu de `EnsureCompanyAccess:use-permission|use-any-permission` et `EnsurePlatformPermission`. Exhaustif.

## 1. D'où viennent les permissions ? (prouvé)

- **Une seule source** : chaque permission est déclarée dans le manifest de son module, puis synchronisée en base (`ModuleRegistry::sync`, `permissions:reconcile`).
  - Recoupement manifests ↔ base DEV : 118 = 118. Aucune permission déclarée dans 2 modules. Aucune permission en base absente des manifests.
- Les **bundles** (78 au total) sont la couche « capacité métier » déjà existante. `_PermissionMatrix.vue` les affiche en **mode simple** ; le mode avancé montre les clés techniques. Platform et Company partagent ce composant.
- **Attribution initiale** à la création d'une société :
  - en production, `JobdomainGate::assignToCompany` lit `jobdomains.default_roles` (JSON historique) et les overlays de marché ;
  - derrière le flag désactivé, `RbacPresetAdapter` applique un preset `rbac` versionné.
- **Attribution effective** :
  - `CompanyRoleController::update` → `CompanyRole::syncPermissionsSafe` : un rôle non administratif ne peut pas recevoir une permission `is_admin` ;
  - **seconde porte d'écriture** : `ThemeRoleVisibilityController` attache directement `theme.view` aux rôles (`settingsPanels` `theme-role-visibility`).
- **Activation d'un module** : aucune permission n'est attribuée. Les écouteurs de `ModuleEnabled` gèrent les widgets, la facturation et les types de congés, pas les droits. Les rôles existants ne reçoivent donc **jamais** les capacités du module activé, sauf modification manuelle.
- **Vérifications**
  - **API** : middleware par route. Aucune mutation Company non gardée hors actions personnelles (cf. BACKEND §7).
  - **UI** : `guards.js` lit `meta.module`, `meta.permission` et `meta.anyPermission` ; `useCan` sert dans les composants.
  - **Écart** :
    - les 192 routes `_*.vue` n'ont aucune méta : voir P0-1 ;
    - 12 pages Platform ont `meta.module` sans `meta.permission`, l'API restant gardée : `access/:tab`, `account/:tab`, `catalog/:tab`, `communications/:tab`, `companies`, `dashboard/:tab`, `documents/:id`, `markets/:key`, `messaging/:tab`, `operations/:tab`, `settings/:tab`, `support/:id` ;
    - ~~`platform/billing/consolidated-preview` n'a aucune garde~~ : **faux positif**, corrigé par ADR-801 (la page déclare `view_billing`).

## 2. Permissions qui ne protègent rien (prouvé : 0 vérification hors manifest, en API comme en UI)

| Permission | Module | Effet actuel quand un admin la coche | Recommandation |
|---|---|---|---|
| `workforce.admin` | workforce | Aucun | Retirer du catalogue, ou la brancher si une action la justifie |
| `workforce.leave_adjust` | workforce_leave | Aucun : il n'existe **aucune route d'ajustement de solde**. Les mutations de congés sont gardées par `leave_request`, `leave_manage` et `leave_approve` | Retirer, ou la brancher le jour où l'ajustement manuel existe |
| `workforce_documents.manage_templates` | workforce_documents | Aucun : les modèles de contrat sont gardés par `workforce_contract.manage_templates` (6 routes) | Fusionner avec la garde réelle |
| `workforce_documents.sign` | workforce_documents | Aucun : la signature est gardée par `workforce_signature.*` | Retirer (doublon conceptuel) |
| `shipments.manage_fields` | logistics_shipments | Aucun | Retirer ou brancher |
| `shipments.delete` | logistics_shipments | Aucun : il n'existe **pas de route de suppression** | Retirer |
| `documentation.view` | core.documentation | Aucun | Retirer, ou garder si le centre d'aide doit être restreint |
| `assign_support` (Platform) | platform.support | Aucun : l'assignation (`PUT support/tickets/{id}/assign`) est gardée par `manage_support` | **Permission incorrecte** : brancher `assign_support` sur l'assignation |
| `manage_audience` (Platform) | platform.audience | Aucun : aucune route Platform d'audience | Retirer ou brancher |

En plus, `jobdomain.manage` ne garde que `PUT api/company/jobdomain`, qui répond toujours 422 : elle ne protège en pratique rien.

Les permissions de portée fine vérifiées dans le code mais pas par route sont **légitimes**. Il ne faut pas les fusionner, car elles protègent des populations ou des données différentes :
`members.sensitive_read`, `workforce.sensitive_read`, `documents.view_confidential`, `workforce.leave_view_all`, `workforce.compensation_manage`, `settings.view`, `theme.view`, `run_sensitive_automations`.

## 3. Rôles

- **Company 1 (DEV)** : 11 rôles.
  - 5 rôles métier : owner, manager, ops_manager et dispatcher, qui sont administratifs ; driver, qui ne l'est pas.
  - **6 rôles `recette-*`** : résidus de recette sur une société réelle. Ils ne sont **pas** supprimés pendant cet audit.
- **Platform** : 19 rôles, dont **12 `e2e_limited_*` / `e2e_plt_limited_*`**, résidus E2E (hygiène DEV, voir backlog P2-6).
- `is_administrative` n'est pas un contournement : il autorise un rôle à détenir des permissions `is_admin`.

## 4. Interfaces de gestion des droits (concurrence)

| Interface | Objet | Statut |
|---|---|---|
| Company : Collaborateurs → onglet Rôles (`people/index.vue` embarque `roles.vue` + `_PermissionMatrix`) | Rôles et permissions de la société | **Principale** |
| `/company/roles` (standalone) | Idem | Alias : redirige |
| Company : Thème → visibilité par rôle (`ThemeRoleVisibilityController`) | Écrit `theme.view` sur les rôles | **Seconde porte d'écriture** sur les mêmes rôles |
| Platform : Accès → Rôles (`access/_RolesTab.vue` + `_PermissionMatrix`) | Rôles Platform | Principale Platform |
| Platform : Catalogue → Presets (RBAC) et JobDomain → onglet Rôles | Rôles **initiaux** proposés (preset `rbac`) | Deux points d'entrée pour le même preset |
| JobDomain `default_roles` (historique, lecture seule) | Rôles initiaux **réellement** appliqués aujourd'hui | Source effective, invisible et non éditable |
| Assistant Workforce, étape « roles » | **Liste** les rôles, sans rien demander | Étape passive |

## 5. Modules (exhaustif)

| Module | Portée / type | Requiert | Menus (clé → route) | Permissions (routes gardées) | Bundles |
|---|---|---|---|---|---|
| payments.stripe | admin/addon | — | — | — | 0 |
| core.settings | company/core | — | company-profile → company-profile-tab<br>my-space → company-my-space-tab<br>account-settings → account-settings-tab | `settings.view` (0)<br>`settings.manage` (14) | 2 |
| core.documentation | company/core | — | — | `documentation.view` (0 ⚠ jamais vérifiée) | 1 |
| core.roles | company/core | — | settings-roles → company-people | `roles.view` (2)<br>`roles.manage` (4) | 1 |
| core.jobdomain | company/core | — | — | `jobdomain.view` (1)<br>`jobdomain.manage` (1) | 2 |
| core.dashboard | company/core | — | dashboard → dashboard<br>personal-home → home | — | 0 |
| core.audit | company/core | — | audit → company-audit | `audit.view` (1) | 1 |
| core.support | company/core | — | — | `support.view` (3)<br>`support.create` (2) | 1 |
| core.theme | company/core | — | — | `theme.view` (0)<br>`theme.manage` (1) | 2 |
| core.members | company/core | — | people → company-people | `members.view` (10)<br>`members.invite` (5)<br>`members.manage` (9)<br>`members.credentials` (2)<br>`members.sensitive_read` (0) | 4 |
| core.workflows | company/core | — | workflows → company-workflows | `automations.view` (4)<br>`automations.manage` (3) | 1 |
| core.documents | company/core | — | settings-documents → company-documents-tab<br>documents → company-documents-tab | `documents.view` (19)<br>`documents.view_confidential` (0)<br>`documents.manage` (26)<br>`documents.configure` (15)<br>`payslips.import` (2)<br>`payslips.review` (10)<br>`payslips.publish` (2)<br>`payslips.download` (2) | 3 |
| core.modules | company/core | — | modules → company-modules | `modules.manage` (3) | 1 |
| core.notifications | company/core | — | — | — | 0 |
| core.billing | company/core | — | billing → company-billing-tab | `billing.manage` (29) | 1 |
| platform.settings | admin/internal | — | settings → platform-settings-tab | `manage_theme_settings` (18)<br>`manage_session_settings` (2)<br>`manage_maintenance` (3) | 3 |
| platform.documentation | admin/platform | — | — | `manage_documentation` (17) | 1 |
| platform.plans | admin/internal | — | — | `manage_plans` (6) | 1 |
| platform.security | admin/platform | — | — | `security.alerts.view` (2)<br>`security.alerts.manage` (3) | 2 |
| platform.activity | admin/platform | platform.audit | — | — | 0 |
| platform.roles | admin/internal | — | — | `manage_roles` (5) | 1 |
| platform.alerts | admin/platform | — | operations → platform-operations-tab | `view_alerts` (3)<br>`manage_alerts` (3) | 2 |
| platform.dashboard | admin/internal | — | dashboard → platform-dashboard-tab | `view_dashboard` (8)<br>`view_system_health` (1)<br>`view_usage_monitoring` (3) | 2 |
| platform.realtime | admin/platform | — | — | `realtime.metrics.view` (2)<br>`realtime.connections.view` (1)<br>`realtime.governance` (2) | 2 |
| platform.audit | admin/internal | — | — | `view_audit_logs` (5) | 1 |
| platform.support | admin/platform | — | communications → platform-communications-tab | `manage_support` (10)<br>`assign_support` (0 ⚠ jamais vérifiée) | 1 |
| platform.markets | admin/internal | — | — | `manage_markets` (22) | 1 |
| platform.ai | admin/platform | — | — | `view_ai` (5)<br>`manage_ai` (7) | 2 |
| platform.users | admin/internal | — | platform-access → platform-access-tab | `manage_platform_users` (5)<br>`manage_platform_user_credentials` (2) | 1 |
| platform.jobdomains | admin/internal | — | organization-presets → platform-organization-presets<br>contract-templates → platform-contract-templates | `manage_jobdomains` (47)<br>`presets.view` (4)<br>`presets.edit` (4)<br>`presets.publish` (2)<br>`presets.preview_company` (2) | 4 |
| platform.fields | admin/internal | — | — | `manage_field_definitions` (6) | 1 |
| platform.automations | admin/platform | — | — | `manage_automations` (4)<br>`run_sensitive_automations` (0) | 1 |
| platform.documents | admin/internal | — | — | `manage_document_catalog` (7) | 1 |
| platform.audience | admin/internal | — | — | `manage_audience` (0 ⚠ jamais vérifiée) | 1 |
| platform.modules | admin/internal | — | catalog → platform-catalog-tab | `manage_modules` (5) | 1 |
| platform.translations | admin/internal | — | — | `manage_translations` (13) | 1 |
| platform.notifications | admin/platform | — | — | `manage_notifications` (3) | 1 |
| platform.companies | admin/internal | — | platform-companies → platform-companies | `view_companies` (9)<br>`manage_companies` (14)<br>`view_company_users` (1) | 2 |
| platform.email | admin/platform | — | platform-messaging → platform-messaging-tab | `manage_email` (37) | 1 |
| platform.billing | admin/platform | — | platform-billing → platform-billing-tab | `view_billing` (27)<br>`manage_billing` (49) | 2 |
| workforce_contract | company/addon | workforce, workforce_documents | settings-templates → company-documents-tab | `workforce_contract.view` (3)<br>`workforce_contract.manage_templates` (6)<br>`workforce_contract.generate` (3) | 1 |
| workforce_signature | company/addon | workforce, workforce_documents | settings-signature → company-workforce-settings-tab | `workforce_signature.view` (6)<br>`workforce_signature.request` (2)<br>`workforce_signature.manage` (3)<br>`workforce_signature.view_evidence` (5)<br>`workforce_signature.admin` (2) | 2 |
| workforce_leave | company/addon | workforce | workforce-leave → company-workforce-leave<br>settings-leave-types → company-workforce-leave | `workforce.leave_request` (8)<br>`workforce.leave_approve` (4)<br>`workforce.leave_view_all` (0)<br>`workforce.leave_manage` (3)<br>`workforce.leave_adjust` (0 ⚠ jamais vérifiée) | 3 |
| workforce_documents | company/addon | workforce | — | `workforce_documents.view` (9)<br>`workforce_documents.generate` (3)<br>`workforce_documents.manage_templates` (0 ⚠ jamais vérifiée)<br>`workforce_documents.sign` (0 ⚠ jamais vérifiée) | 2 |
| workforce_payroll | company/addon | workforce, workforce_leave | workforce-payroll → company-workforce-payroll | `workforce.payroll_prepare` (15)<br>`workforce.payroll_validate` (3)<br>`workforce.payroll_export` (9) | 1 |
| workforce_planning | company/addon | workforce | workforce-planning → company-workforce-planning | `workforce.planning_view` (24)<br>`workforce.planning_manage` (34)<br>`workforce.planning_publish` (2)<br>`workforce.coverage_manage` (7) | 2 |
| workforce | company/addon | — | workforce-me → company-workforce-me<br>workforce-people → company-people<br>workforce-time → company-workforce-time<br>workforce-organization → company-workforce-organization-tab<br>workforce-requirements → company-workforce-requirements<br>workforce-settings → company-workforce-settings-tab | `workforce.view` (22)<br>`workforce.manage` (39)<br>`workforce.export` (1)<br>`workforce.import` (3)<br>`workforce.contracts` (3)<br>`workforce.compensation_read` (2)<br>`workforce.compensation_manage` (0)<br>`workforce.sensitive_read` (0)<br>`workforce.time_manage` (9)<br>`workforce.time_approve` (1)<br>`workforce.admin` (0 ⚠ jamais vérifiée)<br>`workforce.timesheet_view` (11)<br>`workforce.timesheet_submit` (1)<br>`workforce.timesheet_approve` (2)<br>`workforce.timesheet_approve_team` (2) | 8 |
| logistics_shipments | company/addon | — | shipments → company-shipments<br>my-deliveries → company-my-deliveries | `shipments.view` (2)<br>`shipments.create` (1)<br>`shipments.manage_status` (2)<br>`shipments.assign` (2)<br>`shipments.view_own` (2)<br>`shipments.manage_fields` (0 ⚠ jamais vérifiée)<br>`shipments.delete` (0 ⚠ jamais vérifiée) | 3 |
| logistics_tracking | company/addon (preview) | logistics_shipments | — | — | 0 |
| logistics_fleet | company/addon (preview) | logistics_shipments | — | — | 0 |
| logistics_analytics | company/addon (preview) | logistics_shipments | — | — | 0 |

## 6. « Activé » n'est pas « configuré et prêt »

| Module | Ce que l'activation fait | Ce qui manque pour être prêt | Assistant existant |
|---|---|---|---|
| workforce | Activation des dépendances, champs requis activés, widgets | Organisation, temps de travail, rôles RH, premier salarié | **Oui** : `/company/workforce/onboarding` (company → organization → roles → leave → worktime → first_employee). L'étape roles est passive |
| workforce_leave | Seed des types de congés (listener) | Règles d'acquisition, valideurs | Étape `leave` de l'assistant Workforce |
| workforce_planning | Rien de spécifique | Modèles, import des presets de planning | Non (import manuel dans le planning) |
| workforce_payroll / contract / signature / documents | Rien de spécifique | Modèles, paramètres, droits | Non |
| logistics_shipments | Rien de spécifique | Droits chauffeur / dispatch | Non |
| logistics_fleet / tracking / analytics | Rien | **Aucune fonctionnalité** : 0 route, 0 menu, 0 permission ; statut `preview` | Sans objet. `logistics_fleet` est pourtant **actif** pour la société 1 |
| core.theme | — | — | Seul module avec un `settingsPanel` déclaré |

- `ModuleManifest::settingsRoute` vaut `null` pour les 51 modules.
- `GET/PUT api/modules/{key}/settings` existe et sert la page `company/modules/[key].vue`, mais un seul panneau est enregistré (`settingsPanelRegistry.js`).
- Les contributions de module (`contributions`, ADR-794) ne sont déclarées que par `workforce` et `workforce_payroll`. Les décisions sont prises dans `/company/workforce/requirements` → `_ModuleProposals`.

**Socle réutilisable pour un assistant commun** (proposé, **non implémenté**) :
- manifest (`bundles` = capacités, `contributions`, `settingsPanels`) ;
- `ModuleActivationEngine::enable` et l'événement `ModuleEnabled` ;
- `_PermissionMatrix` en mode simple, filtré sur le module ;
- `PresetService` pour proposer les rôles et réglages initiaux ;
- `ModuleContributionDecision` pour les choix explicites ;
- `WorkforceOnboardingService` comme modèle d'état d'étapes (`completed` / `deferred`).

Voir TARGET-ARCHITECTURE §3.
