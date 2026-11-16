# ADR-800 — Inventaire frontend

> Généré depuis `typed-router.d.ts` (routes produites par unplugin-vue-router), les `definePage`, les manifests de modules (`ModuleRegistry::definitions()`) et une analyse des littéraux d'API. SHA `c3f468fa`. Exhaustif pour les fichiers de `resources/js/pages` (290) ; les appels d'API construits dynamiquement peuvent manquer (colonne API = littéraux trouvés dans la page et ses imports directs).

Légende des colonnes : **Nav** = entrée de menu déclarée par un module ; **Liens** = références au nom de route (n) / au chemin (p) dans `resources/js`, **php** = cité côté serveur (notifications, e-mails, nav) ; **Garde** = module · permission vérifiés par `guards.js`.

## 1. Pages réelles (98)

| Route | Fichier | Garde (module · permission) | Nav / point d'entrée | Liens n/p/php | API (littéraux) | Statut |
|---|---|---|---|---|---|---|
| `/` | index.vue | — · — | — | 6/0/non | — | TECHNIQUE légitime |
| `/:error(.*)` | [...error].vue | — · — | — | 0/0/oui | — | TECHNIQUE légitime |
| `/account-settings/:tab` | account-settings/[tab].vue | — · — | core.settings:account-settings | 2/1/oui | — | ACTIVE utile |
| `/audience/confirm` | audience/confirm.vue | — · — | — | 0/0/non | — | TECHNIQUE légitime |
| `/audience/unsubscribe` | audience/unsubscribe.vue | — · — | — | 0/0/non | — | TECHNIQUE légitime |
| `/company/403` | company/403.vue | — · — | — | 1/0/non | — | TECHNIQUE légitime |
| `/company/audit` | company/audit/index.vue | — · — | core.audit:audit(audit.view) | 0/1/oui | — | ACTIVE utile |
| `/company/billing` | company/billing/index.vue | core.billing · billing.manage | — | 0/6/non | — | REMPLACÉE : redirection → billing/:tab |
| `/company/billing/:tab` | company/billing/[tab].vue | core.billing · billing.manage | core.billing:billing(billing.manage) | 8/6/oui | /billing/invoices/{}/pdf<br>/billing/subscription/cancel-preview | ACTIVE utile |
| `/company/billing/consolidated-preview` | company/billing/consolidated-preview.vue | core.billing · billing.manage | — | 0/1/non | — | ACTIVE utile |
| `/company/billing/invoices/:id` | company/billing/invoices/[id].vue | core.billing · billing.manage | — | 0/2/non | /billing/invoices/{}/pdf | ACTIVE utile |
| `/company/billing/pay` | company/billing/pay.vue | core.billing · billing.manage | — | 0/3/non | — | ACTIVE utile |
| `/company/dev-lab` | company/dev-lab.vue | workforce · workforce.view | — | 0/0/non | — | HORS PARCOURS : laboratoire design (ADR-694), routable en PROD |
| `/company/documents/:tab` | company/documents/[tab].vue | — · — | core.documents:settings-documents(documents.configure),core.documents:documents,workforce_contract:settings-templates(workforce_contract.view) | 3/11/oui | /dashboard/priorities<br>/company/documents/search<br>/company/members/{}/documents/{}/review<br>/company/members/{}/documents/{}/apply-suggestions<br>/company/members/{}/documents/{}/retry-ai<br>/company/members/{}/documents/{}<br>+13 | ACTIVE utile |
| `/company/members` | company/members/index.vue | core.members · members.view | — | 0/6/oui | — | REDONDANTE : liste historique complète (1 224 l.) doublon de /company/people (ADR-768 « listes en alias ») |
| `/company/members/:id` | company/members/[id].vue | core.members · members.view | — | 0/6/oui | /company/people/resolve | REMPLACÉE : redirection → people/:personRef (ADR-768) |
| `/company/modules` | company/modules/index.vue | core.modules · modules.manage | core.modules:modules(modules.manage) | 1/1/oui | — | ACTIVE utile |
| `/company/modules/:key` | company/modules/[key].vue | core.modules · modules.manage | — | 1/1/non | — | ACTIVE utile |
| `/company/my-deliveries` | company/my-deliveries/index.vue | — · — | logistics_shipments:my-deliveries(shipments.view_own) | 2/1/oui | — | ACTIVE utile |
| `/company/my-deliveries/:id` | company/my-deliveries/[id].vue | logistics_shipments · shipments.view_own | — | 1/1/oui | — | ACTIVE utile |
| `/company/my-space/:tab` | company/my-space/[tab].vue | — · — | core.settings:my-space | 3/1/oui | /profile/documents | ACTIVE utile |
| `/company/notifications` | company/notifications/index.vue | core.notifications · — | — | 2/0/non | — | ACTIVE utile |
| `/company/people` | company/people/index.vue | core.members · ['members.view', 'workforce.view', 'roles.view'] | core.roles:settings-roles(roles.view),core.members:people,workforce:workforce-people(workforce.view) | 3/7/oui | /workforce/onboarding-files | ACTIVE utile |
| `/company/people/:personRef` | company/people/[personRef].vue | core.members · ['members.view', 'workforce.view'] | — | 0/7/oui | /workforce/documents/by-employee/{} | ACTIVE utile |
| `/company/plan` | company/plan.vue | core.billing · billing.manage | — | 5/1/non | — | REMPLACÉE : redirection → billing/plan (ADR-376) |
| `/company/profile/:tab` | company/profile/[tab].vue | core.settings · settings.view | core.settings:company-profile(settings.view) | 1/2/oui | — | ACTIVE utile |
| `/company/roles` | company/roles.vue | core.roles · roles.view | — | 0/1/oui | — | REMPLACÉE : alias : redirigé vers people?tab=roles en standalone, embarqué dans people (ADR-766) |
| `/company/settings` | company/settings/index.vue | — · — | — | 1/0/oui | /workforce/signature/options | ACTIVE utile |
| `/company/shipments` | company/shipments/index.vue | — · — | logistics_shipments:shipments(shipments.view) | 2/0/oui | — | ACTIVE utile |
| `/company/shipments/:id` | company/shipments/[id].vue | logistics_shipments · shipments.view | — | 2/0/oui | — | ACTIVE utile |
| `/company/shipments/create` | company/shipments/create.vue | logistics_shipments · shipments.create | — | 1/0/oui | — | ACTIVE utile |
| `/company/support` | company/support/index.vue | core.support · support.view | — | 6/0/oui | — | ACTIVE utile |
| `/company/support/:id` | company/support/[id].vue | core.support · support.view | — | 1/0/oui | — | ACTIVE utile |
| `/company/workflows` | company/workflows/index.vue | core.workflows · automations.view | core.workflows:workflows(automations.view) | 1/0/oui | — | ACTIVE utile |
| `/company/workflows/:id` | company/workflows/[id].vue | core.workflows · automations.view | — | 1/0/oui | — | ACTIVE utile |
| `/company/workforce` | company/workforce/index.vue | — · — | — | 0/14/oui | — | REMPLACÉE : redirection → /dashboard (ADR-687) |
| `/company/workforce/configuration` | company/workforce/configuration.vue | workforce · workforce.view | — | 0/0/non | — | REMPLACÉE : redirection → settings/worktime (ADR-639) |
| `/company/workforce/contract/templates` | company/workforce/contract/templates.vue | workforce_contract · workforce_contract.view | — | 0/1/non | — | REDONDANTE : doublon de documents/:tab?tab=templates (aucun lien de nav) |
| `/company/workforce/documents` | company/workforce/documents/index.vue | workforce_documents · workforce_documents.view | — | 0/0/oui | /workforce/employees/{} | REDONDANTE : page embarquée dans documents/:tab (onglet RH) ET routable seule |
| `/company/workforce/dsn` | company/workforce/dsn/index.vue | workforce_payroll · workforce.payroll_prepare | — | 1/0/non | — | ACTIVE utile |
| `/company/workforce/dsn/:id` | company/workforce/dsn/[id].vue | workforce_payroll · workforce.payroll_prepare | — | 1/0/non | — | ACTIVE utile |
| `/company/workforce/employees` | company/workforce/employees/index.vue | workforce · workforce.view | — | 2/4/non | — | REDONDANTE : liste historique (562 l.) doublon de /company/people |
| `/company/workforce/employees/:id` | company/workforce/employees/[id].vue | workforce · workforce.view | — | 2/4/non | /workforce/employees/{}/file<br>/workforce/employees/{}/contracts/{}/cancel<br>/workforce/leaves<br>/workforce/employees/{}/leave-balances<br>/workforce/time-entries<br>/workforce/planning/shifts<br>+3 | REMPLACÉE : redirection → people/employee-{id} (ADR-768) |
| `/company/workforce/leave` | company/workforce/leave/index.vue | workforce_leave · workforce.leave_request['workforce.leave_view_all', 'workforce.leave_approve'] | workforce_leave:workforce-leave(workforce.leave_request),workforce_leave:settings-leave-types(workforce.leave_request) | 3/2/oui | /workforce/config/week-start | ACTIVE utile |
| `/company/workforce/me` | company/workforce/me.vue | workforce · — | workforce:workforce-me | 3/1/oui | /workforce/me/shifts<br>/workforce/me/hours<br>/workforce/me/documents<br>/workforce/me/generated-documents/{}/download${signed  | ACTIVE utile |
| `/company/workforce/onboarding` | company/workforce/onboarding.vue | workforce · workforce.manage | — | 0/2/non | /workforce/onboarding<br>/workforce/onboarding/start<br>/workforce/organization/profile<br>/workforce/onboarding/step<br>/workforce/onboarding/defer<br>/workforce/onboarding/finish<br>+4 | ACTIVE utile |
| `/company/workforce/organization/:tab` | company/workforce/organization/[tab].vue | workforce · workforce.view | workforce:workforce-organization(workforce.view) | 1/2/oui | /workforce/organization/profile<br>/workforce/organization/preset/preview<br>/workforce/organization/preset/apply | ACTIVE utile |
| `/company/workforce/payroll` | company/workforce/payroll/index.vue | workforce_payroll · workforce.payroll_prepare | workforce_payroll:workforce-payroll(workforce.payroll_prepare) | 3/1/oui | — | ACTIVE utile |
| `/company/workforce/payroll/:id` | company/workforce/payroll/[id].vue | workforce_payroll · workforce.payroll_prepare | — | 1/0/non | — | ACTIVE utile |
| `/company/workforce/planning` | company/workforce/planning/index.vue | workforce_planning · workforce.planning_view | workforce_planning:workforce-planning(workforce.planning_view) | 3/3/oui | — | ACTIVE utile |
| `/company/workforce/requirements` | company/workforce/requirements/index.vue | workforce · ['settings.manage', 'documents.configure'] | workforce:workforce-requirements | 0/0/oui | /workforce/requirements<br>/workforce/requirements/catalog<br>/workforce/requirements/preview<br>/workforce/requirements/legacy<br>/workforce/requirements/modules<br>/workforce/requirements/modules/{}/preview<br>+1 | ACTIVE utile |
| `/company/workforce/settings/:tab` | company/workforce/settings/[tab].vue | workforce · workforce.view | workforce_signature:settings-signature(workforce_signature.admin),workforce:workforce-settings(workforce.view) | 1/1/oui | /workforce/config/week-start<br>/workforce/settings<br>/workforce/config/{}<br>/workforce/config/{}/{} | REDONDANTE : onglet worktime = 2ᵉ UI des règles de temps de travail (cf. assistant working-time-setup) |
| `/company/workforce/signature` | company/workforce/signature/index.vue | workforce_signature · workforce_signature.view | — | 0/0/non | — | REDONDANTE : page embarquée dans documents/:tab ET routable seule |
| `/company/workforce/time` | company/workforce/time/index.vue | workforce · ['workforce.timesheet_view', 'workforce.planning_view'] | workforce:workforce-time(workforce.timesheet_view) | 4/2/oui | — | ACTIVE utile |
| `/company/workforce/time/:id` | company/workforce/time/[id].vue | workforce · workforce.timesheet_view | — | 1/2/non | — | ACTIVE utile |
| `/dashboard` | dashboard.vue | core.dashboard · — | core.dashboard:dashboard | 5/17/oui | /dashboard/onboarding<br>/dashboard/onboarding/dismiss<br>/dashboard/onboarding/reopen | ACTIVE utile |
| `/forgot-password` | forgot-password.vue | — · — | — | 0/2/non | /forgot-password | TECHNIQUE légitime |
| `/help-center` | help-center/index.vue | — · — | — | 2/2/non | — | TECHNIQUE légitime |
| `/help-center/:topicSlug` | help-center/[topicSlug]/index.vue | — · — | — | 2/2/non | — | TECHNIQUE légitime |
| `/help-center/:topicSlug/:articleSlug` | help-center/[topicSlug]/[articleSlug].vue | — · — | — | 4/2/non | — | TECHNIQUE légitime |
| `/home` | home.vue | — · — | core.dashboard:personal-home | 3/4/oui | — | ACTIVE utile |
| `/invitations` | invitations.vue | — · — | — | 0/0/non | — | TECHNIQUE légitime |
| `/login` | login.vue | — · — | — | 5/14/non | — | TECHNIQUE légitime |
| `/maintenance` | maintenance.vue | — · — | — | 3/1/non | — | TECHNIQUE légitime |
| `/onboarding` | onboarding/index.vue | — · — | — | 1/1/non | — | TECHNIQUE légitime |
| `/platform` | platform/index.vue | platform.dashboard · — | — | 44/35/oui | — | REMPLACÉE : redirection → dashboard/overview |
| `/platform/access/:tab` | platform/access/[tab].vue | platform.users · — | platform.users:platform-access(manage_platform_users) | 2/2/oui | — | ACTIVE utile |
| `/platform/account/:tab` | platform/account/[tab].vue | platform.settings · — | — | 1/0/oui | /platform/me/profile<br>/platform/me/password<br>/platform/2fa/status<br>/platform/2fa/enable<br>/platform/2fa/confirm<br>/platform/2fa<br>+2 | ACTIVE utile |
| `/platform/billing/:tab` | platform/billing/[tab].vue | platform.billing · view_billing | platform.billing:platform-billing(view_billing) | 1/7/oui | /platform/billing/drilldown/receivables<br>/platform/billing/drilldown/revenue<br>/platform/billing/scheduled-debits<br>/platform/billing/coupons<br>/platform/billing/coupons/{}<br>/platform/companies<br>+2 | ACTIVE utile |
| `/platform/billing/consolidated-preview` | platform/billing/consolidated-preview.vue | — · — | — | 0/1/non | — | ACTIVE utile |
| `/platform/billing/invoices/:id` | platform/billing/invoices/[id].vue | platform.billing · view_billing | — | 3/3/oui | — | ACTIVE utile |
| `/platform/catalog/:tab` | platform/catalog/[tab].vue | platform.modules · — | platform.modules:catalog(manage_modules) | 3/0/oui | /platform/documents<br>/platform/documents/sync<br>/platform/documents/{}/archive<br>/platform/documents/{}/restore<br>/platform/presets<br>/platform/presets/{}/{}<br>+5 | ACTIVE utile |
| `/platform/communications/:tab` | platform/communications/[tab].vue | platform.support · — | platform.support:communications(manage_support) | 2/0/oui | — | ACTIVE utile |
| `/platform/companies` | platform/companies/index.vue | platform.companies · — | platform.companies:platform-companies(view_companies) | 2/9/oui | — | ACTIVE utile |
| `/platform/companies/:id` | platform/companies/[id].vue | platform.companies · manage_companies | — | 6/8/oui | — | ACTIVE utile |
| `/platform/contract-templates` | platform/contract-templates/index.vue | platform.jobdomains · manage_jobdomains | platform.jobdomains:contract-templates(manage_jobdomains) | 0/0/oui | /platform/contract-templates${params.toString() <br>/platform/contract-templates/variables<br>/platform/contract-templates/{}/publish<br>/platform/contract-templates/{}/archive<br>/platform/contract-templates/{}/version<br>/platform/contract-templates/{}/legal-status<br>+3 | ACTIVE utile |
| `/platform/dashboard/:tab` | platform/dashboard/[tab].vue | platform.dashboard · — | platform.dashboard:dashboard(view_dashboard) | 1/0/oui | /platform/onboarding/funnel | ACTIVE utile |
| `/platform/documentation/:slug` | platform/documentation/[slug].vue | platform.documentation · manage_documentation | — | 1/0/oui | — | ACTIVE utile |
| `/platform/documents/:id` | platform/documents/[id].vue | platform.documents · — | — | 1/0/oui | /platform/documents/{}<br>/platform/documents/{}/archive<br>/platform/documents/{}/restore | ACTIVE utile |
| `/platform/forgot-password` | platform/forgot-password.vue | — · — | — | 0/1/non | /platform/forgot-password | TECHNIQUE légitime |
| `/platform/jobdomains/:id` | platform/jobdomains/[id].vue | platform.jobdomains · manage_jobdomains | — | 2/0/oui | /platform/jobdomains/{}/profile<br>/platform/presets/meta<br>/platform/organization-presets<br>/platform/jobdomains/{}<br>/platform/jobdomains/{}/preset-recommendations<br>/platform/jobdomains/{}/presets/{}/draft<br>+5 | ACTIVE utile |
| `/platform/login` | platform/login.vue | — · — | — | 0/7/non | — | TECHNIQUE légitime |
| `/platform/markets/:key` | platform/markets/[key].vue | platform.markets · — | — | 1/0/oui | — | ACTIVE utile |
| `/platform/messaging/:tab` | platform/messaging/[tab].vue | platform.email · — | platform.email:platform-messaging(manage_email) | 0/0/oui | /platform/email/inbox/{}<br>/platform/email/templates/configurable<br>/platform/email/templates/configurable/{}<br>/platform/email/templates/configurable/{}/preview<br>/platform/email/templates/configurable/{}/test<br>/platform/email/orchestration<br>+10 | ACTIVE utile |
| `/platform/modules/:key` | platform/modules/[key].vue | platform.modules · manage_modules | — | 2/0/oui | — | ACTIVE utile |
| `/platform/operations/:tab` | platform/operations/[tab].vue | platform.alerts · — | platform.alerts:operations | 1/0/oui | /platform/system/health | ACTIVE utile |
| `/platform/organization-presets` | platform/organization-presets/index.vue | platform.jobdomains · manage_jobdomains | platform.jobdomains:organization-presets(manage_jobdomains) | 0/3/oui | /platform/organization-presets<br>/platform/organization-presets/meta<br>/platform/organization-presets/{}/publish<br>/platform/organization-presets/{}/duplicate<br>/platform/organization-presets/{}/archive | REDONDANTE : 2ᵉ éditeur des presets d'organisation (même table que catalog/presets et onglet Organisation du JobDomain) |
| `/platform/organization-presets/:id` | platform/organization-presets/[id].vue | platform.jobdomains · manage_jobdomains | — | 0/3/oui | /platform/organization-presets/{}<br>/platform/organization-presets/{}/publish<br>/platform/organization-presets/{}/duplicate | REDONDANTE : idem |
| `/platform/plans/:key` | platform/plans/[key].vue | platform.plans · manage_plans | — | 1/0/oui | — | ACTIVE utile |
| `/platform/presentation-lab` | platform/presentation-lab.vue | platform.dashboard · — | — | 0/0/non | — | HORS PARCOURS : harnais de recette visuelle (ADR-748), routable |
| `/platform/reset-password` | platform/reset-password.vue | — · — | — | 0/0/non | /platform/reset-password | TECHNIQUE légitime |
| `/platform/settings/:tab` | platform/settings/[tab].vue | platform.settings · — | platform.settings:settings(manage_theme_settings) | 3/0/oui | /platform/general-settings<br>/platform/feature-flags<br>/platform/feature-flags/{}/toggle<br>/platform/feature-flags/{} | ACTIVE utile |
| `/platform/support/:id` | platform/support/[id].vue | platform.support · — | — | 2/0/oui | — | ACTIVE utile |
| `/platform/users/:id` | platform/users/[id].vue | platform.users · manage_platform_users | — | 1/2/oui | — | ACTIVE utile |
| `/register` | register/index.vue | — · — | — | 0/3/non | /public/validate-coupon<br>/public/plans/estimate-registration | TECHNIQUE légitime |
| `/reset-password` | reset-password.vue | — · — | — | 0/2/non | /reset-password | TECHNIQUE légitime |
| `/sign/:token` | sign/[token].vue | — · — | — | 0/0/oui | /public/signature/{}<br>/public/signature/{}/prepare<br>/public/signature/{}/sign<br>/public/signature/{}/decline | TECHNIQUE légitime |
| `/ui-lab` | ui-lab.vue | — · — | — | 0/0/non | — | HORS PARCOURS : page de référence UI (ADR-741), routable |

## 2. Sous-composants `_*.vue` exposés comme routes (192)

**Constat prouvé (P0-1)** : `vite.config.js` configure `VueRouter({ routesFolder: 'resources/js/pages' })` sans `exclude`. Chaque `_*.vue` devient une route **sans `definePage`**, donc **sans garde module ni permission** dans `guards.js` (seule la connexion est exigée ; une route `/platform/...` sans `meta.platform` passe même par la portée *company*). Recette lecture seule (chauffeur `bob@leezr.test`, non administratif) : `/company/billing/overview` → **403**, mais `/company/billing/_BillingInvoices` **affiche l'écran Factures** ; l'API répond `403 /api/billing/invoices` → **aucune fuite de données prouvée**, l'exposition est celle de l'interface.

| Fichier (route = /chemin/_Nom) | Statut |
|---|---|
| company/billing/_BillingAlerts.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/billing/_BillingCancelDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/billing/_BillingCards.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/billing/_BillingEmpty.vue | S-ORPH : sous-composant jamais importé, mais routable |
| company/billing/_BillingInvoices.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/billing/_BillingNextInvoice.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/billing/_BillingOverview.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/billing/_BillingPaymentMethods.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/billing/_BillingPlan.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/billing/_BillingTimeline.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/dashboard/_OnboardingWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/dashboard/_PlanBadgeWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/dashboard/_QuickActionsBar.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsActivityTimeline.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsCampaigns.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsCompliance.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsOverview.vue | S-ORPH : sous-composant jamais importé, mais routable |
| company/documents/_DocumentsPayslipImport.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsRequests.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsSearch.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsSettings.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsTodo.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/documents/_DocumentsVault.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/modules/_ThemeRoleVisibility.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/notifications/_NotificationPreferences.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_OnboardingInviteDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_OnboardingPanel.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_OnboardingReviewDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_PersonAccess.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_PersonAddDrawer.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_PersonCreateEmployeeDialog.vue | S-ORPH : sous-composant jamais importé, mais routable |
| company/people/_PersonHrLinkDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_PersonIdentity.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_PersonOverview.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/people/_PersonPayslips.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/profile/_CompanyProfileDocuments.vue | S-ORPH : sous-composant jamais importé, mais routable |
| company/profile/_CompanyProfileOverview.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workflows/_WorkflowCreateDrawer.vue | S-ORPH : sous-composant jamais importé, mais routable |
| company/workforce/_WorkforceActionCard.vue | S-ORPH : sous-composant jamais importé, mais routable |
| company/workforce/_WorkforceModuleCard.vue | S-ORPH : sous-composant jamais importé, mais routable |
| company/workforce/_WorkingTimeSetup.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/contract/_ContractEditor.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/documents/_DocumentDetailDrawer.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/documents/_GenerateDocumentDrawer.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/documents/_PayslipActionCards.vue | S-ORPH : sous-composant jamais importé, mais routable |
| company/workforce/employees/_EmployeeAccessCard.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeAccessDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeBulkActionDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeContract.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeDocumentsSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeFields.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeImportDrawer.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeLeaveSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeePlanningSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeProfile.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeSignaturesSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_EmployeeTimeSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/employees/_GenerateContractDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/leave/_LeaveCalendar.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/leave/_LeaveRequestDetailDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/organization/_OrganizationLocations.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/organization/_OrganizationPresetCard.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/payroll/_EmployeePreparationPanel.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/payroll/_PayrollDisclaimer.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/planning/_ActivityDay.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/planning/_MonthView.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/planning/_PlanningCalendar.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/planning/_PostsView.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/requirements/_ModuleProposals.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/settings/_ContractSettingsSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/settings/_SignatureSettingsSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/settings/_WorkforceRulesSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/signature/_SignatureDetailDrawer.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/signature/_SignatureWizard.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/time/_DayDetailPanel.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| company/workforce/time/_TimeCockpit.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| help-center/_ArticleFeedback.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| help-center/_HelpCenterFooter.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| help-center/_HelpCenterHeader.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| help-center/_HelpCenterKnowledgeBase.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| help-center/_HelpCenterQuickActions.vue | S-ORPH : sous-composant jamais importé, mais routable |
| help-center/_HelpCenterSearchResults.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/access/_PermissionDetail.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/access/_PlatformLogsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/access/_RolesTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/access/_UsersTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/account/_AccountGeneral.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/account/_AccountNotifications.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/account/_AccountSecurity.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiAutoActionsWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiConfidenceWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiDetectionsWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiDocsAnalyzedWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiOperationsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiProviderConfigDrawer.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiProvidersTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiRoutingTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiSettingsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/ai/_AiUsageTab.vue | S-ORPH : sous-composant jamais importé, mais routable |
| platform/billing/_ArOutstandingWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingCouponsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingCreditNotesTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingDashboard.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingDunningTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingForensicsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingGovernanceTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingInvoicesTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingLedgerTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingMetricsWidget.vue | S-ORPH : sous-composant jamais importé, mais routable |
| platform/billing/_BillingPaymentsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingReconciliationTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingRecovery.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingScheduledDebitsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingSubscriptionsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingTariffReviewTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_BillingWalletsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_CashflowTrendWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_FailedPayments7dWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_LastInvoicesWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_LastPaymentsWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_LastRefundsWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_MrrWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_PendingDunningWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_RecentListWidgetShell.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_RefundRatioWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_RevenueMtdWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_RevenueTrendWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/billing/_TopFailureReasonsWidget.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/catalog/_CatalogFields.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/catalog/_CatalogPlans.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/catalog/_CatalogPresets.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/catalog/_PresetEditorDialog.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/communications/_CommunicationsDocumentation.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/communications/_CommunicationsSupport.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/companies/_CompaniesTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/companies/_CompanyActivityTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/companies/_CompanyBillingTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/companies/_CompanyBioPanel.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/companies/_CompanyLogsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/companies/_CompanyMembersTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/companies/_MembersTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/dashboard/_DashboardActivity.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/dashboard/_DashboardFunnel.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/dashboard/_DashboardOverview.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/documents/_CatalogDocuments.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailCompose.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailHealthTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailInboxTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailLeftSidebar.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailLogsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailOrchestrationTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailSettingsTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailTemplatesTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/email/_EmailView.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/international/_TabFxRates.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/international/_TabLanguages.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/international/_TabMarkets.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/international/_TabTranslations.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/jobdomains/_CatalogJobdomains.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/jobdomains/_JobdomainAdvanced.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/jobdomains/_JobdomainPresetTab.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/jobdomains/_MarketOverlaySelector.vue | S-ORPH : sous-composant jamais importé, mais routable |
| platform/messaging/_MessagingNotifications.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/modules/_CatalogModules.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/modules/_PaymentModulesSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/modules/_PaymentRulesSection.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/operations/_AlertTimeline.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/operations/_OperationsAi.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/operations/_OperationsAlerts.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/operations/_OperationsAutomations.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/operations/_OperationsHealth.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/operations/_OperationsRealtime.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/operations/_OperationsSecurity.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/operations/_OperationsUsage.vue | S-ORPH : sous-composant jamais importé, mais routable |
| platform/operations/_OperationsUsageConsumption.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/plans/_TariffMigrationWizard.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsBilling.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsFeatureFlags.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsGeneral.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsInternational.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsMaintenance.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsNotifications.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsSession.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsTheme.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| platform/settings/_SettingsTypography.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| register/_RegisterStepAccount.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| register/_RegisterStepAddons.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| register/_RegisterStepCompany.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| register/_RegisterStepIndustry.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| register/_RegisterStepPlan.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| register/_RegisterStepSummary.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |
| shared/_PermissionMatrix.vue | S-SUB : sous-composant importé, aussi exposé comme route (non intentionnel) |

## 3. Routes manuelles (`plugins/1.router/additional-routes.js`)

10 redirections de compatibilité (anciennes URL Platform `/platform/users`, `/platform/roles`, `/platform/supervision/*`, `/platform/company/users`, `/platform/audit` ; Company `/company/my-space`, `/company/documents`, `/account-settings`, `/profile`). Statut : **TECHNIQUE légitime** (liens profonds historiques) ; retrait possible seulement après mesure d'usage (aucun log d'accès frontend disponible).

## 4. Composants non montés (hors pages)

Voir `DUPLICATIONS-AND-DEAD-CODE.md` §3 (18 composants applicatifs jamais importés ; 13 dialogues Vuexy de stock inutilisés, conservés : politique UI).

## 5. Totaux

| Catégorie | Nombre | Nature |
|---|---|---|
| S-SUB  | 179 | exhaustif |
| ACTIVE utile | 60 | exhaustif |
| TECHNIQUE légitime | 19 | exhaustif |
| S-ORPH  | 13 | exhaustif |
| REMPLACÉE  | 8 | exhaustif |
| REDONDANTE  | 8 | exhaustif |
| HORS PARCOURS | 3 | exhaustif |
| **Total fichiers routés** | **290** | exhaustif |
