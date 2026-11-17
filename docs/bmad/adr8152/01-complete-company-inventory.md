# ADR-815.2 — 01. Inventaire exhaustif de l'espace Company

> **Registre de couverture.** Généré depuis le code au commit `6b7cb5ef` : chaque fichier `resources/js/pages/**/*.vue` hors Platform, plus ses composants locaux sur 3 niveaux et ses magasins Pinia. Il est ensuite croisé avec la recette navigateur d'ADR-815 (9 profils, clone isolé) et les parcours d'ADR-815.2 (`03-end-to-end-journeys.md`).

> **Garantie d'exhaustivité.** Le §2 liste **toutes** les pages routées hors Platform. Le §3 liste **tous** les composants de dialogue ou de tiroir. Le §4 donne, pour chaque page, la liste complète de ses composants (fichiers). Une page absente du §2 n'existe pas dans le routeur.

**Comptage** : tabs = onglets détectés (`<VTab value>` et tableaux d'onglets) ; dlg = composants dialogue ou tiroir, puis balises `VDialog` ou `VNavigationDrawer` ; tbl = tableaux ; flt = champs de filtre ou de recherche ; fld = champs de formulaire ; api = points d'API distincts appelés (page, composants, magasins).

## 1. Modules Company (26)

| Module | Type | Dépend de | Permissions | Actif société 1 | Domaine | Remarque |
|---|---|---|---|---|---|---|
| `core.settings` | core | — | 2 | oui | O |  |
| `core.documentation` | core | — | 1 | oui | Q (aide) |  |
| `core.roles` | core | — | 2 | oui | D |  |
| `core.jobdomain` | core | — | 2 | oui | C/M |  |
| `core.dashboard` | core | — | 0 | oui | A | aucune page ; widgets et contributions seulement |
| `core.audit` | core | — | 1 | oui | O |  |
| `core.support` | core | — | 2 | oui | R |  |
| `core.theme` | core | — | 2 | oui | O |  |
| `core.members` | core | — | 5 | oui | B |  |
| `core.workflows` | core | — | 2 | oui | R |  |
| `core.documents` | core | — | 8 | oui | F |  |
| `core.modules` | core | — | 1 | oui | M |  |
| `core.notifications` | core | — | 0 | oui | Q | aucune page ; widgets et contributions seulement |
| `core.billing` | core | — | 1 | oui | N |  |
| `workforce_contract` | addon | workforce, workforce_documents | 3 | absent | J |  |
| `workforce_signature` | addon | workforce, workforce_documents | 5 | absent | F |  |
| `workforce_leave` | addon | workforce | 5 | oui | I |  |
| `workforce_documents` | addon | workforce | 4 | oui | F |  |
| `workforce_payroll` | addon | workforce, workforce_leave | 3 | oui | K |  |
| `workforce_planning` | addon | workforce | 4 | oui | G |  |
| `workforce` | addon | — | 15 | oui | B/C/H |  |
| `logistics_shipments` | addon | — | 7 | oui | L |  |
| `logistics_tracking` | addon | logistics_shipments | 0 | non | L | aucune permission ni page propre : capacité sans interface (voir 04) |
| `logistics_fleet` | addon | logistics_shipments | 0 | oui | L | aucune permission ni page propre : capacité sans interface (voir 04) |
| `logistics_analytics` | addon | logistics_shipments | 0 | non | L | aucune permission ni page propre : capacité sans interface (voir 04) |

## 2. Pages routées (registre)

| # | URL | Domaine | Module (meta) | Permission (meta) | Lignes (avec composants) | Composants | Onglets | Dialogues | Tableaux | Filtres | Champs | API | Liens sortants | Fichier |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `/account-settings/:tab` | P | — | — | 894 | 3 | 1 | 0/3 | 0 | 0 | 8 | 15 | 2 | `pages/account-settings/[tab].vue` |
| 2 | `/company/403` | Q | — | — | 91 | 1 | 0 | 0/0 | 0 | 0 | 0 | 7 | 0 | `pages/company/403.vue` |
| 3 | `/company/audit` | O | core.audit | audit.view | 206 | 1 | 0 | 0/0 | 1 | 1 | 1 | 1 | 0 | `pages/company/audit/index.vue` |
| 4 | `/company/billing/:tab` | N | core.billing | billing.manage | 2513 | 9 | 1 | 1/2 | 1 | 1 | 5 | 29 | 5 | `pages/company/billing/[tab].vue` |
| 5 | `/company/billing/consolidated-preview` | N | core.billing | billing.manage | 319 | 1 | 0 | 0/0 | 1 | 0 | 0 | 20 | 2 | `pages/company/billing/consolidated-preview.vue` |
| 6 | `/company/billing` | N | core.billing | billing.manage | 18 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 1 | `pages/company/billing/index.vue` |
| 7 | `/company/billing/invoices/:id` | N | core.billing | billing.manage | 915 | 2 | 0 | 0/0 | 5 | 0 | 0 | 21 | 2 | `pages/company/billing/invoices/[id].vue` |
| 8 | `/company/billing/pay` | N | core.billing | billing.manage | 1013 | 1 | 0 | 0/0 | 1 | 0 | 6 | 20 | 2 | `pages/company/billing/pay.vue` |
| 9 | `/company/dev-lab` | Q | workforce | workforce.view | 276 | 1 | 8 | 0/0 | 1 | 0 | 4 | 0 | 0 | `pages/company/dev-lab.vue` |
| 10 | `/company/documents/:tab` | F | core.documents | ['documents.view', 'workforce_documents.view', 'workforce_signature.view'] | 8367 | 28 | 15 | 7/14 | 13 | 15 | 70 | 121 | 3 | `pages/company/documents/[tab].vue` |
| 11 | `/company/members/:id` | B | core.members | members.view | 2626 | 10 | 3 | 2/6 | 2 | 0 | 14 | 37 | 2 | `pages/company/members/[id].vue` |
| 12 | `/company/members` | B | core.members | members.view | 15 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 0 | `pages/company/members/index.vue` |
| 13 | `/company/modules/:key` | M | core.modules | modules.manage | 992 | 4 | 3 | 1/0 | 0 | 0 | 3 | 18 | 1 | `pages/company/modules/[key].vue` |
| 14 | `/company/modules` | M | core.modules | modules.manage | 2167 | 1 | 4 | 0/5 | 1 | 3 | 4 | 14 | 3 | `pages/company/modules/index.vue` |
| 15 | `/company/my-deliveries/:id` | L/P | logistics_shipments | shipments.view_own | 246 | 1 | 0 | 0/0 | 0 | 0 | 0 | 3 | 1 | `pages/company/my-deliveries/[id].vue` |
| 16 | `/company/my-deliveries` | L/P | logistics_shipments | shipments.view_own | 184 | 1 | 0 | 0/0 | 1 | 1 | 1 | 3 | 1 | `pages/company/my-deliveries/index.vue` |
| 17 | `/company/my-space/:tab` | P | — | — | 1684 | 7 | 4 | 1/2 | 2 | 1 | 1 | 16 | 3 | `pages/company/my-space/[tab].vue` |
| 18 | `/company/notifications` | Q | core.notifications | — | 534 | 2 | 2 | 0/0 | 0 | 0 | 3 | 1 | 0 | `pages/company/notifications/index.vue` |
| 19 | `/company/people/:personRef` | B | core.members | ['members.view', 'workforce.view'] | 6765 | 27 | 5 | 7/13 | 7 | 0 | 55 | 99 | 5 | `pages/company/people/[personRef].vue` |
| 20 | `/company/people` | B | core.members | ['members.view', 'workforce.view', 'roles.view'] | 5551 | 17 | 14 | 5/7 | 7 | 9 | 64 | 68 | 3 | `pages/company/people/index.vue` |
| 21 | `/company/plan` | N | core.billing | billing.manage | 14 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 1 | `pages/company/plan.vue` |
| 22 | `/company/profile/:tab` | O | core.settings | settings.view | 622 | 2 | 1 | 0/1 | 0 | 0 | 16 | 16 | 2 | `pages/company/profile/[tab].vue` |
| 23 | `/company/roles` | D | core.roles | roles.view | 1500 | 2 | 4 | 0/1 | 2 | 0 | 11 | 25 | 1 | `pages/company/roles.vue` |
| 24 | `/company/settings` | O | — | — | 285 | 1 | 1 | 0/0 | 0 | 1 | 1 | 11 | 0 | `pages/company/settings/index.vue` |
| 25 | `/company/shipments/:id` | L | logistics_shipments | shipments.view | 370 | 1 | 0 | 0/0 | 0 | 0 | 1 | 19 | 1 | `pages/company/shipments/[id].vue` |
| 26 | `/company/shipments/create` | L | logistics_shipments | shipments.create | 135 | 1 | 0 | 0/0 | 0 | 0 | 4 | 4 | 2 | `pages/company/shipments/create.vue` |
| 27 | `/company/shipments` | L | logistics_shipments | shipments.view | 252 | 1 | 0 | 0/0 | 1 | 2 | 2 | 11 | 3 | `pages/company/shipments/index.vue` |
| 28 | `/company/support/:id` | R | core.support | support.view | 257 | 1 | 0 | 0/0 | 0 | 0 | 1 | 10 | 1 | `pages/company/support/[id].vue` |
| 29 | `/company/support` | R | core.support | support.view | 245 | 1 | 0 | 0/1 | 1 | 1 | 4 | 3 | 1 | `pages/company/support/index.vue` |
| 30 | `/company/workflows/:id` | R | core.workflows | automations.view | 521 | 1 | 0 | 0/2 | 0 | 0 | 5 | 5 | 1 | `pages/company/workflows/[id].vue` |
| 31 | `/company/workflows` | R | core.workflows | automations.view | 214 | 1 | 0 | 0/1 | 1 | 0 | 1 | 5 | 1 | `pages/company/workflows/index.vue` |
| 32 | `/company/workforce/configuration` | O | workforce | workforce.view | 20 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 0 | `pages/company/workforce/configuration.vue` |
| 33 | `/company/workforce/contract/templates` | F/J | workforce_contract | workforce_contract.view | 13 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 0 | `pages/company/workforce/contract/templates.vue` |
| 34 | `/company/workforce/documents` | F | workforce_documents | workforce_documents.view | 19 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 0 | `pages/company/workforce/documents/index.vue` |
| 35 | `/company/workforce/dsn/:id` | K | workforce_payroll | workforce.payroll_prepare | 361 | 1 | 6 | 0/0 | 0 | 0 | 0 | 9 | 1 | `pages/company/workforce/dsn/[id].vue` |
| 36 | `/company/workforce/dsn` | K | workforce_payroll | workforce.payroll_prepare | 615 | 2 | 0 | 0/1 | 1 | 2 | 3 | 10 | 1 | `pages/company/workforce/dsn/index.vue` |
| 37 | `/company/workforce/employees/:id` | B | workforce | workforce.view | 2342 | 11 | 0 | 2/5 | 4 | 0 | 23 | 59 | 4 | `pages/company/workforce/employees/[id].vue` |
| 38 | `/company/workforce/employees` | B | workforce | workforce.view | 20 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 0 | `pages/company/workforce/employees/index.vue` |
| 39 | `/company/workforce` | A | workforce | workforce.view | 16 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 0 | `pages/company/workforce/index.vue` |
| 40 | `/company/workforce/leave` | I | workforce_leave | workforce.leave_request | 2123 | 5 | 9 | 1/5 | 3 | 3 | 27 | 39 | 4 | `pages/company/workforce/leave/index.vue` |
| 41 | `/company/workforce/me` | P | workforce | — | 1013 | 2 | 13 | 0/1 | 0 | 0 | 5 | 19 | 6 | `pages/company/workforce/me.vue` |
| 42 | `/company/workforce/onboarding` | M | workforce | workforce.manage | 950 | 3 | 1 | 0/1 | 0 | 0 | 9 | 10 | 0 | `pages/company/workforce/onboarding.vue` |
| 43 | `/company/workforce/organization/:tab` | C | workforce | workforce.view | 1402 | 3 | 3 | 0/5 | 3 | 0 | 15 | 80 | 0 | `pages/company/workforce/organization/[tab].vue` |
| 44 | `/company/workforce/payroll/:id` | K | workforce_payroll | workforce.payroll_prepare | 696 | 3 | 4 | 0/3 | 2 | 0 | 1 | 15 | 4 | `pages/company/workforce/payroll/[id].vue` |
| 45 | `/company/workforce/payroll` | K | workforce_payroll | workforce.payroll_prepare | 1277 | 4 | 2 | 0/3 | 2 | 7 | 8 | 24 | 2 | `pages/company/workforce/payroll/index.vue` |
| 46 | `/company/workforce/planning` | G | workforce_planning | workforce.planning_view | 8565 | 7 | 11 | 0/28 | 3 | 6 | 92 | 78 | 4 | `pages/company/workforce/planning/index.vue` |
| 47 | `/company/workforce/requirements` | E/F | workforce | ['settings.manage', 'documents.configure'] | 725 | 2 | 0 | 0/0 | 0 | 1 | 2 | 7 | 0 | `pages/company/workforce/requirements/index.vue` |
| 48 | `/company/workforce/settings/:tab` | O/H/I | workforce | workforce.view | 1144 | 5 | 2 | 0/0 | 1 | 0 | 17 | 33 | 1 | `pages/company/workforce/settings/[tab].vue` |
| 49 | `/company/workforce/signature` | F | workforce_signature | workforce_signature.view | 14 | 1 | 0 | 0/0 | 0 | 0 | 0 | 0 | 0 | `pages/company/workforce/signature/index.vue` |
| 50 | `/company/workforce/time/:id` | H | workforce | workforce.timesheet_view | 511 | 1 | 4 | 0/0 | 1 | 0 | 0 | 23 | 1 | `pages/company/workforce/time/[id].vue` |
| 51 | `/company/workforce/time` | H | workforce | ['workforce.timesheet_view', 'workforce.planning_view'] | 2374 | 5 | 19 | 0/7 | 3 | 8 | 29 | 101 | 6 | `pages/company/workforce/time/index.vue` |
| 52 | `/dashboard` | A | core.dashboard | — | 1288 | 6 | 0 | 0/1 | 0 | 0 | 0 | 21 | 3 | `pages/dashboard.vue` |
| 53 | `/home` | A | — | — | 837 | 3 | 4 | 0/1 | 0 | 0 | 0 | 19 | 2 | `pages/home.vue` |
| 54 | `/invitations` | B/D | — | — | 202 | 2 | 0 | 0/0 | 0 | 0 | 0 | 9 | 0 | `pages/invitations.vue` |
| 55 | `/onboarding` | E | — | — | 407 | 1 | 0 | 0/0 | 0 | 0 | 1 | 0 | 0 | `pages/onboarding/index.vue` |
| 56 | `/sign/:token` | F | — | — | 278 | 1 | 0 | 0/0 | 0 | 0 | 3 | 4 | 0 | `pages/sign/[token].vue` |
| 57 | `/ui-lab` | Q | — | — | 141 | 1 | 7 | 0/0 | 0 | 1 | 0 | 0 | 5 | `pages/ui-lab.vue` |

Pages publiques hors espace Company, non détaillées mais conservées : `/`, `/login`, `/register` (parcours 1), `/forgot-password`, `/reset-password`, `/maintenance`, `/help-center/**`, `/audience/*`, `/:error`.

## 3. Dialogues, tiroirs et assistants (registre)

| Composant | Page(s) hôte(s) |
|---|---|
| `CreateDocumentTypeDrawer` | /company/documents/:tab, /company/members/:id, /company/people/:personRef |
| `DocumentDepositDialog` | /company/documents/:tab, /company/people/:personRef |
| `DocumentViewerDialog` | /company/documents/:tab, /company/members/:id, /company/my-space/:tab, /company/people/:personRef |
| `_BillingCancelDialog` | /company/billing/:tab |
| `_DocumentDetailDrawer` | /company/documents/:tab |
| `_EmployeeAccessDialog` | /company/people/:personRef, /company/workforce/employees/:id |
| `_EmployeeBulkActionDialog` | /company/people |
| `_EmployeeImportDrawer` | /company/people |
| `_GenerateContractDialog` | /company/people/:personRef, /company/workforce/employees/:id |
| `_GenerateDocumentDrawer` | /company/documents/:tab |
| `_LeaveRequestDetailDialog` | /company/workforce/leave |
| `_ModuleSetupWizard` | /company/modules/:key |
| `_OnboardingInviteDialog` | /company/people |
| `_OnboardingReviewDialog` | /company/people |
| `_PersonAddDrawer` | /company/people |
| `_PersonHrLinkDialog` | /company/people/:personRef |
| `_SignatureDetailDrawer` | /company/documents/:tab |
| `_SignatureWizard` | /company/documents/:tab, /company/people/:personRef |

## 4. Détail par page : onglets, dialogues, API, liens, états vides

### `/account-settings/:tab`
- **Fichiers** (3) : `pages/account-settings/[tab].vue`, `views/pages/account-settings/AccountSettingsAccount.vue`, `views/pages/account-settings/AccountSettingsSecurity.vue`
- **Magasins** : auth.js
- **Onglets détectés** : item.tab
- **API** (15) : `/2fa`, `/2fa/backup-codes`, `/2fa/confirm`, `/2fa/enable`, `/2fa/status`, `/2fa/verify`, `/login`, `/logout`, `/me`, `/my-companies`, `/profile`, `/profile/avatar`, `/profile/password`, `/register`, `/register/confirm-payment`
- **Navigation sortante** : account-settings-tab, company-my-space-tab
- **Gestion d'erreur** : 6 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/403`
- **Fichiers** (1) : `pages/company/403.vue`
- **Magasins** : auth.js, useCompanyNav.js
- **API** (7) : `/2fa/verify`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/audit`
- **Fichiers** (1) : `pages/company/audit/index.vue`
- **Magasins** : audit.store.js
- **API** (1) : `/audit`
- **États vides** : « Aucun journal d'audit trouvé »
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/billing/:tab`
- **Fichiers** (9) : `pages/company/billing/[tab].vue`, `pages/company/billing/_BillingAlerts.vue`, `pages/company/billing/_BillingCancelDialog.vue`, `pages/company/billing/_BillingCards.vue`, `pages/company/billing/_BillingInvoices.vue`, `pages/company/billing/_BillingNextInvoice.vue`, `pages/company/billing/_BillingOverview.vue`, `pages/company/billing/_BillingPaymentMethods.vue`, `pages/company/billing/_BillingTimeline.vue`
- **Magasins** : auth.js, billing.store.js, useAnalytics.js
- **Onglets détectés** : item.tab
- **Dialogues** : _BillingCancelDialog
- **API** (29) : `/2fa/verify`, `/billing/confirm-setup-intent`, `/billing/consolidated-preview`, `/billing/invoices`, `/billing/invoices/${id}`, `/billing/invoices/${id}/retry`, `/billing/invoices/${invoice.id}/pdf`, `/billing/invoices/outstanding`, `/billing/invoices/pay`, `/billing/invoices/pay/confirm`, `/billing/next-invoice-preview`, `/billing/overview`, `/billing/pending-subscription`, `/billing/plan-change`, `/billing/plan-change-preview`, `/billing/saved-cards`, `/billing/saved-cards/${id}`, `/billing/saved-cards/${id}/default`, `/billing/saved-cards/${profileId}/debit-day`, `/billing/setup-intent`, `/billing/subscription`, `/billing/subscription/cancel`, `/billing/subscription/cancel-preview`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`
- **Navigation sortante** : /company/billing/pay, company-billing-tab, company-plan, company-support, dashboard
- **États vides** : « Aucun abonnement actif. » · « Aucun moyen de paiement disponible pour votre abonnement et marché. » · « Aucun moyen de paiement enregistré. » · « Aucune activité » · « Aucune facture pour le moment. » · « L'historique de votre facturation apparaîtra ici. »
- **Gestion d'erreur** : 5 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/billing/consolidated-preview`
- **Fichiers** (1) : `pages/company/billing/consolidated-preview.vue`
- **Magasins** : billing.store.js
- **API** (20) : `/billing/confirm-setup-intent`, `/billing/consolidated-preview`, `/billing/invoices`, `/billing/invoices/${id}`, `/billing/invoices/${id}/retry`, `/billing/invoices/outstanding`, `/billing/invoices/pay`, `/billing/invoices/pay/confirm`, `/billing/next-invoice-preview`, `/billing/overview`, `/billing/pending-subscription`, `/billing/plan-change`, `/billing/plan-change-preview`, `/billing/saved-cards`, `/billing/saved-cards/${id}`, `/billing/saved-cards/${id}/default`, `/billing/saved-cards/${profileId}/debit-day`, `/billing/setup-intent`, `/billing/subscription`, `/billing/subscription/cancel`
- **Navigation sortante** : /company/billing/invoices, /company/billing/invoices/
- **États vides** : « Aucune facture existante à agréger. »
- **Gestion d'erreur** : 1 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/billing`
- **Fichiers** (1) : `pages/company/billing/index.vue`
- **Navigation sortante** : company-billing-tab
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/billing/invoices/:id`
- **Fichiers** (2) : `components/BrandLogo.vue`, `pages/company/billing/invoices/[id].vue`
- **Magasins** : billing.store.js, useAppName.js
- **API** (21) : `/billing/confirm-setup-intent`, `/billing/consolidated-preview`, `/billing/invoices`, `/billing/invoices/${id}`, `/billing/invoices/${id}/retry`, `/billing/invoices/${invoice.value.id}/pdf`, `/billing/invoices/outstanding`, `/billing/invoices/pay`, `/billing/invoices/pay/confirm`, `/billing/next-invoice-preview`, `/billing/overview`, `/billing/pending-subscription`, `/billing/plan-change`, `/billing/plan-change-preview`, `/billing/saved-cards`, `/billing/saved-cards/${id}`, `/billing/saved-cards/${id}/default`, `/billing/saved-cards/${profileId}/debit-day`, `/billing/setup-intent`, `/billing/subscription`, `/billing/subscription/cancel`
- **Navigation sortante** : /company/billing/pay, company-billing-tab
- **États vides** : « Aucun avoir. » · « Aucun paiement enregistré. »
- **Gestion d'erreur** : 2 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/billing/pay`
- **Fichiers** (1) : `pages/company/billing/pay.vue`
- **Magasins** : billing.store.js, useAnalytics.js
- **API** (20) : `/billing/confirm-setup-intent`, `/billing/consolidated-preview`, `/billing/invoices`, `/billing/invoices/${id}`, `/billing/invoices/${id}/retry`, `/billing/invoices/outstanding`, `/billing/invoices/pay`, `/billing/invoices/pay/confirm`, `/billing/next-invoice-preview`, `/billing/overview`, `/billing/pending-subscription`, `/billing/plan-change`, `/billing/plan-change-preview`, `/billing/saved-cards`, `/billing/saved-cards/${id}`, `/billing/saved-cards/${id}/default`, `/billing/saved-cards/${profileId}/debit-day`, `/billing/setup-intent`, `/billing/subscription`, `/billing/subscription/cancel`
- **Navigation sortante** : /company/billing/overview, company-support
- **États vides** : « Aucune facture impayée. »
- **Gestion d'erreur** : 3 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/dev-lab`
- **Fichiers** (1) : `pages/company/dev-lab.vue`
- **Magasins** : useAppToast.js
- **Onglets détectés** : contract, documents, history, leave, overview, planning, profile, time
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/documents/:tab`
- **Fichiers** (28) : `company/views/CreateDocumentTypeDrawer.vue`, `company/views/DocumentDepositDialog.vue`, `components/workforce/DocumentTemplatePicker.vue`, `components/workforce/EmployeePicker.vue`, `pages/company/documents/[tab].vue`, `pages/company/documents/_DocumentsCampaigns.vue`, `pages/company/documents/_DocumentsCompliance.vue`, `pages/company/documents/_DocumentsPayslipImport.vue`, `pages/company/documents/_DocumentsRequests.vue`, `pages/company/documents/_DocumentsSearch.vue`, `pages/company/documents/_DocumentsSettings.vue`, `pages/company/documents/_DocumentsTodo.vue`, `pages/company/documents/_DocumentsVault.vue`, `pages/company/workforce/contract/_ContractEditor.vue`, `pages/company/workforce/contract/_ContractTemplatesView.vue`, `pages/company/workforce/documents/_DocumentDetailDrawer.vue`, `pages/company/workforce/documents/_GenerateDocumentDrawer.vue`, `pages/company/workforce/documents/_HrDocumentsView.vue`, `pages/company/workforce/signature/_SignatureDetailDrawer.vue`, `pages/company/workforce/signature/_SignatureWizard.vue`, `pages/company/workforce/signature/_SignaturesView.vue`, `views/pages/company-settings/CompanyDocumentActivationCatalog.vue`, `views/pages/company-settings/CompanyDocumentsVault.vue`, `views/shared/documents/DocumentAiChip.vue`, `views/shared/documents/DocumentConstraintsInline.vue`, `views/shared/documents/DocumentMandatoryChip.vue`, `views/shared/documents/DocumentScopeChip.vue`, `views/shared/documents/DocumentViewerDialog.vue`
- **Magasins** : auth.js, contract.store.js, documents.store.js, employees.store.js, members.store.js, module.js, settings.store.js, signature.store.js, useAppToast.js, useCan.js, useCompanyPermissionContext.js, useConfirm.js, useDocumentHelpers.js, workforce-documents.store.js
- **Onglets détectés** : campaign, completed, declined, deposit, documents, expired, item.tab, partially_signed, payslips, pending, request, sign, signatures, tab.key, templates
- **Dialogues** : CreateDocumentTypeDrawer, DocumentDepositDialog, DocumentViewerDialog, _DocumentDetailDrawer, _GenerateDocumentDrawer, _SignatureDetailDrawer, _SignatureWizard
- **API** (121) : `/2fa/verify`, `/company`, `/company/ai-quota/documents`, `/company/document-activations`, `/company/document-activations/${u.code}`, `/company/document-campaigns`, `/company/document-campaigns/${id}`, `/company/document-campaigns/preview`, `/company/document-requests/${requestId}/cancel`, `/company/document-requests/${requestId}/remind`, `/company/document-requests/batch`, `/company/document-requests/bulk-action`, `/company/document-requests/roles`, `/company/document-settings`, `/company/document-types/custom`, `/company/document-types/custom/${code}`, `/company/document-types/custom/${code}/archive`, `/company/documents`, `/company/documents/${code}`, `/company/documents/${code}/download`, `/company/documents/${doc.code}/review`, `/company/documents/activity`, `/company/documents/compliance`, `/company/documents/search`, `/company/field-activations`, `/company/field-definitions`, `/company/field-definitions/${id}`, `/company/legal-structure`, `/company/members`, `/company/members/${id}`, `/company/members/${id}/fields${params}`, `/company/members/${id}/password`, `/company/members/${id}/reset-password`, `/company/members/${membershipId}/documents/${code}`, `/company/members/${membershipId}/documents/${request.document_type.code}/review`, `/company/members/${r.user.membership_id}/documents/${r.document_type.code}/apply-suggestions`, `/company/members/${r.user.membership_id}/documents/${r.document_type.code}/retry-ai`, `/company/payslip-imports`, `/company/payslip-imports/${activeBatch.value.id}`, `/company/payslip-imports/${activeBatch.value.id}/items`
- **Navigation sortante** : /company/workforce/employees/, company-documents-tab, company-workforce-payroll
- **États vides** : « Activez des types de documents pour voir les statistiques de conformité. » · « Aucun cachet cryptographique (document antérieur au cachet natif). » · « Aucun document d'entreprise » · « Aucun document entreprise disponible. Utilisez « Gérer les types » pour activer des types de documents. » · « Aucun document généré » · « Aucun document ne correspond, parmi ceux que vous pouvez consulter. »
- **Gestion d'erreur** : 36 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/members/:id`
- **Fichiers** (10) : `company/components/MemberProfileForm.vue`, `company/views/CreateDocumentTypeDrawer.vue`, `pages/company/members/[id].vue`, `views/pages/company-members/MemberDocumentsWorkflowPanel.vue`, `views/shared/documents/DocumentAiChip.vue`, `views/shared/documents/DocumentConstraintsInline.vue`, `views/shared/documents/DocumentMandatoryChip.vue`, `views/shared/documents/DocumentStateChip.vue`, `views/shared/documents/DocumentVersionsTimeline.vue`, `views/shared/documents/DocumentViewerDialog.vue`
- **Magasins** : auth.js, documents.store.js, members.store.js, settings.store.js, useAppToast.js, useConfirm.js, useDocumentHelpers.js
- **Onglets détectés** : credentials, documents, overview
- **Dialogues** : CreateDocumentTypeDrawer, DocumentViewerDialog
- **API** (37) : `${base.value}/documents`, `${base.value}/documents/${code}`, `${base.value}/documents/${code}/download`, `${base.value}/documents/${code}/review`, `${base.value}/documents/${doc.code}/apply-suggestions`, `/2fa/verify`, `/company`, `/company/document-activations`, `/company/document-requests`, `/company/document-requests/${requestId}/cancel`, `/company/document-requests/${requestId}/remind`, `/company/document-requests/bulk-action`, `/company/document-settings`, `/company/document-types/custom`, `/company/document-types/custom/${code}`, `/company/document-types/custom/${code}/archive`, `/company/documents`, `/company/documents/activity`, `/company/documents/compliance`, `/company/field-activations`, `/company/field-definitions`, `/company/field-definitions/${id}`, `/company/legal-structure`, `/company/members`, `/company/members/${id}`, `/company/members/${id}/fields${params}`, `/company/members/${id}/password`, `/company/members/${id}/reset-password`, `/company/people/resolve`, `/company/roles`, `/company/roles/${id}`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`
- **Navigation sortante** : /company/people, company-people
- **États vides** : « Aucun document requis » · « Aucun rôle » · « Aucun type de document n'est activé pour ce membre. » · « Aucune version précédente. »
- **Gestion d'erreur** : 9 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/members`
- **Fichiers** (1) : `pages/company/members/index.vue`
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/modules/:key`
- **Fichiers** (4) : `components/modules/CapabilityChangePreview.vue`, `components/modules/ModuleCapabilityAccess.vue`, `pages/company/modules/[key].vue`, `pages/company/modules/_ModuleSetupWizard.vue`
- **Magasins** : auth.js, module.js, useAppToast.js
- **Onglets détectés** : collective, own, sensitive
- **Dialogues** : _ModuleSetupWizard
- **API** (18) : `/2fa/verify`, `/login`, `/logout`, `/me`, `/modules`, `/modules/${key}/deactivation-preview`, `/modules/${key}/disable`, `/modules/${key}/enable`, `/modules/${key}/settings`, `/modules/${props.moduleKey}/setup`, `/modules/${props.moduleKey}/setup/access`, `/modules/${props.moduleKey}/setup/access/preview`, `/modules/${props.moduleKey}/setup/confirm`, `/modules/${props.moduleKey}/setup/steps`, `/modules/quote`, `/my-companies`, `/register`, `/register/confirm-payment`
- **Navigation sortante** : company-modules
- **États vides** : « Aucun changement : les accès actuels sont conservés. » · « Aucune proposition disponible pour votre activité : choisissez vous-même. » · « Ce module n'a aucun paramètre configurable. »
- **Gestion d'erreur** : 6 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/modules`
- **Fichiers** (1) : `pages/company/modules/index.vue`
- **Magasins** : auth.js, module.js, useAppToast.js
- **Onglets détectés** : 0, 1, 2, 3
- **API** (14) : `/2fa/verify`, `/login`, `/logout`, `/me`, `/modules`, `/modules/${key}/deactivation-preview`, `/modules/${key}/disable`, `/modules/${key}/enable`, `/modules/${key}/settings`, `/modules/${module.key}/deactivation-impact`, `/modules/quote`, `/my-companies`, `/register`, `/register/confirm-payment`
- **Navigation sortante** : /company/modules/, company-modules-key, company-plan
- **États vides** : « Aucun module dans cette section. » · « Aucun module disponible. » · « Aucun module ne correspond à votre recherche. » · « Aucune donnée n'est supprimée par la désactivation. »
- **Gestion d'erreur** : 3 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/my-deliveries/:id`
- **Fichiers** (1) : `pages/company/my-deliveries/[id].vue`
- **Magasins** : delivery.store.js
- **API** (3) : `/my-deliveries`, `/my-deliveries/${id}`, `/my-deliveries/${id}/status`
- **Navigation sortante** : company-my-deliveries
- **États vides** : « Aucune action disponible. »
- **Gestion d'erreur** : 1 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/my-deliveries`
- **Fichiers** (1) : `pages/company/my-deliveries/index.vue`
- **Magasins** : delivery.store.js
- **API** (3) : `/my-deliveries`, `/my-deliveries/${id}`, `/my-deliveries/${id}/status`
- **Navigation sortante** : company-my-deliveries-id
- **États vides** : « Aucune livraison ne vous est assignée. »
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/my-space/:tab`
- **Fichiers** (7) : `pages/company/my-space/[tab].vue`, `views/pages/account-settings/AccountSettingsDocuments.vue`, `views/pages/my-space/MyPayslips.vue`, `views/pages/my-space/MySignatures.vue`, `views/shared/documents/DocumentStateChip.vue`, `views/shared/documents/DocumentVersionsTimeline.vue`, `views/shared/documents/DocumentViewerDialog.vue`
- **Magasins** : auth.js, personal-space.store.js, useAppToast.js, useDocumentHelpers.js
- **Onglets détectés** : documents, overview, payslips, signatures
- **Dialogues** : DocumentViewerDialog
- **API** (16) : `/2fa/verify`, `/login`, `/logout`, `/me`, `/my-companies`, `/profile/documents`, `/profile/documents/${code}`, `/profile/documents/${code}/download`, `/profile/payslips`, `/profile/payslips/${p.id}/download`, `/profile/space`, `/register`, `/register/confirm-payment`, `/workforce/signature/me`, `/workforce/signature/me/${row.id}/download`, `/workforce/signature/me/${row.id}/open`
- **Navigation sortante** : account-settings-tab, company-my-space-tab, company-workforce-me
- **États vides** : « Aucun bulletin disponible » · « Aucun document ne vous est demandé » · « Aucune fiche salarié n'est rattachée à votre compte dans cette entreprise : planning, pointage et congés ne sont pas proposés. » · « Aucune signature » · « Aucune version précédente. » · « Les documents demandés par votre entreprise apparaîtront ici. »
- **Gestion d'erreur** : 2 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/notifications`
- **Fichiers** (2) : `pages/company/notifications/_NotificationPreferences.vue`, `pages/company/notifications/index.vue`
- **Magasins** : notification.js, useAppToast.js
- **Onglets détectés** : inbox, preferences
- **API** (1) : `/notifications${path}`
- **États vides** : « Aucune notification. »
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/people/:personRef`
- **Fichiers** (27) : `company/components/MemberProfileForm.vue`, `company/views/CreateDocumentTypeDrawer.vue`, `company/views/DocumentDepositDialog.vue`, `pages/company/people/[personRef].vue`, `pages/company/people/_PersonAccess.vue`, `pages/company/people/_PersonHrLinkDialog.vue`, `pages/company/people/_PersonIdentity.vue`, `pages/company/people/_PersonOverview.vue`, `pages/company/people/_PersonPayslips.vue`, `pages/company/workforce/employees/_EmployeeAccessDialog.vue`, `pages/company/workforce/employees/_EmployeeContract.vue`, `pages/company/workforce/employees/_EmployeeDocumentsSection.vue`, `pages/company/workforce/employees/_EmployeeFields.vue`, `pages/company/workforce/employees/_EmployeeLeaveSection.vue`, `pages/company/workforce/employees/_EmployeePlanningSection.vue`, `pages/company/workforce/employees/_EmployeeProfile.vue`, `pages/company/workforce/employees/_EmployeeSignaturesSection.vue`, `pages/company/workforce/employees/_EmployeeTimeSection.vue`, `pages/company/workforce/employees/_GenerateContractDialog.vue`, `pages/company/workforce/signature/_SignatureWizard.vue`, `views/pages/company-members/MemberDocumentsWorkflowPanel.vue`, `views/shared/documents/DocumentAiChip.vue`, `views/shared/documents/DocumentConstraintsInline.vue`, `views/shared/documents/DocumentMandatoryChip.vue`, `views/shared/documents/DocumentStateChip.vue`, `views/shared/documents/DocumentVersionsTimeline.vue`, `views/shared/documents/DocumentViewerDialog.vue`
- **Magasins** : contract.store.js, documents.store.js, employees.store.js, members.store.js, person.store.js, settings.store.js, signature.store.js, useAppToast.js, useCan.js, useConfirm.js, useDocumentHelpers.js
- **Onglets détectés** : access, documents, overview, profile, time
- **Dialogues** : CreateDocumentTypeDrawer, DocumentDepositDialog, DocumentViewerDialog, _EmployeeAccessDialog, _GenerateContractDialog, _PersonHrLinkDialog, _SignatureWizard
- **API** (99) : `${base.value}/documents`, `${base.value}/documents/${code}`, `${base.value}/documents/${code}/download`, `${base.value}/documents/${code}/review`, `${base.value}/documents/${doc.code}/apply-suggestions`, `/company`, `/company/document-activations`, `/company/document-requests`, `/company/document-requests/${requestId}/cancel`, `/company/document-requests/${requestId}/remind`, `/company/document-requests/bulk-action`, `/company/document-settings`, `/company/document-types/custom`, `/company/document-types/custom/${code}`, `/company/document-types/custom/${code}/archive`, `/company/documents`, `/company/documents/activity`, `/company/documents/compliance`, `/company/field-activations`, `/company/field-definitions`, `/company/field-definitions/${id}`, `/company/legal-structure`, `/company/members`, `/company/members/${id}`, `/company/members/${id}/fields${params}`, `/company/members/${id}/password`, `/company/members/${id}/reset-password`, `/company/members/${mid}`, `/company/people`, `/company/people/${encodeURIComponent(ref)}`, `/company/people/${encodeURIComponent(ref)}/history`, `/company/people/${encodeURIComponent(ref)}/payslips`, `/company/people/${encodeURIComponent(ref)}/payslips/${id}/download`, `/company/roles`, `/company/roles/${id}`, `/workforce/contract/generate`, `/workforce/contract/preview`, `/workforce/contract/preview-pdf`, `/workforce/contract/templates${qs}`, `/workforce/contract/templates/${id}`
- **Navigation sortante** : /company/documents/hr, /company/workforce/leave, /company/workforce/planning, /company/workforce/time, company-people
- **États vides** : « Aucun accès pour l'instant. Invitez le collaborateur à créer son espace. » · « Aucun bulletin publié pour cette personne. » · « Aucun compte » · « Aucun contrat actif. » · « Aucun contrat actif. Créez un contrat pour cet employé. » · « Aucun document RH généré. »
- **Gestion d'erreur** : 29 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/people`
- **Fichiers** (17) : `components/modules/CapabilityChangePreview.vue`, `components/modules/ModuleCapabilityAccess.vue`, `components/workforce/EmployeePicker.vue`, `pages/company/people/_MemberHealth.vue`, `pages/company/people/_OnboardingInviteDialog.vue`, `pages/company/people/_OnboardingPanel.vue`, `pages/company/people/_OnboardingReviewDialog.vue`, `pages/company/people/_PendingAccessReview.vue`, `pages/company/people/_PeopleHrTools.vue`, `pages/company/people/_PersonAddDrawer.vue`, `pages/company/people/_ProfileFieldsSettings.vue`, `pages/company/people/_RoleCapabilities.vue`, `pages/company/people/index.vue`, `pages/company/roles.vue`, `pages/company/workforce/employees/_EmployeeBulkActionDialog.vue`, `pages/company/workforce/employees/_EmployeeImportDrawer.vue`, `pages/shared/_PermissionMatrix.vue`
- **Magasins** : auth.js, employees.store.js, jobdomain.store.js, members.store.js, module.js, people-list.store.js, settings.store.js, useAppToast.js, useCan.js, useConfirm.js
- **Onglets détectés** : active, addons, available, capabilities, collective, core, custom, disabled, hr, own, people, platform-ext, roles, sensitive
- **Dialogues** : _EmployeeBulkActionDialog, _EmployeeImportDrawer, _OnboardingInviteDialog, _OnboardingReviewDialog, _PersonAddDrawer
- **API** (68) : `/2fa/verify`, `/company`, `/company/document-activations`, `/company/document-types/custom`, `/company/document-types/custom/${code}`, `/company/document-types/custom/${code}/archive`, `/company/documents`, `/company/field-activations`, `/company/field-definitions`, `/company/field-definitions/${id}`, `/company/jobdomain`, `/company/legal-structure`, `/company/members`, `/company/members/${id}`, `/company/members/${id}/fields${params}`, `/company/members/${id}/password`, `/company/members/${id}/reset-password`, `/company/pending-access`, `/company/pending-access/confirm`, `/company/people`, `/company/role-capabilities`, `/company/role-capabilities/${moduleKey.value}`, `/company/role-capabilities/${moduleKey.value}/preview`, `/company/roles`, `/company/roles/${id}`, `/company/roles/${r.id}/confirm-pending`, `/company/roles/${role.id}/confirm-pending`, `/login`, `/logout`, `/me`, `/modules`, `/modules/${key}/deactivation-preview`, `/modules/${key}/disable`, `/modules/${key}/enable`, `/modules/${key}/settings`, `/modules/quote`, `/my-companies`, `/register`, `/register/confirm-payment`, `/workforce/conventions`
- **Navigation sortante** : /company/people/, /company/people/employee-, company-people
- **États vides** : « Ajouter un employé » · « Aucun champ membre activé pour le moment. » · « Aucun champ ne correspond à votre recherche. » · « Aucun changement : les accès actuels sont conservés. » · « Aucun collaborateur ne correspond à vos filtres. » · « Aucun collaborateur. »
- **Gestion d'erreur** : 26 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/plan`
- **Fichiers** (1) : `pages/company/plan.vue`
- **Navigation sortante** : company-billing-tab
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/profile/:tab`
- **Fichiers** (2) : `pages/company/profile/[tab].vue`, `pages/company/profile/_CompanyProfileOverview.vue`
- **Magasins** : auth.js, settings.store.js, useAppToast.js, useUnsavedChanges.js, world.js
- **Onglets détectés** : item.tab
- **API** (16) : `/2fa/verify`, `/company`, `/company/document-activations`, `/company/document-types/custom`, `/company/document-types/custom/${code}`, `/company/document-types/custom/${code}/archive`, `/company/documents`, `/company/legal-structure`, `/company/roles`, `/company/roles/${id}`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`
- **Navigation sortante** : company-profile-tab, dashboard
- **Gestion d'erreur** : 1 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/roles`
- **Fichiers** (2) : `pages/company/roles.vue`, `pages/shared/_PermissionMatrix.vue`
- **Magasins** : auth.js, members.store.js, settings.store.js, useAppToast.js, useConfirm.js
- **Onglets détectés** : addons, core, disabled, platform-ext
- **API** (25) : `/2fa/verify`, `/company`, `/company/document-activations`, `/company/document-types/custom`, `/company/document-types/custom/${code}`, `/company/document-types/custom/${code}/archive`, `/company/documents`, `/company/field-activations`, `/company/field-definitions`, `/company/field-definitions/${id}`, `/company/legal-structure`, `/company/members`, `/company/members/${id}`, `/company/members/${id}/fields${params}`, `/company/members/${id}/password`, `/company/members/${id}/reset-password`, `/company/roles`, `/company/roles/${id}`, `/company/roles/${role.id}/confirm-pending`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`
- **Navigation sortante** : company-people
- **États vides** : « Aucun rôle personnalisé » · « Les rôles personnalisés permettent de contrôler finement les accès de vos membres. »
- **Gestion d'erreur** : 3 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/settings`
- **Fichiers** (1) : `pages/company/settings/index.vue`
- **Magasins** : nav.js, settings.store.js, useCompanyPermissionContext.js
- **Onglets détectés** : advanced
- **API** (11) : `/company`, `/company/document-activations`, `/company/document-types/custom`, `/company/document-types/custom/${code}`, `/company/document-types/custom/${code}/archive`, `/company/documents`, `/company/legal-structure`, `/company/roles`, `/company/roles/${id}`, `/nav`, `/workforce/signature/options`
- **États vides** : « Aucun réglage n'est accessible avec vos droits. » · « Aucun réglage ne correspond à votre recherche. »
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/shipments/:id`
- **Fichiers** (1) : `pages/company/shipments/[id].vue`
- **Magasins** : auth.js, members.store.js, shipment.store.js
- **API** (19) : `/2fa/verify`, `/company/field-activations`, `/company/field-definitions`, `/company/field-definitions/${id}`, `/company/members`, `/company/members/${id}`, `/company/members/${id}/fields${params}`, `/company/members/${id}/password`, `/company/members/${id}/reset-password`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`, `/shipments`, `/shipments/${id}`, `/shipments/${id}/assign`, `/shipments/${id}/status`
- **Navigation sortante** : company-shipments
- **États vides** : « Aucune action disponible. »
- **Gestion d'erreur** : 2 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/shipments/create`
- **Fichiers** (1) : `pages/company/shipments/create.vue`
- **Magasins** : shipment.store.js, useUnsavedChanges.js
- **API** (4) : `/shipments`, `/shipments/${id}`, `/shipments/${id}/assign`, `/shipments/${id}/status`
- **Navigation sortante** : company-shipments, company-shipments-id
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/shipments`
- **Fichiers** (1) : `pages/company/shipments/index.vue`
- **Magasins** : auth.js, shipment.store.js
- **API** (11) : `/2fa/verify`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`, `/shipments`, `/shipments/${id}`, `/shipments/${id}/assign`, `/shipments/${id}/status`
- **Navigation sortante** : company-my-deliveries, company-shipments-create, company-shipments-id
- **États vides** : « Aucune expédition trouvée. »
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/support/:id`
- **Fichiers** (1) : `pages/company/support/[id].vue`
- **Magasins** : auth.js, support.store.js
- **API** (10) : `/2fa/verify`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`, `/support/tickets`, `/support/tickets/${id}`, `/support/tickets/${ticketId}/messages`
- **Navigation sortante** : company-support
- **États vides** : « Aucun message »
- **Gestion d'erreur** : 2 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/support`
- **Fichiers** (1) : `pages/company/support/index.vue`
- **Magasins** : support.store.js
- **API** (3) : `/support/tickets`, `/support/tickets/${id}`, `/support/tickets/${ticketId}/messages`
- **Navigation sortante** : company-support-id
- **États vides** : « Aucun ticket pour le moment »
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workflows/:id`
- **Fichiers** (1) : `pages/company/workflows/[id].vue`
- **Magasins** : workflows.store.js
- **API** (5) : `/workflows`, `/workflows/${id}`, `/workflows/${rule.id}`, `/workflows/${ruleId}/logs`, `/workflows/triggers`
- **Navigation sortante** : company-workflows
- **États vides** : « Aucune condition — s'exécute à chaque déclenchement. » · « Aucune exécution enregistrée. »
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workflows`
- **Fichiers** (1) : `pages/company/workflows/index.vue`
- **Magasins** : workflows.store.js
- **API** (5) : `/workflows`, `/workflows/${id}`, `/workflows/${rule.id}`, `/workflows/${ruleId}/logs`, `/workflows/triggers`
- **Navigation sortante** : company-workflows-id
- **États vides** : « Aucune règle d'automatisation » · « Créez des règles pour automatiser les tâches récurrentes de votre entreprise. »
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/configuration`
- **Fichiers** (1) : `pages/company/workforce/configuration.vue`
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/contract/templates`
- **Fichiers** (1) : `pages/company/workforce/contract/templates.vue`
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/documents`
- **Fichiers** (1) : `pages/company/workforce/documents/index.vue`
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/dsn/:id`
- **Fichiers** (1) : `pages/company/workforce/dsn/[id].vue`
- **Magasins** : dsn.store.js, useAppToast.js
- **Onglets détectés** : accepted, draft, exported, rejected, submitted, validated
- **API** (9) : `/workforce/dsn${qs `, `/workforce/dsn/${id}`, `/workforce/dsn/${id}/history`, `/workforce/dsn/${id}/poll`, `/workforce/dsn/${id}/submit`, `/workforce/dsn/${id}/validation`, `/workforce/dsn/capability`, `/workforce/dsn/export`, `/workforce/dsn/statistics`
- **Navigation sortante** : company-workforce-dsn
- **États vides** : « Aucun changement de statut »
- **Gestion d'erreur** : 4 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/dsn`
- **Fichiers** (2) : `components/workforce/PayrollRunPicker.vue`, `pages/company/workforce/dsn/index.vue`
- **Magasins** : dsn.store.js, useAppToast.js
- **API** (10) : `/workforce/dsn${qs `, `/workforce/dsn/${id}`, `/workforce/dsn/${id}/history`, `/workforce/dsn/${id}/poll`, `/workforce/dsn/${id}/submit`, `/workforce/dsn/${id}/validation`, `/workforce/dsn/capability`, `/workforce/dsn/export`, `/workforce/dsn/statistics`, `/workforce/payroll`
- **Navigation sortante** : company-workforce-dsn-id
- **États vides** : « Aucune declaration DSN » · « Exportez une declaration DSN depuis un run de paie pour commencer. »
- **Gestion d'erreur** : 2 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/employees/:id`
- **Fichiers** (11) : `pages/company/workforce/employees/[id].vue`, `pages/company/workforce/employees/_EmployeeAccessCard.vue`, `pages/company/workforce/employees/_EmployeeAccessDialog.vue`, `pages/company/workforce/employees/_EmployeeContract.vue`, `pages/company/workforce/employees/_EmployeeDocumentsSection.vue`, `pages/company/workforce/employees/_EmployeeLeaveSection.vue`, `pages/company/workforce/employees/_EmployeePlanningSection.vue`, `pages/company/workforce/employees/_EmployeeProfile.vue`, `pages/company/workforce/employees/_EmployeeSignaturesSection.vue`, `pages/company/workforce/employees/_EmployeeTimeSection.vue`, `pages/company/workforce/employees/_GenerateContractDialog.vue`
- **Magasins** : contract.store.js, employees.store.js, signature.store.js, useAppToast.js, useCan.js
- **Dialogues** : _EmployeeAccessDialog, _GenerateContractDialog
- **API** (59) : `/workforce/contract/generate`, `/workforce/contract/preview`, `/workforce/contract/preview-pdf`, `/workforce/contract/templates${qs}`, `/workforce/contract/templates/${id}`, `/workforce/contract/templates/${id}/archive`, `/workforce/contract/templates/${id}/default`, `/workforce/contract/templates/${id}/duplicate`, `/workforce/contract/templates/${id}/publish`, `/workforce/contract/templates/${id}/version`, `/workforce/contract/templates/variables`, `/workforce/conventions`, `/workforce/departments`, `/workforce/departments/${id}`, `/workforce/documents/${doc.id}/${signed `, `/workforce/documents/by-employee/${props.employeeId}`, `/workforce/employees`, `/workforce/employees${qs `, `/workforce/employees/${empId.value}/file`, `/workforce/employees/${employeeId}/contracts`, `/workforce/employees/${employeeId}/contracts/${contractId}/activate`, `/workforce/employees/${id}`, `/workforce/employees/${id}/access`, `/workforce/employees/${id}/access/resend`, `/workforce/employees/${id}/access/restore`, `/workforce/employees/${id}/access/revoke`, `/workforce/employees/${id}/access/suspend`, `/workforce/employees/${id}/terminate`, `/workforce/employees/${props.employee.id}/contracts/${cancelTarget.value}/cancel`, `/workforce/employees/${props.employeeId}/leave-balances`, `/workforce/employees/bulk`, `/workforce/employees/bulk/preview`, `/workforce/employees/export${qs `, `/workforce/employees/import`, `/workforce/employees/import/preview`, `/workforce/employees/import/template`, `/workforce/employees/summary`, `/workforce/job-roles`, `/workforce/job-roles/${id}`, `/workforce/leaves`
- **Navigation sortante** : /company/documents/hr, /company/workforce/leave, /company/workforce/planning, /company/workforce/time
- **États vides** : « Aucun accès pour l'instant. Invitez le collaborateur à créer son espace. » · « Aucun contrat actif. » · « Aucun contrat actif. Créez un contrat pour cet employé. » · « Aucun document RH généré. » · « Aucun e-mail — renseignez-le pour inviter. » · « Aucun pointage récent. »
- **Gestion d'erreur** : 13 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/employees`
- **Fichiers** (1) : `pages/company/workforce/employees/index.vue`
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce`
- **Fichiers** (1) : `pages/company/workforce/index.vue`
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/leave`
- **Fichiers** (5) : `components/workforce/EmployeePicker.vue`, `components/workforce/TimeSpaceHeader.vue`, `pages/company/workforce/leave/_LeaveCalendar.vue`, `pages/company/workforce/leave/_LeaveRequestDetailDialog.vue`, `pages/company/workforce/leave/index.vue`
- **Magasins** : employees.store.js, leaves.store.js, personal-space.store.js, useAppToast.js, useCan.js, useCompanyPermissionContext.js, useSettingsEntry.js
- **Onglets détectés** : balances, calendar, config, leave, me, planning, requests, time, v.key
- **Dialogues** : _LeaveRequestDetailDialog
- **API** (39) : `/profile/space`, `/workforce/config/week-start`, `/workforce/conventions`, `/workforce/departments`, `/workforce/departments/${id}`, `/workforce/employees`, `/workforce/employees${qs `, `/workforce/employees/${employeeId}/contracts`, `/workforce/employees/${employeeId}/contracts/${contractId}/activate`, `/workforce/employees/${employeeId}/leave-balances${qs `, `/workforce/employees/${id}`, `/workforce/employees/${id}/access`, `/workforce/employees/${id}/access/resend`, `/workforce/employees/${id}/access/restore`, `/workforce/employees/${id}/access/revoke`, `/workforce/employees/${id}/access/suspend`, `/workforce/employees/${id}/terminate`, `/workforce/employees/bulk`, `/workforce/employees/bulk/preview`, `/workforce/employees/export${qs `, `/workforce/employees/import`, `/workforce/employees/import/preview`, `/workforce/employees/import/template`, `/workforce/employees/summary`, `/workforce/job-roles`, `/workforce/job-roles/${id}`, `/workforce/leaves`, `/workforce/leaves${qs `, `/workforce/leaves/${id}`, `/workforce/leaves/${id}/approve`, `/workforce/leaves/${id}/cancel`, `/workforce/leaves/${id}/impacted-shifts`, `/workforce/leaves/${id}/reject`, `/workforce/leaves/calendar`, `/workforce/leaves/deduction-preview`, `/workforce/leaves/statistics${qs `, `/workforce/leaves/types`, `/workforce/leaves/types${all `, `/workforce/leaves/types/${id}`
- **Navigation sortante** : company-workforce-leave, company-workforce-me, company-workforce-planning, company-workforce-time
- **États vides** : « Ajoutez un premier type de congé pour permettre aux employés de soumettre des demandes. » · « Aucun compteur n'a encore été initialisé pour cet employé sur cette année. » · « Aucun solde de congés » · « Aucun type de congé configuré » · « Aucune autre absence sur cette période. » · « Aucune demande de congé »
- **Gestion d'erreur** : 8 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/me`
- **Fichiers** (2) : `components/workforce/TimeSpaceHeader.vue`, `pages/company/workforce/me.vue`
- **Magasins** : module.js, personal-space.store.js, useAppToast.js, useCompanyPermissionContext.js, useSettingsEntry.js, workforce-clock.js
- **Onglets détectés** : arrival, break, hours-pending, hours-period, hours-validated, hours-week, leave, me, planning, status, time, v.key, worked
- **API** (19) : `/modules`, `/modules/${key}/deactivation-preview`, `/modules/${key}/disable`, `/modules/${key}/enable`, `/modules/${key}/settings`, `/modules/quote`, `/profile/space`, `/workforce/me`, `/workforce/me/break/end`, `/workforce/me/break/start`, `/workforce/me/clock-in`, `/workforce/me/clock-out`, `/workforce/me/documents`, `/workforce/me/generated-documents/${doc.id}/download${signed `, `/workforce/me/hours`, `/workforce/me/leaves`, `/workforce/me/leaves/deduction-preview`, `/workforce/me/shifts`, `/workforce/me/today`
- **Navigation sortante** : company-my-space-tab, company-workforce-leave, company-workforce-me, company-workforce-payroll, company-workforce-planning, company-workforce-time
- **États vides** : « Aucun contrat actif » · « Aucun créneau publié à venir. Votre planning apparaîtra ici dès que votre responsable l'aura publié. » · « Aucun dossier employé trouvé pour votre compte. » · « Aucun solde de congés disponible » · « Aucun solde de congés n'est ouvert pour vous. Votre entreprise doit d'abord activer votre contrat ; vous pourrez ensuite demander un congé ici. » · « Aucune durée cible configurée »
- **Gestion d'erreur** : 2 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/onboarding`
- **Fichiers** (3) : `pages/company/workforce/_WorkingTimeSetup.vue`, `pages/company/workforce/onboarding.vue`, `pages/company/workforce/organization/_OrganizationPresetCard.vue`
- **Magasins** : useAppToast.js
- **Onglets détectés** : s
- **API** (10) : `/workforce/onboarding`, `/workforce/onboarding/defer`, `/workforce/onboarding/finish`, `/workforce/onboarding/start`, `/workforce/onboarding/step`, `/workforce/organization/preset/apply`, `/workforce/organization/preset/preview`, `/workforce/organization/profile`, `/workforce/working-time-setup`, `/workforce/working-time-setup/convention`
- **États vides** : « Aucun modèle disponible pour ce profil. » · « Aucun profil » · « Aucune convention disponible pour ce marché » · « Aucune convention ne s’applique »
- **Gestion d'erreur** : 2 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/organization/:tab`
- **Fichiers** (3) : `pages/company/workforce/organization/[tab].vue`, `pages/company/workforce/organization/_OrganizationLocations.vue`, `pages/company/workforce/organization/_OrganizationPresetCard.vue`
- **Magasins** : employees.store.js, planning.store.js, useAppToast.js, useCan.js, world.js
- **Onglets détectés** : departments, locations, roles
- **API** (80) : `/workforce/config/week-start`, `/workforce/conventions`, `/workforce/departments`, `/workforce/departments/${id}`, `/workforce/employees`, `/workforce/employees${qs `, `/workforce/employees/${employeeId}/contracts`, `/workforce/employees/${employeeId}/contracts/${contractId}/activate`, `/workforce/employees/${id}`, `/workforce/employees/${id}/access`, `/workforce/employees/${id}/access/resend`, `/workforce/employees/${id}/access/restore`, `/workforce/employees/${id}/access/revoke`, `/workforce/employees/${id}/access/suspend`, `/workforce/employees/${id}/terminate`, `/workforce/employees/bulk`, `/workforce/employees/bulk/preview`, `/workforce/employees/export${qs `, `/workforce/employees/import`, `/workforce/employees/import/preview`, `/workforce/employees/import/template`, `/workforce/employees/summary`, `/workforce/job-roles`, `/workforce/job-roles/${id}`, `/workforce/organization/preset/apply`, `/workforce/organization/preset/preview`, `/workforce/organization/profile`, `/workforce/planning/activities`, `/workforce/planning/activities${qs `, `/workforce/planning/activities/${id}`, `/workforce/planning/activities/${id}/cancel`, `/workforce/planning/activities/${id}/duplicate`, `/workforce/planning/activity-presets`, `/workforce/planning/activity-presets/${id}`, `/workforce/planning/coverage`, `/workforce/planning/coverage-categories`, `/workforce/planning/coverage-categories/${id}`, `/workforce/planning/coverage/${id}`, `/workforce/planning/coverage/replicate`, `/workforce/planning/coverage/summary`
- **États vides** : « Aucun département enregistré. » · « Aucun lieu de travail défini. » · « Aucun modèle disponible pour ce profil. » · « Aucun poste enregistré. » · « Aucun profil » · « Créez votre premier département pour structurer votre organisation. »
- **Gestion d'erreur** : 5 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/payroll/:id`
- **Fichiers** (3) : `pages/company/workforce/payroll/[id].vue`, `pages/company/workforce/payroll/_EmployeePreparationPanel.vue`, `pages/company/workforce/payroll/_PayrollDisclaimer.vue`
- **Magasins** : payroll.store.js, useAppToast.js, useCan.js
- **Onglets détectés** : computed, draft, exported, validated
- **API** (15) : `/workforce/documents/payslip-drafts`, `/workforce/documents/payslip-official`, `/workforce/payroll`, `/workforce/payroll${qs `, `/workforce/payroll/${id}`, `/workforce/payroll/${id}/compute`, `/workforce/payroll/${id}/compute-calculations`, `/workforce/payroll/${id}/export`, `/workforce/payroll/${id}/recompute`, `/workforce/payroll/${id}/validate`, `/workforce/payroll/${runId}/calculations`, `/workforce/payroll/${runId}/documents`, `/workforce/payroll/${runId}/lines${qs `, `/workforce/payroll/${runId}/lines/${lineId}`, `/workforce/payroll/statistics`
- **Navigation sortante** : company-workforce-employees-id, company-workforce-leave, company-workforce-payroll, company-workforce-time
- **États vides** : « Aucun document généré pour ce cycle. » · « Aucune anomalie pour ce collaborateur. »
- **Gestion d'erreur** : 6 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/payroll`
- **Fichiers** (4) : `components/workforce/PayrollRunPicker.vue`, `pages/company/workforce/dsn/index.vue`, `pages/company/workforce/payroll/_PayrollDisclaimer.vue`, `pages/company/workforce/payroll/index.vue`
- **Magasins** : dsn.store.js, payroll.store.js, useAppToast.js, useCan.js
- **Onglets détectés** : declarations, periods
- **API** (24) : `/workforce/documents/payslip-drafts`, `/workforce/documents/payslip-official`, `/workforce/dsn${qs `, `/workforce/dsn/${id}`, `/workforce/dsn/${id}/history`, `/workforce/dsn/${id}/poll`, `/workforce/dsn/${id}/submit`, `/workforce/dsn/${id}/validation`, `/workforce/dsn/capability`, `/workforce/dsn/export`, `/workforce/dsn/statistics`, `/workforce/payroll`, `/workforce/payroll${qs `, `/workforce/payroll/${id}`, `/workforce/payroll/${id}/compute`, `/workforce/payroll/${id}/compute-calculations`, `/workforce/payroll/${id}/export`, `/workforce/payroll/${id}/recompute`, `/workforce/payroll/${id}/validate`, `/workforce/payroll/${runId}/calculations`, `/workforce/payroll/${runId}/documents`, `/workforce/payroll/${runId}/lines${qs `, `/workforce/payroll/${runId}/lines/${lineId}`, `/workforce/payroll/statistics`
- **Navigation sortante** : company-workforce-dsn-id, company-workforce-payroll-id
- **États vides** : « Aucune declaration DSN » · « Aucune période de paie » · « Créez une période pour commencer à préparer la paie. » · « Exportez une declaration DSN depuis un run de paie pour commencer. »
- **Gestion d'erreur** : 4 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/planning`
- **Fichiers** (7) : `components/workforce/EmployeePicker.vue`, `components/workforce/TimeSpaceHeader.vue`, `pages/company/workforce/planning/_ActivityDay.vue`, `pages/company/workforce/planning/_MonthView.vue`, `pages/company/workforce/planning/_PlanningCalendar.vue`, `pages/company/workforce/planning/_PostsView.vue`, `pages/company/workforce/planning/index.vue`
- **Magasins** : employees.store.js, personal-space.store.js, planning.store.js, useAppToast.js, useCan.js, useCompanyPermissionContext.js, useSettingsEntry.js, world.js
- **Onglets détectés** : all, copyprev, copyweek, leave, me, planning, scratch, templates, time, v.key, weektpl
- **API** (78) : `/profile/space`, `/workforce/config/week-start`, `/workforce/conventions`, `/workforce/departments`, `/workforce/departments/${id}`, `/workforce/employees`, `/workforce/employees${qs `, `/workforce/employees/${employeeId}/contracts`, `/workforce/employees/${employeeId}/contracts/${contractId}/activate`, `/workforce/employees/${id}`, `/workforce/employees/${id}/access`, `/workforce/employees/${id}/access/resend`, `/workforce/employees/${id}/access/restore`, `/workforce/employees/${id}/access/revoke`, `/workforce/employees/${id}/access/suspend`, `/workforce/employees/${id}/terminate`, `/workforce/employees/bulk`, `/workforce/employees/bulk/preview`, `/workforce/employees/export${qs `, `/workforce/employees/import`, `/workforce/employees/import/preview`, `/workforce/employees/import/template`, `/workforce/employees/summary`, `/workforce/job-roles`, `/workforce/job-roles/${id}`, `/workforce/planning/activities`, `/workforce/planning/activities${qs `, `/workforce/planning/activities/${id}`, `/workforce/planning/activities/${id}/cancel`, `/workforce/planning/activities/${id}/duplicate`, `/workforce/planning/activity-presets`, `/workforce/planning/activity-presets/${id}`, `/workforce/planning/coverage`, `/workforce/planning/coverage-categories`, `/workforce/planning/coverage-categories/${id}`, `/workforce/planning/coverage/${id}`, `/workforce/planning/coverage/replicate`, `/workforce/planning/coverage/summary`, `/workforce/planning/favorites`, `/workforce/planning/favorites/toggle`
- **Navigation sortante** : company-workforce-leave, company-workforce-me, company-workforce-planning, company-workforce-time
- **États vides** : « Aucun collaborateur disponible dans votre périmètre sur ce créneau. » · « Aucun créneau ce jour. » · « Aucun créneau planifié » · « Aucun modèle correspondant. » · « Aucun modèle de créneau » · « Aucun modèle métier disponible pour cette activité. »
- **Gestion d'erreur** : 51 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/requirements`
- **Fichiers** (2) : `pages/company/workforce/requirements/_ModuleProposals.vue`, `pages/company/workforce/requirements/index.vue`
- **Magasins** : useAppToast.js
- **API** (7) : `/workforce/requirements`, `/workforce/requirements/catalog`, `/workforce/requirements/legacy`, `/workforce/requirements/modules`, `/workforce/requirements/modules/${m.module_key}/decisions`, `/workforce/requirements/modules/${m.module_key}/preview`, `/workforce/requirements/preview`
- **États vides** : « Aucun changement. » · « Rien de demandé pour l'instant. » · « aucun poste correspondant »
- **Gestion d'erreur** : 4 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/settings/:tab`
- **Fichiers** (5) : `pages/company/workforce/_WorkingTimeSetup.vue`, `pages/company/workforce/settings/[tab].vue`, `pages/company/workforce/settings/_ContractSettingsSection.vue`, `pages/company/workforce/settings/_SignatureSettingsSection.vue`, `pages/company/workforce/settings/_WorkforceRulesSection.vue`
- **Magasins** : contract.store.js, signature.store.js, useAppToast.js, useCan.js
- **Onglets détectés** : s, s.key
- **API** (33) : `/workforce/config/${props.domain}`, `/workforce/config/${props.domain}/${r.key}`, `/workforce/config/week-start`, `/workforce/contract/generate`, `/workforce/contract/preview`, `/workforce/contract/preview-pdf`, `/workforce/contract/templates${qs}`, `/workforce/contract/templates/${id}`, `/workforce/contract/templates/${id}/archive`, `/workforce/contract/templates/${id}/default`, `/workforce/contract/templates/${id}/duplicate`, `/workforce/contract/templates/${id}/publish`, `/workforce/contract/templates/${id}/version`, `/workforce/contract/templates/variables`, `/workforce/settings`, `/workforce/signature`, `/workforce/signature${qs `, `/workforce/signature/${id}`, `/workforce/signature/${id}/cancel`, `/workforce/signature/${id}/download`, `/workforce/signature/${id}/evidence`, `/workforce/signature/${id}/evidence-package`, `/workforce/signature/${id}/remind`, `/workforce/signature/${id}/verify`, `/workforce/signature/by-employee/${employeeId}`, `/workforce/signature/certificates`, `/workforce/signature/eligible-documents`, `/workforce/signature/options`, `/workforce/signature/reconciliation`, `/workforce/signature/settings`, `/workforce/signature/supervision`, `/workforce/working-time-setup`, `/workforce/working-time-setup/convention`
- **Navigation sortante** : company-profile-tab
- **États vides** : « Aucun modèle par défaut défini. Choisissez-en un dans la bibliothèque. » · « Aucune convention disponible pour ce marché » · « Aucune convention ne s’applique » · « Aucune information d'entreprise renseignée. Complétez votre fiche entreprise pour enrichir vos contrats. » · « Non défini »
- **Gestion d'erreur** : 4 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/signature`
- **Fichiers** (1) : `pages/company/workforce/signature/index.vue`
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/time/:id`
- **Fichiers** (1) : `pages/company/workforce/time/[id].vue`
- **Magasins** : timesheets.store.js, useAppToast.js, useCan.js
- **Onglets détectés** : breaks, overtime, planned, worked
- **API** (23) : `/workforce/time-entries`, `/workforce/time-entries${qs `, `/workforce/time-entries/${id}`, `/workforce/time-entries/${id}/break/end`, `/workforce/time-entries/${id}/break/start`, `/workforce/time-entries/${id}/clock-out`, `/workforce/time-entries/active`, `/workforce/time-entries/anomalies${qs `, `/workforce/time-entries/clock-in`, `/workforce/time-entries/cockpit/board${qs `, `/workforce/time-entries/cockpit/summary${date `, `/workforce/time-entries/company${qs `, `/workforce/timesheets`, `/workforce/timesheets${qs `, `/workforce/timesheets/${id}`, `/workforce/timesheets/${id}/approve`, `/workforce/timesheets/${id}/compliance`, `/workforce/timesheets/${id}/history`, `/workforce/timesheets/${id}/lock`, `/workforce/timesheets/${id}/reject`, `/workforce/timesheets/${id}/reopen`, `/workforce/timesheets/${id}/submit`, `/workforce/timesheets/statistics${qs `
- **Navigation sortante** : company-workforce-time
- **États vides** : « Aucun événement enregistré. »
- **Gestion d'erreur** : 1 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/company/workforce/time`
- **Fichiers** (5) : `components/workforce/EmployeePicker.vue`, `components/workforce/TimeSpaceHeader.vue`, `pages/company/workforce/time/_DayDetailPanel.vue`, `pages/company/workforce/time/_TimeCockpit.vue`, `pages/company/workforce/time/index.vue`
- **Magasins** : employees.store.js, personal-space.store.js, planning.store.js, timesheets.store.js, useAppToast.js, useCan.js, useCompanyPermissionContext.js, useSettingsEntry.js
- **Onglets détectés** : anomalies, approved, cockpit, completed, draft, entries, incomplete, leave, locked, me, not_clocked, on_break, pending_timesheets, planning, submitted, time, timesheets, v.key, working
- **API** (101) : `/profile/space`, `/workforce/config/week-start`, `/workforce/conventions`, `/workforce/departments`, `/workforce/departments/${id}`, `/workforce/employees`, `/workforce/employees${qs `, `/workforce/employees/${employeeId}/contracts`, `/workforce/employees/${employeeId}/contracts/${contractId}/activate`, `/workforce/employees/${id}`, `/workforce/employees/${id}/access`, `/workforce/employees/${id}/access/resend`, `/workforce/employees/${id}/access/restore`, `/workforce/employees/${id}/access/revoke`, `/workforce/employees/${id}/access/suspend`, `/workforce/employees/${id}/terminate`, `/workforce/employees/bulk`, `/workforce/employees/bulk/preview`, `/workforce/employees/export${qs `, `/workforce/employees/import`, `/workforce/employees/import/preview`, `/workforce/employees/import/template`, `/workforce/employees/summary`, `/workforce/job-roles`, `/workforce/job-roles/${id}`, `/workforce/planning/activities`, `/workforce/planning/activities${qs `, `/workforce/planning/activities/${id}`, `/workforce/planning/activities/${id}/cancel`, `/workforce/planning/activities/${id}/duplicate`, `/workforce/planning/activity-presets`, `/workforce/planning/activity-presets/${id}`, `/workforce/planning/coverage`, `/workforce/planning/coverage-categories`, `/workforce/planning/coverage-categories/${id}`, `/workforce/planning/coverage/${id}`, `/workforce/planning/coverage/replicate`, `/workforce/planning/coverage/summary`, `/workforce/planning/favorites`, `/workforce/planning/favorites/toggle`
- **Navigation sortante** : /company/workforce/employees/, company-workforce-leave, company-workforce-me, company-workforce-planning, company-workforce-time, company-workforce-time-id
- **États vides** : « Aucun créneau ce jour » · « Aucun pointage » · « Aucun pointage ce jour » · « Aucune donnée pour cette date. » · « Aucune feuille de temps » · « Générez des feuilles de temps pour commencer le suivi des heures. »
- **Gestion d'erreur** : 14 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/dashboard`
- **Fichiers** (6) : `components/dashboard/DashboardGrid.vue`, `components/dashboard/DashboardHostContainer.vue`, `components/dashboard/HomePrioritiesSection.vue`, `pages/company/dashboard/_OnboardingWidget.vue`, `pages/company/dashboard/_QuickActionsBar.vue`, `pages/dashboard.vue`
- **Magasins** : auth.js, compliance.store.js, dashboard.store.js, useCan.js, useDashboardGrid.js, useSpaceActions.js, workforce-clock.js
- **API** (21) : `/2fa/verify`, `/company/documents/compliance`, `/dashboard/onboarding`, `/dashboard/onboarding/dismiss`, `/dashboard/onboarding/reopen`, `/dashboard/priorities`, `/dashboard/suggestions`, `/login`, `/logout`, `/me`, `/my-companies`, `/register`, `/register/confirm-payment`, `/workforce/me`, `/workforce/me/break/end`, `/workforce/me/break/start`, `/workforce/me/clock-in`, `/workforce/me/clock-out`, `/workforce/me/leaves`, `/workforce/me/leaves/deduction-preview`, `/workforce/me/today`
- **Navigation sortante** : company-billing-tab, company-documents-tab, company-workforce-me
- **États vides** : « Aucun indicateur affiché. Utilisez « Personnaliser » pour en ajouter. » · « Aucun indicateur à afficher pour le moment. »
- **Gestion d'erreur** : 1 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : oui

### `/home`
- **Fichiers** (3) : `components/dashboard/DashboardGrid.vue`, `components/dashboard/DashboardHostContainer.vue`, `pages/home.vue`
- **Magasins** : auth.js, employee-home.store.js, home.store.js, personal-space.store.js, useCan.js, useDashboardGrid.js, workforce-clock.js
- **Onglets détectés** : deliveries, docs, leaves, signatures
- **API** (19) : `/2fa/verify`, `/login`, `/logout`, `/me`, `/my-companies`, `/my-deliveries`, `/profile/documents`, `/profile/space`, `/register`, `/register/confirm-payment`, `/workforce/me`, `/workforce/me/break/end`, `/workforce/me/break/start`, `/workforce/me/clock-in`, `/workforce/me/clock-out`, `/workforce/me/leaves`, `/workforce/me/leaves/deduction-preview`, `/workforce/me/shifts`, `/workforce/me/today`
- **Navigation sortante** : /company/workforce/me, company-my-space-tab
- **États vides** : « Aucun créneau planifié pour le moment. » · « Rien d'urgent pour l'instant » · « Vos créneaux, pointages et demandes apparaîtront ici dès qu'ils seront disponibles. »
- **Gestion d'erreur** : 1 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : oui

### `/invitations`
- **Fichiers** (2) : `components/PendingInvitationsCard.vue`, `pages/invitations.vue`
- **Magasins** : auth.js, useAppToast.js
- **API** (9) : `/2fa/verify`, `/login`, `/logout`, `/me`, `/my-companies`, `/my-invitations`, `/my-invitations/${inv.id}/${action}`, `/register`, `/register/confirm-payment`
- **États vides** : « Aucun accès n'est ouvert tant que vous n'avez pas accepté. » · « Vous n'avez aucune invitation en attente. »
- **Gestion d'erreur** : 3 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/onboarding`
- **Fichiers** (1) : `pages/onboarding/index.vue`
- **États vides** : « Les informations sensibles seront recueillies directement par l'entreprise. »
- **Gestion d'erreur** : 5 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/sign/:token`
- **Fichiers** (1) : `pages/sign/[token].vue`
- **API** (4) : `/public/signature/${token.value}`, `/public/signature/${token.value}/decline`, `/public/signature/${token.value}/prepare`, `/public/signature/${token.value}/sign`
- **Gestion d'erreur** : 5 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

### `/ui-lab`
- **Fichiers** (1) : `pages/ui-lab.vue`
- **Onglets détectés** : 30d, 7d, empty, error, loaded, loading, mtd
- **Navigation sortante** : /company/audit, /company/documents/overview, /company/workforce/planning, /company/workforce/time, /dashboard
- **Gestion d'erreur** : 0 point(s) (`catch`, toast d'erreur, `ErrorState`) · **Widgets** : non

