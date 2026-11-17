# ADR-815 — Routes, permissions et autorisations réellement vérifiées

> Audit en lecture seule. Code : `70ec3dbe`. Les vérifications HTTP ont été faites sur le **clone isolé** (`evidence/http-probes.json`, `evidence/bob-person.json`).

## 1. Chaîne d'autorisation

```
Navigateur ─ guards.js (routeur) ─────────────── meta.module / meta.permission / meta.anyPermission / meta.surface='structure'
             (confort : masque et redirige, ne protège rien)
API ──────── SetCompanyContext (X-Company-Id) ─▶ EnsureCompanyAccess:use-module,<module>
                                               ▶ EnsureCompanyAccess:use-permission|use-any-permission,<clés>
                                               ▶ EnsureBusinessAccess, SessionGovernance
             contrôleur / cas d'usage ────────▶ périmètre (WorkforceAccess, EnsureEmployeeInScope, isOwnerOf…)
Navigation ─ NavBuilder::forCompany(permissions, niveau) depuis les manifestes des modules (groupes : home, team, time, documents, payroll, activity, modules, settings, personal_menu)
```

**Volumes** : 473 routes API portent le contexte société. 449 sont gardées par module, 24 ne le sont pas.

| Garde au niveau de la route | Nombre | Commentaire |
|---|---|---|
| Module **et** permission | 410 | Cas nominal |
| Module seul (`use-module`) | 39 | Self-service (`/profile/*`, `/workforce/me/*`) : légitime, car scopé à l'utilisateur. Quelques routes de lecture de gestion : voir R2 et R3. |
| Aucune (contexte société seul) | 24 | Tableau de bord, navigation, notifications, 2FA, temps réel, drapeaux |

## 2. Constats d'autorisation

| ID | Gravité | Constat | Preuve |
|---|---|---|---|
| **R0** | **P0** | Les widgets de tableau de bord exposent des **agrégats de toute l'entreprise** à des profils qui n'ont que des droits personnels : `timesheet_view` (« View own timesheets »), `leave_request`. | `dashboard-context-audit.md` C2. Pour le salarié : `{"not_clocked":1}`, `{"pending":1}` (HTTP 200). |
| **R1** | **P1 (confidentialité, à arbitrer)** | Le **chauffeur** lit l'annuaire complet de la société : `GET /company/people` et `/company/members` (200) ; `GET /company/people/employee-2` (fiche d'Alice, 200) avec e-mail de connexion, état du compte, rôle, indicateur administrateur, `can.view_access=true`. Il le doit à `members.view`, accordé par le bundle `members.team_view` du rôle par défaut `driver` (`JobdomainRegistry.php:253`). Les données RH restent protégées : documents 403, historique 403, `employment` vide. **Incohérence** : le manager (`workforce.view`, scopé à son équipe) reçoit **404** sur la même fiche. Le chauffeur voit donc plus de personnes que le manager. | `evidence/bob-person.json`, `evidence/http-probes.json` |
| **R10** | **P1 (cohérence des droits)** | Voir **tous** les salariés exige `workforce.view` **et** `workforce.manage` (`WorkforceAccess.php:29`, `:135`). Sans `manage`, `workforce.view` couvre soi-même et ses rapports directs. Le rôle de recette « RH (sans paie) » détient `workforce.view`, `contracts`, `compensation_manage`, `sensitive_read` et `workforce.admin`, mais **ne peut ouvrir aucune fiche de collègue** (404 sur `employee-2` et `employee-1237`). Le chauffeur, lui, lit l'annuaire via `members.view`. Le sens de « voir » dépend de combinaisons invisibles dans l'éditeur de rôles. | Recette clone : `crawl-c1-rh.json` ; `effectivePermissionKeys()` |
| **R2** | P2 | `GET /modules/{key}/settings`, `/modules/quote` et `/modules/{key}/deactivation-preview` ne demandent **aucune permission** (route : `use-module,core.modules` ; contrôleurs sans contrôle). Le chauffeur et le salarié lisent les réglages des modules, des devis (« Paie & Déclarations ») et `incomplete_profiles_count: 22`. | HTTP 200 pour les 4 profils |
| **R3** | P3 | `GET /company`, `/company/field-definitions`, `/company/field-activations` et `/company/legal-structure` : lisibles par tout membre (`use-module,core.settings`). Ce sont des données de configuration, sans donnée personnelle. | HTTP 200 |
| **R4** | P2 (produit) | Le rôle par défaut `driver` reçoit `theme.manage` (gérer le thème de l'entreprise). | `effectivePermissionKeys()` du rôle `driver` (société 1) |
| **R5** | P2 | La permission `workforce.timesheet_view`, « own », ouvre aussi l'écran **de gestion** « Suivi du temps » au salarié (entrée de menu `workforce-time`, `WorkforceModule.php:42`). L'API reste scopée (`/workforce/timesheets` → liste vide pour lui). | `evidence/nav.json` (salarié : « Time Tracking ») ; HTTP |
| **R6** | P3 | `/company/settings` déclare `meta: {}` : aucune garde de routeur, le filtrage se fait dans la page. `/home` n'a pas de `definePage`. | `pages/company/settings/index.vue:16`, `pages/home.vue` |
| **R7** | P3 | Pages de laboratoire livrées dans l'application : `/company/dev-lab` (`workforce.view`) et `/ui-lab` (sans meta). Elles sont absentes de la navigation. | `pages/company/dev-lab.vue`, `pages/ui-lab.vue` |
| **R8** | Information | `GET /workforce/employees/{id}/file` n'a pas de permission sur la route, mais il est **gardé dans le contrôleur** (`WorkforceAccess::canViewEmployeeData`). | `EmployeeController.php:168` |
| **R9** | Information | Le « niveau de gestion » se calcule de trois façons : `memberships.role = owner`, `company_roles.is_administrative`, `roleLevel` (management ou operational). Les pages `surface: 'structure'` exigent le niveau management. | `guards.js:123-129`, `NavBuilder::forCompany` |

## 3. Permissions effectives des profils testés (société 1)

| Profil | Rôle | Administratif | Permissions effectives |
|---|---|---|---|
| Propriétaire | owner | oui | toutes (contournement propriétaire) |
| Alice `admin@` | dispatcher | oui | `settings.view`, `support.*`, `theme.*`, `members.view`, `invite`, `manage`, `credentials`, `documents.view`, `manage`, `configure`, `shipments.*` |
| Manager | recette-manager | oui | `workforce.view`, `leave_*`, `planning_*`, `timesheet_view`, `timesheet_approve` |
| Salarié | recette-salarie | **non** | `workforce.leave_request`, `timesheet_view`, `timesheet_submit` |
| Chauffeur | driver | non | `settings.view`, `theme.view`, **`theme.manage`**, **`members.view`**, `shipments.manage_status`, `view_own` |

## 4. Navigation calculée par profil (`NavBuilder`, lecture seule)

| Profil | Entrées |
|---|---|
| Propriétaire | Accueil · Équipe · Planning & temps › (Planning, Suivi du temps, Congés) · Documents · Paie · Expéditions · Modules · Réglages · menu personnel (Accueil personnel, Mon espace, Mon activité, Compte) |
| Alice (dispatcher) | Accueil · Équipe · Documents · Expéditions · Réglages · menu personnel |
| Manager / RH | Accueil · Équipe · Planning & temps · (Documents pour RH) · Réglages · menu personnel |
| Salarié | Accueil (→ tableau de bord de gestion) · Suivi du temps · Mon espace · Mon activité · Compte |
| Chauffeur | Accueil · Mes livraisons · Mon espace · Mon activité · Compte |

Constat : l'entrée « Accueil » mène, selon le profil, au tableau de bord de gestion ou à l'accueil personnel. Le menu personnel affiche **en plus** « Accueil personnel » pour les profils de gestion : il y a deux accueils.

## Annexe A — Matrice exhaustive des routes API Company (générée)

473 routes portant le contexte société (`SetCompanyContext`). Chaque ligne indique la garde **au niveau de la route**. Les contrôles internes au contrôleur ou au cas d'usage (périmètre, propriétaire, `EnsureEmployeeInScope`…) ne sont pas visibles ici ; voir §2.

### Module `core.audit` (1 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/audit` | audit.view |  | `CompanyAuditLogController@index` |

### Module `core.billing` (29 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| POST | `/api/billing/checkout` | billing.manage | ThrottleRequests:60,1 | `BillingCheckoutController` |
| GET | `/api/billing/checkout/status` | billing.manage | ThrottleRequests:60,1 | `CheckoutStatusController` |
| POST | `/api/billing/confirm-setup-intent` | billing.manage | ThrottleRequests:60,1 | `CompanyPaymentSetupController@confirmSetupIntent` |
| GET | `/api/billing/consolidated-preview` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@consolidatedPreview` |
| GET | `/api/billing/invoices` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@invoices` |
| GET | `/api/billing/invoices/outstanding` | billing.manage | ThrottleRequests:60,1 | `InvoiceBatchPayController@listOutstanding` |
| POST | `/api/billing/invoices/pay` | billing.manage | ThrottleRequests:60,1 | `InvoiceBatchPayController@createPaymentIntent` |
| POST | `/api/billing/invoices/pay/confirm` | billing.manage | ThrottleRequests:60,1 | `InvoiceBatchPayController@confirmPayment` |
| GET | `/api/billing/invoices/{id}` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@invoiceDetail` |
| GET | `/api/billing/invoices/{id}/pdf` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@invoicePdf` |
| POST | `/api/billing/invoices/{id}/retry` | billing.manage | ThrottleRequests:60,1 | `CompanyPaymentMethodController@retryInvoice` |
| GET | `/api/billing/next-invoice-preview` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@nextInvoicePreview` |
| GET | `/api/billing/overview` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@overview` |
| POST | `/api/billing/pay-now` | billing.manage | ThrottleRequests:60,1 | `SubscriptionMutationController@payNow` |
| DELETE | `/api/billing/pending-subscription` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@dismissPendingSubscription` |
| POST | `/api/billing/plan-change` | billing.manage | ThrottleRequests:60,1 | `SubscriptionMutationController@planChange` |
| DELETE | `/api/billing/plan-change` | billing.manage | ThrottleRequests:60,1 | `SubscriptionMutationController@cancelPlanChange` |
| GET | `/api/billing/plan-change-preview` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@planChangePreview` |
| GET | `/api/billing/saved-cards` | billing.manage | ThrottleRequests:60,1 | `CompanyPaymentMethodController@savedCards` |
| DELETE | `/api/billing/saved-cards/{id}` | billing.manage | ThrottleRequests:60,1 | `CompanyPaymentMethodController@deleteCard` |
| PUT | `/api/billing/saved-cards/{id}/debit-day` | billing.manage | ThrottleRequests:60,1 | `CompanyPaymentMethodController@setDebitDay` |
| PUT | `/api/billing/saved-cards/{id}/default` | billing.manage | ThrottleRequests:60,1 | `CompanyPaymentMethodController@setDefault` |
| POST | `/api/billing/setup-intent` | billing.manage | ThrottleRequests:60,1 | `CompanyPaymentSetupController@createSetupIntent` |
| GET | `/api/billing/subscription` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingController@subscription` |
| PUT | `/api/billing/subscription/billing-day` | billing.manage | ThrottleRequests:60,1 | `SubscriptionMutationController@setBillingDay` |
| PUT | `/api/billing/subscription/cancel` | billing.manage | ThrottleRequests:60,1 | `SubscriptionMutationController@cancel` |
| GET | `/api/billing/subscription/cancel-preview` | billing.manage | ThrottleRequests:60,1 | `SubscriptionMutationController@cancelPreview` |
| GET | `/api/billing/timeline` | billing.manage | ThrottleRequests:60,1 | `CompanyBillingTimelineController` |
| PUT | `/api/company/plan` | billing.manage | ThrottleRequests:60,1 | `CompanyPlanController@update` |

### Module `core.documents` (71 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/company/ai-quota/{moduleKey}` | documents.view |  | `AiQuotaController@show` |
| GET | `/api/company/document-activations` | documents.view |  | `CompanyDocumentActivationController@index` |
| PUT | `/api/company/document-activations/{code}` | documents.configure |  | `CompanyDocumentActivationController@upsert` |
| GET | `/api/company/document-campaigns` | documents.manage |  | `DocumentCampaignController@index` |
| POST | `/api/company/document-campaigns` | documents.manage |  | `DocumentCampaignController@store` |
| POST | `/api/company/document-campaigns/preview` | documents.manage |  | `DocumentCampaignController@preview` |
| GET | `/api/company/document-campaigns/{id}` | documents.manage |  | `DocumentCampaignController@show` |
| PUT | `/api/company/document-campaigns/{id}/cancel` | documents.manage |  | `DocumentCampaignController@cancel` |
| POST | `/api/company/document-campaigns/{id}/launch` | documents.manage |  | `DocumentCampaignController@launch` |
| GET | `/api/company/document-campaigns/{id}/recipients` | documents.manage |  | `DocumentCampaignController@recipients` |
| POST | `/api/company/document-requests` | documents.manage |  | `DocumentRequestController@store` |
| POST | `/api/company/document-requests/batch` | documents.manage |  | `DocumentRequestController@batchByRole` |
| POST | `/api/company/document-requests/bulk-action` | documents.manage |  | `DocumentRequestController@bulkAction` |
| GET | `/api/company/document-requests/queue` | documents.manage |  | `DocumentRequestController@queue` |
| GET | `/api/company/document-requests/roles` | documents.manage |  | `DocumentRequestController@batchRoles` |
| PUT | `/api/company/document-requests/{id}/cancel` | documents.manage |  | `DocumentRequestController@cancel` |
| PUT | `/api/company/document-requests/{id}/remind` | documents.manage |  | `DocumentRequestController@remind` |
| GET | `/api/company/document-settings` | documents.configure |  | `DocumentSettingController@show` |
| PUT | `/api/company/document-settings` | documents.configure |  | `DocumentSettingController@update` |
| POST | `/api/company/document-types/custom` | documents.configure |  | `CustomDocumentTypeController@store` |
| PUT | `/api/company/document-types/custom/{code}` | documents.configure |  | `CustomDocumentTypeController@update` |
| DELETE | `/api/company/document-types/custom/{code}` | documents.configure |  | `CustomDocumentTypeController@destroy` |
| PUT | `/api/company/document-types/custom/{code}/archive` | documents.configure |  | `CustomDocumentTypeController@archive` |
| GET | `/api/company/documents` | documents.view |  | `CompanyDocumentController@index` |
| GET | `/api/company/documents/activity` | documents.view |  | `DocumentComplianceController@activity` |
| GET | `/api/company/documents/compliance` | documents.view |  | `DocumentComplianceController@index` |
| GET | `/api/company/documents/compliance/export` | documents.manage |  | `DocumentComplianceController@exportCsv` |
| GET | `/api/company/documents/search` | documents.view|workforce_documents.view |  | `DocumentSearchController` |
| POST | `/api/company/documents/{code}` | documents.manage |  | `CompanyDocumentController@upload` |
| DELETE | `/api/company/documents/{code}` | documents.manage |  | `CompanyDocumentController@destroy` |
| GET | `/api/company/documents/{code}/download` | documents.view |  | `CompanyDocumentController@download` |
| PUT | `/api/company/documents/{code}/review` | documents.manage |  | `CompanyDocumentController@review` |
| GET | `/api/company/documents/{code}/versions` | documents.view |  | `CompanyDocumentController@versions` |
| GET | `/api/company/documents/{code}/versions/{versionId}/download` | documents.view |  | `CompanyDocumentController@downloadVersion` |
| GET | `/api/company/members/{id}/documents` | documents.view |  | `MemberDocumentController@index` |
| POST | `/api/company/members/{id}/documents/{code}` | documents.manage |  | `MemberDocumentController@upload` |
| DELETE | `/api/company/members/{id}/documents/{code}` | documents.manage |  | `MemberDocumentController@destroy` |
| POST | `/api/company/members/{id}/documents/{code}/apply-suggestions` | documents.manage |  | `MemberDocumentAiSuggestionController@apply` |
| GET | `/api/company/members/{id}/documents/{code}/download` | documents.view |  | `MemberDocumentController@download` |
| POST | `/api/company/members/{id}/documents/{code}/retry-ai` | documents.manage |  | `MemberDocumentController@retryAi` |
| PUT | `/api/company/members/{id}/documents/{code}/review` | documents.manage |  | `MemberDocumentController@review` |
| GET | `/api/company/members/{id}/documents/{code}/versions` | documents.view |  | `MemberDocumentController@versions` |
| GET | `/api/company/members/{id}/documents/{code}/versions/{versionId}/download` | documents.view |  | `MemberDocumentController@downloadVersion` |
| POST | `/api/company/payslip-imports` | payslips.import |  | `PayslipImportController@store` |
| GET | `/api/company/payslip-imports` | payslips.import|payslips.review |  | `PayslipImportController@index` |
| GET | `/api/company/payslip-imports/{id}` | payslips.review |  | `PayslipImportController@show` |
| GET | `/api/company/payslip-imports/{id}/items` | payslips.review |  | `PayslipImportController@items` |
| PUT | `/api/company/payslip-imports/{id}/items/{itemId}/assign` | payslips.review |  | `PayslipImportController@assign` |
| PUT | `/api/company/payslip-imports/{id}/items/{itemId}/exclude` | payslips.review |  | `PayslipImportController@exclude` |
| PUT | `/api/company/payslip-imports/{id}/items/{itemId}/period` | payslips.review |  | `PayslipImportController@correctPeriod` |
| GET | `/api/company/payslip-imports/{id}/items/{itemId}/preview` | payslips.review |  | `PayslipImportController@previewItem` |
| PUT | `/api/company/payslip-imports/{id}/items/{itemId}/split` | payslips.review |  | `PayslipImportController@split` |
| PUT | `/api/company/payslip-imports/{id}/pages/{pageId}/exclude` | payslips.review |  | `PayslipImportController@excludePage` |
| POST | `/api/company/payslip-imports/{id}/publish` | payslips.publish |  | `PayslipImportController@publish` |
| PUT | `/api/company/payslip-imports/{id}/regroup` | payslips.review |  | `PayslipImportController@regroup` |
| GET | `/api/company/people/{personRef}/documents` | documents.view |  | `PersonDocumentController@index` |
| POST | `/api/company/people/{personRef}/documents/{code}` | documents.manage |  | `PersonDocumentController@upload` |
| GET | `/api/company/people/{personRef}/documents/{code}/download` | documents.view |  | `PersonDocumentController@download` |
| PUT | `/api/company/people/{personRef}/documents/{code}/review` | documents.manage |  | `PersonDocumentReviewController@review` |
| GET | `/api/company/people/{personRef}/documents/{code}/versions` | documents.view |  | `PersonDocumentController@versions` |
| GET | `/api/company/people/{personRef}/documents/{code}/versions/{versionId}/download` | documents.view |  | `PersonDocumentController@downloadVersion` |
| GET | `/api/company/people/{personRef}/payslips` | documents.view|payslips.download|workforce.compensation_read|workforce.payroll_validate|workforce.payroll_prepare |  | `PublishedPayslipController@person` |
| GET | `/api/company/people/{personRef}/payslips/{id}/download` | documents.view|payslips.download|workforce.compensation_read|workforce.payroll_validate|workforce.payroll_prepare |  | `PublishedPayslipController@downloadPerson` |
| PUT | `/api/company/published-payslips/{payslipId}/revoke` | payslips.publish |  | `PayslipImportController@revoke` |
| GET | `/api/profile/documents` | — |  | `SelfDocumentController@index` |
| POST | `/api/profile/documents/{code}` | — |  | `SelfDocumentController@upload` |
| GET | `/api/profile/documents/{code}/download` | — |  | `SelfDocumentController@download` |
| GET | `/api/profile/documents/{code}/versions` | — |  | `SelfDocumentController@versions` |
| GET | `/api/profile/documents/{code}/versions/{versionId}/download` | — |  | `SelfDocumentController@downloadVersion` |
| GET | `/api/profile/payslips` | — |  | `PublishedPayslipController@self` |
| GET | `/api/profile/payslips/{id}/download` | — |  | `PublishedPayslipController@downloadSelf` |

### Module `core.jobdomain` (2 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/company/jobdomain` | jobdomain.view |  | `CompanyJobdomainController@show` |
| PUT | `/api/company/jobdomain` | jobdomain.manage |  | `CompanyJobdomainController@update` |

### Module `core.members` (22 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/company/members` | members.view|documents.manage|shipments.assign |  | `MembershipController@index` |
| POST | `/api/company/members` | members.invite |  | `MembershipController@store` |
| GET | `/api/company/members/{id}` | members.view |  | `MembershipController@show` |
| PUT | `/api/company/members/{id}` | members.manage |  | `MembershipController@update` |
| DELETE | `/api/company/members/{id}` | members.manage |  | `MembershipController@destroy` |
| GET | `/api/company/members/{id}/fields` | members.view |  | `MembershipController@fields` |
| PUT | `/api/company/members/{id}/password` | members.credentials |  | `MemberCredentialController@setPassword` |
| POST | `/api/company/members/{id}/resend-invitation` | members.invite |  | `MemberAccessController@resendInvitation` |
| POST | `/api/company/members/{id}/reset-password` | members.credentials |  | `MemberCredentialController@resetPassword` |
| POST | `/api/company/members/{id}/restore` | members.manage |  | `MemberAccessController@restore` |
| POST | `/api/company/members/{id}/suspend` | members.manage |  | `MemberAccessController@suspend` |
| GET | `/api/company/people` | members.view|workforce.view |  | `PersonController@index` |
| GET | `/api/company/people/resolve` | members.view|workforce.view |  | `PersonController@resolve` |
| GET | `/api/company/people/{personRef}` | members.view|workforce.view |  | `PersonController@show` |
| GET | `/api/company/people/{personRef}/history` | members.view|workforce.view |  | `PersonController@history` |
| GET | `/api/profile` | — |  | `UserProfileController@show` |
| PUT | `/api/profile` | — |  | `UserProfileController@update` |
| POST | `/api/profile/avatar` | — |  | `UserProfileController@updateAvatar` |
| POST | `/api/profile/disclosures` | — | ThrottleRequests:20,1 | `UserProfileController@shareFields` |
| POST | `/api/profile/disclosures/withdrawal` | — | ThrottleRequests:10,1 | `UserProfileController@requestWithdrawal` |
| PUT | `/api/profile/password` | — |  | `UserProfileController@updatePassword` |
| GET | `/api/profile/space` | — |  | `PersonalSpaceController@show` |

### Module `core.modules` (13 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/modules` | — |  | `CompanyModuleController@index` |
| GET | `/api/modules/quote` | — |  | `ModuleQuoteController` |
| GET | `/api/modules/{key}/deactivation-impact` | modules.manage |  | `ModuleSetupController@deactivationImpact` |
| GET | `/api/modules/{key}/deactivation-preview` | — |  | `ModuleDeactivationPreviewController` |
| PUT | `/api/modules/{key}/disable` | modules.manage |  | `CompanyModuleController@disable` |
| PUT | `/api/modules/{key}/enable` | modules.manage |  | `CompanyModuleController@enable` |
| GET | `/api/modules/{key}/settings` | — |  | `CompanyModuleController@getSettings` |
| PUT | `/api/modules/{key}/settings` | modules.manage |  | `CompanyModuleController@updateSettings` |
| GET | `/api/modules/{key}/setup` | modules.manage |  | `ModuleSetupController@show` |
| PUT | `/api/modules/{key}/setup/access` | modules.manage / roles.manage |  | `ModuleSetupController@applyAccess` |
| POST | `/api/modules/{key}/setup/access/preview` | modules.manage |  | `ModuleSetupController@preview` |
| POST | `/api/modules/{key}/setup/confirm` | modules.manage |  | `ModuleSetupController@confirm` |
| POST | `/api/modules/{key}/setup/steps` | modules.manage |  | `ModuleSetupController@step` |

### Module `core.roles` (12 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/company/pending-access` | roles.view |  | `PendingAccessController@index` |
| POST | `/api/company/pending-access/confirm` | roles.manage |  | `PendingAccessController@confirm` |
| GET | `/api/company/permissions` | roles.view |  | `CompanyRoleController@permissionCatalog` |
| GET | `/api/company/role-capabilities` | roles.view |  | `CompanyRoleCapabilityController@modules` |
| GET | `/api/company/role-capabilities/{module}` | roles.view |  | `CompanyRoleCapabilityController@show` |
| PUT | `/api/company/role-capabilities/{module}` | roles.manage |  | `CompanyRoleCapabilityController@apply` |
| POST | `/api/company/role-capabilities/{module}/preview` | roles.manage |  | `CompanyRoleCapabilityController@preview` |
| GET | `/api/company/roles` | roles.view |  | `CompanyRoleController@index` |
| POST | `/api/company/roles` | roles.manage |  | `CompanyRoleController@store` |
| PUT | `/api/company/roles/{id}` | roles.manage |  | `CompanyRoleController@update` |
| DELETE | `/api/company/roles/{id}` | roles.manage |  | `CompanyRoleController@destroy` |
| POST | `/api/company/roles/{id}/confirm-pending` | roles.manage |  | `CompanyRoleController@confirmPending` |

### Module `core.settings` (10 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/company` | — |  | `CompanyController@show` |
| PUT | `/api/company` | settings.manage |  | `CompanyController@update` |
| GET | `/api/company/field-activations` | — |  | `CompanyFieldActivationController@index` |
| POST | `/api/company/field-activations` | settings.manage |  | `CompanyFieldActivationController@upsert` |
| GET | `/api/company/field-definitions` | — |  | `CompanyFieldDefinitionController@index` |
| POST | `/api/company/field-definitions` | settings.manage |  | `CompanyFieldDefinitionController@store` |
| PUT | `/api/company/field-definitions/{id}` | settings.manage |  | `CompanyFieldDefinitionController@update` |
| DELETE | `/api/company/field-definitions/{id}` | settings.manage |  | `CompanyFieldDefinitionController@destroy` |
| GET | `/api/company/legal-structure` | — |  | `CompanyLegalStructureController@show` |
| PUT | `/api/company/legal-structure` | settings.manage |  | `CompanyLegalStructureController@updateLegalStatus` |

### Module `core.support` (5 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/support/tickets` | support.view |  | `SupportTicketController@index` |
| POST | `/api/support/tickets` | support.create |  | `SupportTicketController@store` |
| GET | `/api/support/tickets/{id}` | support.view |  | `SupportTicketController@show` |
| GET | `/api/support/tickets/{id}/messages` | support.view |  | `SupportMessageController@index` |
| POST | `/api/support/tickets/{id}/messages` | support.create |  | `SupportMessageController@store` |

### Module `core.theme` (3 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| PUT | `/api/theme-preference` | theme.manage |  | `ThemePreferenceController@update` |
| GET | `/api/theme/role-visibility` | manage-structure |  | `ThemeRoleVisibilityController@index` |
| PUT | `/api/theme/role-visibility` | roles.manage |  | `ThemeRoleVisibilityController@update` |

### Module `core.workflows` (7 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/workflows` | automations.view |  | `WorkflowRuleController@index` |
| POST | `/api/workflows` | automations.manage |  | `WorkflowRuleController@store` |
| GET | `/api/workflows/triggers` | automations.view |  | `WorkflowRuleController@triggers` |
| GET | `/api/workflows/{workflowRule}` | automations.view |  | `WorkflowRuleController@show` |
| PUT | `/api/workflows/{workflowRule}` | automations.manage |  | `WorkflowRuleController@update` |
| DELETE | `/api/workflows/{workflowRule}` | automations.manage |  | `WorkflowRuleController@destroy` |
| GET | `/api/workflows/{workflowRule}/logs` | automations.view |  | `WorkflowRuleController@logs` |

### Module `logistics_shipments` (8 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/my-deliveries` | shipments.view_own |  | `MyDeliveryController@index` |
| GET | `/api/my-deliveries/{id}` | shipments.view_own |  | `MyDeliveryController@show` |
| PUT | `/api/my-deliveries/{id}/status` | shipments.view_own / shipments.manage_status |  | `MyDeliveryController@updateStatus` |
| GET | `/api/shipments` | shipments.view |  | `ShipmentController@index` |
| POST | `/api/shipments` | shipments.create |  | `ShipmentController@store` |
| GET | `/api/shipments/{id}` | shipments.view |  | `ShipmentController@show` |
| POST | `/api/shipments/{id}/assign` | shipments.view / shipments.assign |  | `ShipmentController@assign` |
| PUT | `/api/shipments/{id}/status` | shipments.view / shipments.manage_status |  | `ShipmentController@changeStatus` |

### Module `workforce` (132 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/workforce/config/breaks` | workforce.view |  | `WorkforceConfigController@breaks` |
| PUT | `/api/workforce/config/breaks/{rule}` | workforce.manage |  | `WorkforceConfigController@setBreak` |
| GET | `/api/workforce/config/week-start` | workforce.view |  | `WorkforceConfigController@weekStart` |
| PUT | `/api/workforce/config/week-start` | workforce.manage |  | `WorkforceConfigController@setWeekStart` |
| GET | `/api/workforce/config/work-time` | workforce.view |  | `WorkforceConfigController@workTime` |
| PUT | `/api/workforce/config/work-time/{rule}` | workforce.manage |  | `WorkforceConfigController@setWorkTime` |
| GET | `/api/workforce/conventions` | workforce.view |  | `ContractController@conventions` |
| GET | `/api/workforce/dashboard/priorities` | workforce.view |  | `WorkforceDashboardController@priorities` |
| GET | `/api/workforce/dashboard/statistics` | workforce.view |  | `WorkforceDashboardController@statistics` |
| GET | `/api/workforce/departments` | workforce.view |  | `DepartmentController@index` |
| POST | `/api/workforce/departments` | workforce.manage |  | `DepartmentController@store` |
| PUT | `/api/workforce/departments/{id}` | workforce.manage |  | `DepartmentController@update` |
| DELETE | `/api/workforce/departments/{id}` | workforce.manage |  | `DepartmentController@destroy` |
| GET | `/api/workforce/employees` | workforce.view |  | `EmployeeController@index` |
| POST | `/api/workforce/employees` | workforce.manage |  | `EmployeeController@store` |
| POST | `/api/workforce/employees/bulk` | workforce.manage |  | `EmployeeController@bulk` |
| POST | `/api/workforce/employees/bulk/preview` | workforce.manage |  | `EmployeeController@bulkPreview` |
| GET | `/api/workforce/employees/export` | workforce.export |  | `EmployeeController@export` |
| POST | `/api/workforce/employees/from-member` | workforce.manage / members.view |  | `EmployeeAccountLinkController@createForMember` |
| POST | `/api/workforce/employees/import` | workforce.import |  | `EmployeeController@import` |
| POST | `/api/workforce/employees/import/preview` | workforce.import |  | `EmployeeController@importPreview` |
| GET | `/api/workforce/employees/import/template` | workforce.import |  | `EmployeeController@importTemplate` |
| GET | `/api/workforce/employees/link-candidates` | workforce.manage / members.view |  | `EmployeeAccountLinkController@candidates` |
| GET | `/api/workforce/employees/summary` | workforce.view |  | `EmployeeController@summary` |
| GET | `/api/workforce/employees/{employeeId}/contracts` | workforce.view | EnsureEmployeeInScope | `ContractController@index` |
| POST | `/api/workforce/employees/{employeeId}/contracts` | workforce.contracts |  | `ContractController@store` |
| POST | `/api/workforce/employees/{employeeId}/contracts/{contractId}/activate` | workforce.contracts |  | `ContractController@activate` |
| POST | `/api/workforce/employees/{employeeId}/contracts/{contractId}/cancel` | workforce.contracts |  | `ContractController@cancel` |
| GET | `/api/workforce/employees/{employeeId}/leave-balances` | workforce.leave_request |  | `LeaveController@balances` |
| GET | `/api/workforce/employees/{id}` | workforce.view | EnsureEmployeeInScope | `EmployeeController@show` |
| PUT | `/api/workforce/employees/{id}` | workforce.manage |  | `EmployeeController@update` |
| GET | `/api/workforce/employees/{id}/access` | members.view|members.invite|members.manage |  | `EmployeeController@accessStatus` |
| POST | `/api/workforce/employees/{id}/access` | members.invite |  | `EmployeeController@grantAccess` |
| POST | `/api/workforce/employees/{id}/access/resend` | members.invite |  | `EmployeeController@resendAccess` |
| POST | `/api/workforce/employees/{id}/access/restore` | members.manage |  | `EmployeeController@restoreAccess` |
| POST | `/api/workforce/employees/{id}/access/revoke` | members.manage |  | `EmployeeController@revokeAccess` |
| POST | `/api/workforce/employees/{id}/access/suspend` | members.manage |  | `EmployeeController@suspendAccess` |
| GET | `/api/workforce/employees/{id}/fields` | workforce.view | EnsureEmployeeInScope | `EmployeeFieldController@show` |
| PUT | `/api/workforce/employees/{id}/fields` | workforce.manage |  | `EmployeeFieldController@update` |
| GET | `/api/workforce/employees/{id}/file` | — |  | `EmployeeController@file` |
| POST | `/api/workforce/employees/{id}/link-account` | workforce.manage / members.manage |  | `EmployeeAccountLinkController@linkAccount` |
| POST | `/api/workforce/employees/{id}/terminate` | workforce.manage |  | `EmployeeController@terminate` |
| GET | `/api/workforce/job-roles` | workforce.view |  | `JobRoleController@index` |
| POST | `/api/workforce/job-roles` | workforce.manage |  | `JobRoleController@store` |
| PUT | `/api/workforce/job-roles/{id}` | workforce.manage |  | `JobRoleController@update` |
| DELETE | `/api/workforce/job-roles/{id}` | workforce.manage |  | `JobRoleController@destroy` |
| GET | `/api/workforce/leaves` | workforce.leave_request |  | `LeaveController@index` |
| POST | `/api/workforce/leaves` | workforce.leave_request |  | `LeaveController@store` |
| GET | `/api/workforce/leaves/calendar` | workforce.leave_request |  | `LeaveController@calendar` |
| POST | `/api/workforce/leaves/deduction-preview` | workforce.leave_request |  | `LeaveController@deductionPreview` |
| GET | `/api/workforce/leaves/statistics` | workforce.leave_request |  | `LeaveController@statistics` |
| GET | `/api/workforce/leaves/types` | workforce.leave_request |  | `LeaveTypeController@index` |
| POST | `/api/workforce/leaves/types` | workforce.leave_manage |  | `LeaveTypeController@store` |
| PUT | `/api/workforce/leaves/types/{id}` | workforce.leave_manage |  | `LeaveTypeController@update` |
| DELETE | `/api/workforce/leaves/types/{id}` | workforce.leave_manage |  | `LeaveTypeController@destroy` |
| GET | `/api/workforce/leaves/{id}` | workforce.leave_request |  | `LeaveController@show` |
| POST | `/api/workforce/leaves/{id}/approve` | workforce.leave_approve |  | `LeaveController@approve` |
| POST | `/api/workforce/leaves/{id}/cancel` | workforce.leave_approve |  | `LeaveController@cancel` |
| GET | `/api/workforce/leaves/{id}/impacted-shifts` | workforce.leave_approve |  | `LeaveController@impactedShifts` |
| POST | `/api/workforce/leaves/{id}/reject` | workforce.leave_approve |  | `LeaveController@reject` |
| GET | `/api/workforce/me` | — |  | `EmployeeSelfController@profile` |
| POST | `/api/workforce/me/break/end` | — |  | `EmployeeSelfController@endBreak` |
| POST | `/api/workforce/me/break/start` | — |  | `EmployeeSelfController@startBreak` |
| POST | `/api/workforce/me/clock-in` | — |  | `EmployeeSelfController@clockIn` |
| POST | `/api/workforce/me/clock-out` | — |  | `EmployeeSelfController@clockOut` |
| GET | `/api/workforce/me/documents` | — |  | `EmployeeSelfController@documents` |
| GET | `/api/workforce/me/generated-documents/{id}/download` | — |  | `EmployeeGeneratedDocumentController@downloadOwn` |
| GET | `/api/workforce/me/hours` | — |  | `EmployeeSelfHoursController` |
| GET | `/api/workforce/me/leave-balances` | — |  | `EmployeeSelfController@leaveBalances` |
| GET | `/api/workforce/me/leaves` | — |  | `EmployeeSelfController@leaves` |
| POST | `/api/workforce/me/leaves` | — |  | `EmployeeSelfController@createLeave` |
| POST | `/api/workforce/me/leaves/deduction-preview` | — |  | `EmployeeSelfController@leaveDeductionPreview` |
| GET | `/api/workforce/me/shifts` | — |  | `EmployeeSelfController@shifts` |
| GET | `/api/workforce/me/today` | — |  | `EmployeeSelfController@today` |
| GET | `/api/workforce/onboarding` | workforce.view |  | `WorkforceOnboardingController@show` |
| GET | `/api/workforce/onboarding-files` | workforce.manage |  | `OnboardingController@index` |
| POST | `/api/workforce/onboarding-files` | workforce.manage | ThrottleRequests:30,1 | `OnboardingController@store` |
| GET | `/api/workforce/onboarding-files/proposal` | workforce.manage |  | `OnboardingController@proposal` |
| GET | `/api/workforce/onboarding-files/{id}` | workforce.manage |  | `OnboardingController@show` |
| PUT | `/api/workforce/onboarding-files/{id}/contact` | workforce.manage | ThrottleRequests:20,1 | `OnboardingController@contact` |
| PUT | `/api/workforce/onboarding-files/{id}/items` | workforce.manage |  | `OnboardingController@revise` |
| POST | `/api/workforce/onboarding-files/{id}/items/preview` | workforce.manage |  | `OnboardingController@previewRevision` |
| POST | `/api/workforce/onboarding-files/{id}/link` | workforce.manage | ThrottleRequests:20,1 | `OnboardingController@link` |
| POST | `/api/workforce/onboarding-files/{id}/request-changes` | workforce.manage |  | `OnboardingController@requestChanges` |
| POST | `/api/workforce/onboarding-files/{id}/revoke` | workforce.manage |  | `OnboardingController@revoke` |
| POST | `/api/workforce/onboarding-files/{id}/send` | workforce.manage | ThrottleRequests:10,1 | `OnboardingController@send` |
| POST | `/api/workforce/onboarding-files/{id}/uploads/{upload}/rescan` | workforce.manage | ThrottleRequests:10,1 | `OnboardingController@rescan` |
| POST | `/api/workforce/onboarding-files/{id}/validate` | workforce.manage |  | `OnboardingController@validateFile` |
| POST | `/api/workforce/onboarding/defer` | workforce.manage |  | `WorkforceOnboardingController@deferStep` |
| POST | `/api/workforce/onboarding/finish` | workforce.manage |  | `WorkforceOnboardingController@finish` |
| POST | `/api/workforce/onboarding/start` | workforce.manage |  | `WorkforceOnboardingController@start` |
| POST | `/api/workforce/onboarding/step` | workforce.manage |  | `WorkforceOnboardingController@completeStep` |
| POST | `/api/workforce/organization/preset/apply` | workforce.manage |  | `OrganizationPresetController@apply` |
| GET | `/api/workforce/organization/preset/preview` | workforce.view |  | `OrganizationPresetController@preview` |
| GET | `/api/workforce/organization/profile` | workforce.view |  | `OrganizationPresetController@profile` |
| PUT | `/api/workforce/organization/profile` | workforce.manage |  | `OrganizationPresetController@setProfile` |
| GET | `/api/workforce/requirements` | settings.manage|documents.configure |  | `RequirementConfigController@index` |
| POST | `/api/workforce/requirements` | settings.manage|documents.configure |  | `RequirementConfigController@save` |
| GET | `/api/workforce/requirements/catalog` | settings.manage|documents.configure |  | `RequirementConfigController@catalog` |
| GET | `/api/workforce/requirements/legacy` | settings.manage|documents.configure |  | `RequirementConfigController@legacy` |
| GET | `/api/workforce/requirements/modules` | settings.manage|documents.configure |  | `ModuleContributionController@index` |
| POST | `/api/workforce/requirements/modules/{module}/decisions` | settings.manage|documents.configure |  | `ModuleContributionController@decide` |
| POST | `/api/workforce/requirements/modules/{module}/preview` | settings.manage|documents.configure |  | `ModuleContributionController@preview` |
| POST | `/api/workforce/requirements/preview` | settings.manage|documents.configure |  | `RequirementConfigController@preview` |
| GET | `/api/workforce/settings` | workforce.view |  | `WorkforceSettingsController@index` |
| GET | `/api/workforce/time-entries` | workforce.timesheet_view |  | `TimeEntryController@index` |
| POST | `/api/workforce/time-entries` | workforce.time_manage |  | `TimeEntryController@store` |
| GET | `/api/workforce/time-entries/active` | workforce.timesheet_view |  | `TimeEntryController@active` |
| GET | `/api/workforce/time-entries/anomalies` | workforce.timesheet_view |  | `TimeEntryController@anomalies` |
| POST | `/api/workforce/time-entries/clock-in` | workforce.time_manage |  | `TimeEntryController@clockIn` |
| GET | `/api/workforce/time-entries/cockpit/board` | workforce.timesheet_view |  | `TimeEntryController@cockpitBoard` |
| GET | `/api/workforce/time-entries/cockpit/summary` | workforce.timesheet_view |  | `TimeEntryController@cockpitSummary` |
| GET | `/api/workforce/time-entries/company` | workforce.timesheet_view |  | `TimeEntryController@companyIndex` |
| PUT | `/api/workforce/time-entries/{id}` | workforce.time_manage |  | `TimeEntryController@update` |
| DELETE | `/api/workforce/time-entries/{id}` | workforce.time_manage |  | `TimeEntryController@destroy` |
| POST | `/api/workforce/time-entries/{id}/break/end` | workforce.time_manage |  | `TimeEntryController@endBreak` |
| POST | `/api/workforce/time-entries/{id}/break/start` | workforce.time_manage |  | `TimeEntryController@startBreak` |
| POST | `/api/workforce/time-entries/{id}/clock-out` | workforce.time_manage |  | `TimeEntryController@clockOut` |
| GET | `/api/workforce/timesheets` | workforce.timesheet_view |  | `TimesheetController@index` |
| POST | `/api/workforce/timesheets` | workforce.time_manage |  | `TimesheetController@generate` |
| GET | `/api/workforce/timesheets/statistics` | workforce.timesheet_view |  | `TimesheetController@statistics` |
| GET | `/api/workforce/timesheets/{id}` | workforce.timesheet_view |  | `TimesheetController@show` |
| POST | `/api/workforce/timesheets/{id}/approve` | workforce.timesheet_approve|workforce.timesheet_approve_team |  | `TimesheetController@approve` |
| GET | `/api/workforce/timesheets/{id}/compliance` | workforce.timesheet_view |  | `TimesheetController@compliance` |
| GET | `/api/workforce/timesheets/{id}/history` | workforce.timesheet_view |  | `TimesheetController@history` |
| POST | `/api/workforce/timesheets/{id}/lock` | workforce.time_approve |  | `TimesheetController@lock` |
| POST | `/api/workforce/timesheets/{id}/reject` | workforce.timesheet_approve|workforce.timesheet_approve_team |  | `TimesheetController@reject` |
| POST | `/api/workforce/timesheets/{id}/reopen` | workforce.time_manage |  | `TimesheetController@reopen` |
| POST | `/api/workforce/timesheets/{id}/submit` | workforce.timesheet_submit |  | `TimesheetController@submit` |
| GET | `/api/workforce/working-time-setup` | workforce.view |  | `WorkingTimeSetupController@show` |
| PUT | `/api/workforce/working-time-setup` | workforce.manage |  | `WorkingTimeSetupController@save` |
| POST | `/api/workforce/working-time-setup/convention` | workforce.manage |  | `WorkingTimeSetupController@confirmConvention` |

### Module `workforce_contract` (12 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| POST | `/api/workforce/contract/generate` | workforce_contract.generate |  | `ContractController@generate` |
| POST | `/api/workforce/contract/preview` | workforce_contract.generate |  | `ContractController@preview` |
| POST | `/api/workforce/contract/preview-pdf` | workforce_contract.generate |  | `ContractController@previewPdf` |
| GET | `/api/workforce/contract/templates` | workforce_contract.view |  | `ContractController@templates` |
| GET | `/api/workforce/contract/templates/variables` | workforce_contract.view |  | `ContractController@variables` |
| GET | `/api/workforce/contract/templates/{id}` | workforce_contract.view |  | `ContractController@showTemplate` |
| PUT | `/api/workforce/contract/templates/{id}` | workforce_contract.manage_templates |  | `ContractController@updateTemplate` |
| POST | `/api/workforce/contract/templates/{id}/archive` | workforce_contract.manage_templates |  | `ContractController@archive` |
| POST | `/api/workforce/contract/templates/{id}/default` | workforce_contract.manage_templates |  | `ContractController@setDefault` |
| POST | `/api/workforce/contract/templates/{id}/duplicate` | workforce_contract.manage_templates |  | `ContractController@duplicate` |
| POST | `/api/workforce/contract/templates/{id}/publish` | workforce_contract.manage_templates |  | `ContractController@publish` |
| POST | `/api/workforce/contract/templates/{id}/version` | workforce_contract.manage_templates |  | `ContractController@createVersion` |

### Module `workforce_documents` (11 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/workforce/documents` | workforce_documents.view |  | `WorkforceDocumentController@index` |
| GET | `/api/workforce/documents/by-employee/{employeeId}` | workforce_documents.view | EnsureEmployeeInScope | `EmployeeGeneratedDocumentController@byEmployee` |
| POST | `/api/workforce/documents/generate` | workforce_documents.generate |  | `WorkforceDocumentController@generate` |
| GET | `/api/workforce/documents/payroll-run/{runId}` | workforce_documents.view |  | `WorkforceDocumentController@forPayrollRun` |
| POST | `/api/workforce/documents/payslip-drafts` | workforce_documents.generate |  | `WorkforceDocumentController@generatePayslipDrafts` |
| POST | `/api/workforce/documents/payslip-official` | workforce_documents.generate |  | `WorkforceDocumentController@generateOfficialPayslips` |
| GET | `/api/workforce/documents/statistics` | workforce_documents.view |  | `WorkforceDocumentController@statistics` |
| GET | `/api/workforce/documents/templates` | workforce_documents.view |  | `WorkforceDocumentController@templates` |
| GET | `/api/workforce/documents/{id}` | workforce_documents.view |  | `WorkforceDocumentController@show` |
| GET | `/api/workforce/documents/{id}/download` | workforce_documents.view |  | `WorkforceDocumentController@download` |
| GET | `/api/workforce/documents/{id}/signed` | workforce_documents.view | EnsureCanOpenGeneratedDocument | `EmployeeGeneratedDocumentController@signed` |

### Module `workforce_payroll` (23 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/workforce/dsn` | workforce.payroll_export |  | `DsnDeclarationController@index` |
| GET | `/api/workforce/dsn/capability` | workforce.payroll_prepare |  | `DsnDeclarationController@capability` |
| POST | `/api/workforce/dsn/export` | workforce.payroll_export |  | `DsnDeclarationController@export` |
| GET | `/api/workforce/dsn/statistics` | workforce.payroll_export |  | `DsnDeclarationController@statistics` |
| GET | `/api/workforce/dsn/{id}` | workforce.payroll_export |  | `DsnDeclarationController@show` |
| GET | `/api/workforce/dsn/{id}/history` | workforce.payroll_export |  | `DsnDeclarationController@history` |
| POST | `/api/workforce/dsn/{id}/poll` | workforce.payroll_export |  | `DsnDeclarationController@poll` |
| POST | `/api/workforce/dsn/{id}/submit` | workforce.payroll_export |  | `DsnDeclarationController@submit` |
| GET | `/api/workforce/dsn/{id}/validation` | workforce.payroll_export |  | `DsnDeclarationController@validationErrors` |
| GET | `/api/workforce/payroll` | workforce.payroll_prepare |  | `PayrollRunController@index` |
| POST | `/api/workforce/payroll` | workforce.payroll_prepare |  | `PayrollRunController@store` |
| GET | `/api/workforce/payroll/statistics` | workforce.payroll_prepare |  | `PayrollRunController@statistics` |
| GET | `/api/workforce/payroll/{id}` | workforce.payroll_prepare |  | `PayrollRunController@show` |
| DELETE | `/api/workforce/payroll/{id}` | workforce.payroll_prepare |  | `PayrollRunController@destroy` |
| GET | `/api/workforce/payroll/{id}/calculations` | workforce.payroll_prepare |  | `PayrollRunController@calculations` |
| POST | `/api/workforce/payroll/{id}/compute` | workforce.payroll_prepare |  | `PayrollRunController@compute` |
| POST | `/api/workforce/payroll/{id}/compute-calculations` | workforce.payroll_prepare |  | `PayrollRunController@computeCalculations` |
| GET | `/api/workforce/payroll/{id}/documents` | workforce.payroll_prepare |  | `PayrollRunController@documents` |
| POST | `/api/workforce/payroll/{id}/export` | workforce.payroll_export |  | `PayrollRunController@export` |
| GET | `/api/workforce/payroll/{id}/lines` | workforce.payroll_prepare |  | `PayrollRunController@lines` |
| GET | `/api/workforce/payroll/{id}/lines/{lineId}` | workforce.payroll_prepare |  | `PayrollRunController@showLine` |
| POST | `/api/workforce/payroll/{id}/recompute` | workforce.payroll_prepare |  | `PayrollRunController@recompute` |
| POST | `/api/workforce/payroll/{id}/validate` | workforce.payroll_validate |  | `PayrollRunController@validate` |

### Module `workforce_planning` (67 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/workforce/planning/activities` | workforce.planning_view |  | `ScheduledActivityController@index` |
| POST | `/api/workforce/planning/activities` | workforce.planning_manage |  | `ScheduledActivityController@store` |
| PUT | `/api/workforce/planning/activities/{id}` | workforce.planning_manage |  | `ScheduledActivityController@update` |
| DELETE | `/api/workforce/planning/activities/{id}` | workforce.planning_manage |  | `ScheduledActivityController@destroy` |
| POST | `/api/workforce/planning/activities/{id}/cancel` | workforce.planning_manage |  | `ScheduledActivityController@cancel` |
| POST | `/api/workforce/planning/activities/{id}/duplicate` | workforce.planning_manage |  | `ScheduledActivityController@duplicate` |
| GET | `/api/workforce/planning/activity-presets` | workforce.planning_view |  | `ActivityPresetController@index` |
| POST | `/api/workforce/planning/activity-presets` | workforce.planning_manage |  | `ActivityPresetController@store` |
| PUT | `/api/workforce/planning/activity-presets/{id}` | workforce.planning_manage |  | `ActivityPresetController@update` |
| DELETE | `/api/workforce/planning/activity-presets/{id}` | workforce.planning_manage |  | `ActivityPresetController@destroy` |
| GET | `/api/workforce/planning/coverage` | workforce.planning_view |  | `CoverageRequirementController@index` |
| POST | `/api/workforce/planning/coverage` | workforce.coverage_manage |  | `CoverageRequirementController@store` |
| GET | `/api/workforce/planning/coverage-categories` | workforce.planning_view |  | `CoverageCategoryController@index` |
| POST | `/api/workforce/planning/coverage-categories` | workforce.coverage_manage |  | `CoverageCategoryController@store` |
| PUT | `/api/workforce/planning/coverage-categories/{id}` | workforce.coverage_manage |  | `CoverageCategoryController@update` |
| DELETE | `/api/workforce/planning/coverage-categories/{id}` | workforce.coverage_manage |  | `CoverageCategoryController@destroy` |
| POST | `/api/workforce/planning/coverage/replicate` | workforce.coverage_manage |  | `CoverageRequirementController@replicate` |
| GET | `/api/workforce/planning/coverage/summary` | workforce.planning_view |  | `CoverageRequirementController@summary` |
| PUT | `/api/workforce/planning/coverage/{id}` | workforce.coverage_manage |  | `CoverageRequirementController@update` |
| DELETE | `/api/workforce/planning/coverage/{id}` | workforce.coverage_manage |  | `CoverageRequirementController@destroy` |
| GET | `/api/workforce/planning/favorites` | workforce.planning_view |  | `WorkforceFavoriteController@index` |
| POST | `/api/workforce/planning/favorites/toggle` | workforce.planning_view |  | `WorkforceFavoriteController@toggle` |
| GET | `/api/workforce/planning/locations` | workforce.planning_view |  | `WorkLocationController@index` |
| POST | `/api/workforce/planning/locations` | workforce.planning_manage |  | `WorkLocationController@store` |
| PUT | `/api/workforce/planning/locations/{id}` | workforce.planning_manage |  | `WorkLocationController@update` |
| DELETE | `/api/workforce/planning/locations/{id}` | workforce.planning_manage |  | `WorkLocationController@destroy` |
| POST | `/api/workforce/planning/operations/{id}/redo` | workforce.planning_manage |  | `ShiftController@redoOperation` |
| POST | `/api/workforce/planning/operations/{id}/undo` | workforce.planning_manage |  | `ShiftController@undoOperation` |
| POST | `/api/workforce/planning/presets/import` | workforce.planning_manage |  | `WorkforcePresetController@import` |
| GET | `/api/workforce/planning/presets/import-preview` | workforce.planning_view |  | `WorkforcePresetController@preview` |
| GET | `/api/workforce/planning/shifts` | workforce.planning_view |  | `ShiftController@index` |
| POST | `/api/workforce/planning/shifts` | workforce.planning_manage |  | `ShiftController@store` |
| GET | `/api/workforce/planning/shifts/availability` | workforce.planning_manage |  | `ShiftController@availability` |
| GET | `/api/workforce/planning/shifts/board` | workforce.planning_view |  | `ShiftController@board` |
| POST | `/api/workforce/planning/shifts/bulk` | workforce.planning_manage |  | `ShiftController@bulkCreate` |
| POST | `/api/workforce/planning/shifts/bulk-template` | workforce.planning_manage |  | `ShiftController@bulkApplyTemplate` |
| POST | `/api/workforce/planning/shifts/bulk-template/preview` | workforce.planning_view |  | `ShiftController@bulkApplyTemplatePreview` |
| GET | `/api/workforce/planning/shifts/calendar` | workforce.planning_view |  | `ShiftController@calendar` |
| GET | `/api/workforce/planning/shifts/comparison` | workforce.planning_view |  | `ShiftController@comparison` |
| POST | `/api/workforce/planning/shifts/copy` | workforce.planning_manage |  | `ShiftController@copy` |
| POST | `/api/workforce/planning/shifts/copy-week` | workforce.planning_manage |  | `ShiftController@copyWeek` |
| POST | `/api/workforce/planning/shifts/copy-week/apply` | workforce.planning_manage |  | `ShiftController@copyWeekApply` |
| POST | `/api/workforce/planning/shifts/copy-week/preview` | workforce.planning_view |  | `ShiftController@copyWeekPreview` |
| POST | `/api/workforce/planning/shifts/copy/preview` | workforce.planning_view |  | `ShiftController@copyPreview` |
| POST | `/api/workforce/planning/shifts/overstaffing-preview` | workforce.planning_view |  | `ShiftController@overstaffingPreview` |
| POST | `/api/workforce/planning/shifts/publish` | workforce.planning_publish |  | `ShiftController@publish` |
| GET | `/api/workforce/planning/shifts/statistics` | workforce.planning_view |  | `ShiftController@statistics` |
| GET | `/api/workforce/planning/shifts/week` | workforce.planning_view |  | `ShiftController@week` |
| PUT | `/api/workforce/planning/shifts/{id}` | workforce.planning_manage |  | `ShiftController@update` |
| DELETE | `/api/workforce/planning/shifts/{id}` | workforce.planning_manage |  | `ShiftController@destroy` |
| POST | `/api/workforce/planning/shifts/{id}/cancel` | workforce.planning_manage |  | `ShiftController@cancel` |
| POST | `/api/workforce/planning/shifts/{id}/duplicate` | workforce.planning_manage |  | `ShiftController@duplicate` |
| POST | `/api/workforce/planning/shifts/{id}/replace` | workforce.planning_publish |  | `ShiftController@replace` |
| GET | `/api/workforce/planning/shifts/{id}/replacement-candidates` | workforce.planning_view |  | `ShiftController@replacementCandidates` |
| GET | `/api/workforce/planning/templates` | workforce.planning_view |  | `ScheduleTemplateController@index` |
| POST | `/api/workforce/planning/templates` | workforce.planning_manage |  | `ScheduleTemplateController@store` |
| PUT | `/api/workforce/planning/templates/{id}` | workforce.planning_manage |  | `ScheduleTemplateController@update` |
| DELETE | `/api/workforce/planning/templates/{id}` | workforce.planning_manage |  | `ScheduleTemplateController@destroy` |
| GET | `/api/workforce/planning/week-templates` | workforce.planning_view |  | `WeekTemplateController@index` |
| POST | `/api/workforce/planning/week-templates` | workforce.planning_manage |  | `WeekTemplateController@store` |
| POST | `/api/workforce/planning/week-templates/capture` | workforce.planning_manage |  | `WeekTemplateController@capture` |
| PUT | `/api/workforce/planning/week-templates/{id}` | workforce.planning_manage |  | `WeekTemplateController@update` |
| DELETE | `/api/workforce/planning/week-templates/{id}` | workforce.planning_manage |  | `WeekTemplateController@destroy` |
| POST | `/api/workforce/planning/week-templates/{id}/apply` | workforce.planning_manage |  | `WeekTemplateController@apply` |
| POST | `/api/workforce/planning/week-templates/{id}/preview` | workforce.planning_view |  | `WeekTemplateController@preview` |
| POST | `/api/workforce/planning/week-templates/{id}/repeat-apply` | workforce.planning_manage |  | `WeekTemplateController@repeatApply` |
| POST | `/api/workforce/planning/week-templates/{id}/repeat-preview` | workforce.planning_view |  | `WeekTemplateController@repeatPreview` |

### Module `workforce_signature` (21 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| GET | `/api/workforce/signature` | workforce_signature.view |  | `SignatureController@index` |
| POST | `/api/workforce/signature` | workforce_signature.request |  | `SignatureController@store` |
| GET | `/api/workforce/signature/by-employee/{employeeId}` | workforce_signature.view |  | `SignatureController@byEmployee` |
| GET | `/api/workforce/signature/certificates` | workforce_signature.view_evidence |  | `SignatureController@certificates` |
| GET | `/api/workforce/signature/eligible-documents` | workforce_signature.request |  | `SignatureController@eligibleDocuments` |
| GET | `/api/workforce/signature/me` | — |  | `MySignatureController@index` |
| GET | `/api/workforce/signature/me/{id}/download` | — |  | `MySignatureController@download` |
| POST | `/api/workforce/signature/me/{id}/open` | — | ThrottleRequests:20,1 | `MySignatureController@open` |
| GET | `/api/workforce/signature/options` | workforce_signature.view |  | `SignatureController@options` |
| GET | `/api/workforce/signature/reconciliation` | workforce_signature.view_evidence |  | `SignatureController@reconciliation` |
| GET | `/api/workforce/signature/settings` | workforce_signature.admin |  | `SignatureController@getSettings` |
| PUT | `/api/workforce/signature/settings` | workforce_signature.admin |  | `SignatureController@updateSettings` |
| GET | `/api/workforce/signature/supervision` | workforce_signature.view_evidence |  | `SignatureController@supervision` |
| GET | `/api/workforce/signature/{id}` | workforce_signature.view |  | `SignatureController@show` |
| POST | `/api/workforce/signature/{id}/cancel` | workforce_signature.manage |  | `SignatureController@cancel` |
| GET | `/api/workforce/signature/{id}/download` | workforce_signature.view | EnsureCanOpenGeneratedDocument:signature | `SignatureController@downloadSigned` |
| GET | `/api/workforce/signature/{id}/evidence` | workforce_signature.view_evidence |  | `SignatureController@evidence` |
| GET | `/api/workforce/signature/{id}/evidence-package` | workforce_signature.view_evidence |  | `SignatureController@evidencePackage` |
| POST | `/api/workforce/signature/{id}/remind` | workforce_signature.manage |  | `SignatureController@remind` |
| POST | `/api/workforce/signature/{id}/signers/{signerId}/reset-key` | workforce_signature.manage |  | `SignatureController@resetSignerKey` |
| GET | `/api/workforce/signature/{id}/verify` | workforce_signature.view |  | `SignatureController@verify` |

### Module `—` (24 routes)

| Méthode | URI | Permission (route) | Garde complémentaire | Action |
|---|---|---|---|---|
| DELETE | `/api/2fa` | — |  | `TwoFactorController@disable` |
| POST | `/api/2fa/backup-codes` | — |  | `TwoFactorController@regenerateBackupCodes` |
| POST | `/api/2fa/confirm` | — |  | `TwoFactorController@confirm` |
| POST | `/api/2fa/enable` | — |  | `TwoFactorController@enable` |
| GET | `/api/2fa/status` | — |  | `TwoFactorController@status` |
| GET | `/api/dashboard/layout` | — |  | `CompanyDashboardLayoutController@show` |
| PUT | `/api/dashboard/layout` | manage-structure |  | `CompanyDashboardLayoutController@update` |
| GET | `/api/dashboard/onboarding` | — |  | `OnboardingStatusController@__invoke` |
| POST | `/api/dashboard/onboarding/dismiss` | — |  | `OnboardingStatusController@dismiss` |
| POST | `/api/dashboard/onboarding/reopen` | — |  | `OnboardingStatusController@reopen` |
| GET | `/api/dashboard/priorities` | — |  | `HomePrioritiesController` |
| GET | `/api/dashboard/suggestions` | — |  | `CompanyDashboardLayoutController@suggestions` |
| GET | `/api/dashboard/widgets/catalog` | — |  | `CompanyDashboardWidgetController@catalog` |
| POST | `/api/dashboard/widgets/data` | — |  | `CompanyDashboardWidgetController@batchResolve` |
| GET | `/api/feature-flags` | — |  | `CompanyFeatureFlagController` |
| GET | `/api/nav` | — |  | `NavController@company` |
| GET | `/api/notifications` | — |  | `NotificationController@index` |
| GET | `/api/notifications/preferences` | — |  | `NotificationPreferenceController@index` |
| PUT | `/api/notifications/preferences` | — |  | `NotificationPreferenceController@update` |
| POST | `/api/notifications/read-all` | — |  | `NotificationController@markAllRead` |
| GET | `/api/notifications/unread-count` | — |  | `NotificationController@unreadCount` |
| DELETE | `/api/notifications/{id}` | — |  | `NotificationController@destroy` |
| POST | `/api/notifications/{id}/read` | — |  | `NotificationController@markRead` |
| GET | `/api/realtime/stream` | — |  | `RealtimeStreamController` |
