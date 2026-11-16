# ADR-800 — Inventaire backend

> `php artisan route:list --json` (903 routes dont 896 `api/*`), `ModuleRegistry::definitions()`, recherche des consommateurs : littéraux d'URL dans `resources/js` (`$api`, `$platformApi`, `ofetch`, chaînes `/api/...`), puis vérification manuelle des cas sans littéral (URL construites, stores, SSE, webhooks). SHA `c3f468fa`.

## 1. Synthèse des endpoints

| Groupe | Endpoints | Consommés (littéral) | Sans littéral |
|---|---|---|---|
| workforce | 266 | 256 | 10 |
| company | 97 | 81 | 16 |
| platform/billing | 72 | 71 | 1 |
| platform/email | 38 | 35 | 3 |
| billing | 28 | 24 | 4 |
| platform/companies | 25 | 24 | 1 |
| public | 25 | 17 | 8 |
| platform/documentation | 17 | 17 | 0 |
| platform/jobdomains | 15 | 14 | 1 |
| profile | 14 | 11 | 3 |
| platform/translations | 13 | 13 | 0 |
| platform/ai | 12 | 12 | 0 |
| platform/markets | 11 | 11 | 0 |
| platform/presets | 11 | 10 | 1 |
| platform/contract-templates | 10 | 10 | 0 |
| platform/me | 10 | 6 | 4 |
| platform/support | 10 | 10 | 0 |
| dashboard | 9 | 9 | 0 |
| platform/organization-presets | 9 | 8 | 1 |
| modules | 7 | 7 | 0 |
| notifications | 7 | 3 | 4 |
| platform/dashboard | 7 | 3 | 4 |
| platform/documents | 7 | 7 | 0 |
| platform/languages | 7 | 7 | 0 |
| platform/plans | 7 | 7 | 0 |
| platform/platform-users | 7 | 7 | 0 |
| workflows | 7 | 7 | 0 |
| 2fa | 6 | 6 | 0 |
| platform/2fa | 6 | 6 | 0 |
| platform/alerts | 6 | 6 | 0 |
| platform/feature-flags | 6 | 5 | 1 |
| platform/realtime | 6 | 5 | 1 |
| help-center | 5 | 5 | 0 |
| platform/modules | 5 | 5 | 0 |
| platform/security | 5 | 5 | 0 |
| shipments | 5 | 5 | 0 |
| support | 5 | 5 | 0 |
| audience | 4 | 4 | 0 |
| platform/automations | 4 | 4 | 0 |
| platform/field-definitions | 4 | 4 | 0 |
| platform/font-families | 4 | 4 | 0 |
| platform/pricing | 4 | 4 | 0 |
| platform/roles | 4 | 4 | 0 |
| my-deliveries | 3 | 3 | 0 |
| my-invitations | 3 | 3 | 0 |
| platform/audit | 3 | 3 | 0 |
| platform/notifications | 3 | 3 | 0 |
| register | 3 | 2 | 1 |
| webhooks | 3 | 0 | 3 |
| platform/activity | 2 | 2 | 0 |
| platform/field-activations | 2 | 2 | 0 |
| platform/fx-rates | 2 | 2 | 0 |
| platform/general-settings | 2 | 2 | 0 |
| platform/legal-statuses | 2 | 2 | 0 |
| platform/maintenance-settings | 2 | 2 | 0 |
| platform/session-settings | 2 | 2 | 0 |
| platform/theme | 2 | 2 | 0 |
| platform/typography | 2 | 2 | 0 |
| platform/usage | 2 | 2 | 0 |
| platform/world-settings | 2 | 0 | 2 |
| theme | 2 | 2 | 0 |
| audit | 1 | 1 | 0 |
| feature-flags | 1 | 1 | 0 |
| forgot-password | 1 | 1 | 0 |
| heartbeat | 1 | 1 | 0 |
| login | 1 | 1 | 0 |
| logout | 1 | 1 | 0 |
| me | 1 | 1 | 0 |
| my-companies | 1 | 1 | 0 |
| nav | 1 | 1 | 0 |
| platform/company-users | 1 | 1 | 0 |
| platform/forgot-password | 1 | 1 | 0 |
| platform/heartbeat | 1 | 0 | 1 |
| platform/login | 1 | 1 | 0 |
| platform/logout | 1 | 1 | 0 |
| platform/maintenance | 1 | 1 | 0 |
| platform/nav | 1 | 1 | 0 |
| platform/onboarding | 1 | 1 | 0 |
| platform/permissions | 1 | 1 | 0 |
| platform/reset-password | 1 | 1 | 0 |
| platform/search | 1 | 1 | 0 |
| platform/system | 1 | 1 | 0 |
| platform/theme-preference | 1 | 0 | 1 |
| realtime | 1 | 0 | 1 |
| reset-password | 1 | 1 | 0 |
| runtime-error | 1 | 1 | 0 |
| theme-preference | 1 | 1 | 0 |
| whoami | 1 | 1 | 0 |
| **Total** | **896** | **824** | **72** |

### Lecture

- **CONSOMMÉ** : un littéral d'URL ou un appel indirect vérifié existe dans le frontend.
- **EXTERNE** : appelé par un prestataire (webhook, inbound).
- **ORPHELIN UI** : endpoint testé côté PHP, aucune interface ne l'appelle. La fonction existe mais l'utilisateur ne peut pas la déclencher.
- **ORPHELIN confirmé** : aucun appel, recherche élargie à tous les segments distinctifs.
- **MORT prouvé** : ne peut jamais réussir.

Sur 72 endpoints sans littéral, **47 sont consommés indirectement ou par un prestataire**. Il reste **27 orphelins** et **1 endpoint mort** (§2) : 23 sont des orphelins UI (testés, sans écran) et 4 des orphelins confirmés. Après vérification, chacun des 896 endpoints a un statut explicite : 821 consommés par un littéral, 43 consommés indirectement, 4 externes, 23 orphelins UI, 4 orphelins confirmés, 1 mort.

## 2. Endpoints sans consommateur (vérifiés un à un)

| Endpoint | Preuve | Confiance | Recommandation |
|---|---|---|---|
| `PUT api/company/jobdomain` | `CompanyJobdomainController::update` répond 422 dès que `jobdomain_key` existe, toujours vrai depuis ADR-167a ; `jobdomain.store.setJobdomain` n'a aucun appelant | Prouvé (code + commentaire ADR-167a) | Retirer la route et l'action du store ; la permission `jobdomain.manage` ne garde alors plus rien (voir MODULE-RBAC-MAP) |
| `GET/PUT api/platform/world-settings` | 0 occurrence de `world-settings` dans `resources/js` | Fortement probable | Vérifier si `/platform/settings` le remplace, puis déprécier |
| `POST api/platform/feature-flags/{key}/company-override` | 0 occurrence ; le moteur de feature flags n'a **aucun consommateur** (`useFeatureFlag` n'est importé nulle part, 0 flag en base DEV) | Prouvé | Voir §6 (mécanisme sans usage) |
| `POST api/register/funnel-track` | `useAnalytics.track()` ne fait que `debugLog` | Prouvé | Brancher ou retirer (P3) |
| `GET api/platform/billing/metrics-export` | 0 occurrence frontend, testé | Fortement probable | Exposer (bouton export) ou déprécier |
| `GET api/platform/dashboard/health` | 0 occurrence frontend, testé | Fortement probable | Idem |
| `GET api/platform/organization-presets/{id}/simulate` | 0 occurrence ; ancien éditeur d'organisation | Fortement probable | À retirer avec l'ancien éditeur (lot P1-3) |
| `POST api/platform/presets/{kind}/{id}/companies/{companyId}/apply` | Volontaire (ADR-793 : aucune application silencieuse) | Prouvé | **Conserver**, sert au futur assistant de module |
| `GET/PUT/DELETE api/platform/jobdomains/{key}/overlays…` | `_MarketOverlaySelector.vue` n'est plus monté depuis la réécriture ADR-799 de `jobdomains/[id].vue` ; les méthodes du store n'ont plus d'appelant | Prouvé | Décider : réintégrer dans l'onglet Général ou déprécier les overlays de marché (P1-3) |
| `POST api/workforce/signature/{id}/signers/{signerId}/reset-key` | 0 occurrence frontend | Fortement probable | Exposer dans le détail signature ou retirer |
| `GET api/billing/checkout/status`, `PUT api/billing/subscription/billing-day`, `POST api/billing/pay-now` | 0 appel (`pay-now` n'apparaît que dans l'i18n) | Fortement probable | Hors périmètre (aucun changement Billing) : documenter |
| `PUT/POST/GET api/company/document-campaigns/{id}/cancel\|launch\|recipients` | `_DocumentsCampaigns.vue` crée et suit ; ne lance pas, n'annule pas, ne liste pas les destinataires | Prouvé | Exposer ces actions dans le suivi de campagne (P1) |
| `api/company/payslip-imports/…/split\|period\|regroup\|preview\|pages/exclude`, `published-payslips/{id}/revoke` | `_DocumentsPayslipImport.vue` n'appelle que `exclude` et `assign` ; l'éditeur de revue ADR-764 n'est pas branché à l'écran | Prouvé | Exposer (P1) : ces actions sont annoncées par ADR-764/M5.1 mais inaccessibles |
| `POST api/profile/disclosures` et `…/withdrawal` | 0 appel frontend (ADR-796) | Fortement probable | Brancher dans Mon espace ou documenter comme API-only |
| `POST api/workforce/onboarding-files/{id}/uploads/{upload}/rescan` | `rescan` n'apparaît que dans l'i18n | Fortement probable | Exposer dans la revue du dossier |

## 3. Services, moteurs et classes

Recensement : 1 317 classes PHP dans `app/` ; 128 `*Service.php`, 62 ReadModels, 160 UseCases, 15 Adapters, 27 Resolvers, 83 Commands, 11 Jobs, 210 Controllers.

Méthode : une classe est dite sans référence si son nom court n'apparaît dans aucun autre fichier de `app/`, `routes/`, `config/`, `database/` et `bootstrap/`, ni sous forme `::class`, ni comme chaîne. La résolution dynamique (`app($class)`) n'utilise que des `::class` venant des manifests : elle est donc couverte.

- **139 classes sans référence.**
  - 82 Commands (auto-découvertes par Laravel, légitimes) ;
  - 39 classes `*Module.php` (auto-découvertes par `ModuleRegistry`, légitimes) ;
  - **18 autres**, ci-dessous.

| Classe | Références hors fichier | Statut |
|---|---|---|
| `Workforce/UseCases/CompleteShiftUseCase`, `DetectAnomaliesUseCase`, `GrantLeaveUseCase`, `StornoLedgerEntryUseCase` | 0 dans tout le dépôt (y compris tests et ADR) | **Code mort prouvé (statique)** |
| `Workforce/ReadModels/ContributionRulesReadModel`, `DocumentTemplateReadModel`, `TemplateVariableSchemaReadModel` | 0 dans tout le dépôt | **Code mort prouvé (statique)** |
| `Workforce/UseCases/BulkGenerateDocumentUseCase`, `CreateDocumentTemplateUseCase`, `UpdateDocumentTemplateUseCase`, `DeleteGeneratedDocumentUseCase`, `UpdateSignatureStatusUseCase` | Citées seulement dans un ADR | Mort probable : remplacées, à confirmer par l'ADR |
| `Workforce/Data/ApproveLeaveData`, `RequestLeaveData` | Citées seulement dans un ADR | Mort probable |
| `Core/Email/EmailStatsService` | Citée seulement dans un ADR | Mort probable |
| `Core/Modules/DependencyResolver` (`canActivate`/`canDeactivate`) | Testée seulement ; l'activation réelle passe par `ModuleActivationEngine` + `DependencyGraph` | **Moteur concurrent inutilisé** |
| `Core/Crypto/MultiPadesSignatureService`, `Core/Fields/FieldConfigHealthCheck` | Testées seulement | Suspect : outil de recette ou fonction non branchée |

## 4. Resolvers : concurrence et superposition

| Domaine | Resolvers | Constat | Confiance |
|---|---|---|---|
| Exigences documentaires | `DocumentResolverService` (en production : conformité, read-models) ∥ `EffectiveRequirementResolver` (ADR-790, comparé par `RequirementParityComparator`, `requirements:compare`) ∥ `CompanyRequirementResolver` (ADR-798, uniquement l'aperçu de l'éditeur Platform) | **3 moteurs pour la même question** « que doit fournir X ? » ; mode parallèle assumé, sans date de bascule | Prouvé |
| Initialisation Company | `JobdomainGate::assignToCompany` (en production) ∥ `VersionedCompanyInitializer::initializeNew` (flag `JOBDOMAIN_VERSIONED_INIT` désactivé) ∥ `CreationParity` (mesure) | 2 chemins de création ; le chemin versionné n'a **aucun contenu publié** sur DEV (`presets` : 1 brouillon RBAC, 1 brouillon planning ; `jobdomain_preset_links` = 0) | Prouvé (base DEV) |
| Presets de rôles | `jobdomains.default_roles` (JSON de 14 ko, lu par `assignToCompany`) ∥ overlays de marché `JobdomainPresetResolver` ∥ presets versionnés `rbac` | 3 sources pour « rôles initiaux » | Prouvé |
| Presets d'organisation | `organization_presets` (table unique) édité par **2 interfaces** : `/platform/organization-presets` et le catalogue Presets ; `OrganizationPresetResolver` côté Company | Une source, deux éditeurs | Prouvé |
| Presets de planning | `jobdomains.workforce_presets` (JSON, importé par `WorkforcePresetImportService`, `/workforce/planning/presets/import`) ∥ presets versionnés `planning` | 2 sources | Prouvé |
| Règles de temps de travail | `WorkforceRuleResolver` (une source) exposé par **2 API/UI** : `/workforce/config/{work-time,breaks}` (Paramètres → onglet worktime) et `/workforce/working-time-setup` (assistant, ADR-799) | Une source, deux écrans | Prouvé |
| Dépendances de modules | `DependencyResolver` ∥ `DependencyGraph` + `ModuleActivationEngine::collectTransitiveRequires` | Doublon (le premier est inutilisé) | Prouvé |

## 5. Jobs, événements, commandes, scheduler

- **Scheduler** : 29 tâches (`php artisan schedule:list`), toutes avec une commande existante.
  - Billing : 11 ; documents : 3 ; onboarding : 3 ; congés : 2 ; alertes : 2 ; signature, invitations, inbox, planning, workflow, usage, inscription, heartbeat : 1 chacune.
- **Commandes** : 83 au total, dont 54 manuelles.
  - **13 `e2e:*`** (préparation de recette) et `leezr:dev-reset` / `leezr:dev-bootstrap` : outils DEV légitimes. Les isoler par environnement est à vérifier.
  - **Commandes de migration ou de mesure historiques**, candidates à l'archivage une fois leur bascule faite : `billing:v2-migration-audit`, `billing:regularize-current-subscriptions`, `billing:reconstruct-contracted-price`, `documents:m0-backfill`, `role:backfill-archetypes`, `requirements:compare`, `jobdomains:creation-parity`, `jobdomains:compatibility-report`, `jobdomain:sync-logistics-fields`, `onboarding:cleanup-fixtures`.
- **Événements de module** : `ModuleEnabled` déclenche `InjectModuleWidgets`, `AddonBillingListener` et `SeedLeaveTypesOnModuleEnabled` ; `ModuleDisabled` déclenche `AddonCreditListener`.
  - **Aucun listener n'attribue de permissions ni ne lance d'assistant** à l'activation : c'est l'écart « activé ≠ configuré ».

## 6. Flags

| Flag | Défaut | Rôle | Stratégie de retrait connue |
|---|---|---|---|
| `JOBDOMAIN_VERSIONED_INIT` | off | Création versionnée (ADR-798) | Aucune : dépend de presets publiés, qui n'existent pas |
| `JOBDOMAIN_LEGACY_EDITING` | off | Échappatoire d'édition de l'ancienne configuration JobDomain | Aucune |
| `BILLING_CONSOLIDATED_EMIT`, `BILLING_CONSOLIDATED_ROUTE_ACTIVATIONS` | off | Facturation V2 (ADR-632) | NO-GO PROD documenté ; hors périmètre |
| `BILLING_AUTO_REPAIR_ENABLED`, `BILLING_ALERT_ENABLED` | off | Ops Billing | Hors périmètre |
| `DSN_SUBMIT_ENABLED` | off | Dépôt DSN réel | Dépend des identifiants |
| `SIGNATURE_EXTERNAL_ENABLED` | off | Prestataire de signature (ADR-765) | Dépend des identifiants et d'eIDAS |
| `SIGNATURE_DEV_CA_ENABLED` | on hors prod | Autorité de certification de dev | Légitime |
| Moteur `FeatureFlag` (table, CRUD Platform `_SettingsFeatureFlags.vue`, override par société) | 0 flag en base | **Aucun code ne lit un flag** (`useFeatureFlag` : 0 import ; `FeatureFlag::` : 0 usage) | **Mécanisme complet sans usage** |

## 7. Policies et middlewares

- Les gardes réelles sont `EnsureCompanyAccess:use-module|use-permission|use-any-permission|manage-structure` (854 routes), `EnsurePlatformPermission` (357 routes, syntaxe `a|b`) et `EnsureModuleActive` (363 routes). Il y a 12 classes `*Policy.php`.
- **Toutes les mutations Company sans middleware de permission** sont des actions personnelles :
  - `workforce/me/*` (pointage, congés) ;
  - notifications ;
  - tableau de bord (masquer / rouvrir l'onboarding) ;
  - `signature/me/{id}/open` ;
  - heartbeat et erreurs runtime.

  Toutes sont légitimes. Prouvé par `route:list`.
- Mutations Platform sans `EnsurePlatformPermission` : uniquement authentification, compte personnel, notifications personnelles et `email/inbound`. Ce dernier est protégé par un secret partagé, fail-closed. Remarque : le secret est aussi accepté dans le corps de la requête (P3).

## 8. Code « Core métier » (rappel doctrine ADR-562)

- `app/Core/Workforce/` contient **95 fichiers**, dont les modèles, `WorkforceRuleResolver`, `ConventionCollective` et DSN.
- C'est l'écart de doctrine déjà connu, « métier RH dans Core ». Il est inventorié ici, mais **hors de la simplification d'usage** : le déplacer serait un lot technique P2 sans gain pour l'utilisateur.

## 9. Annexe — liste exhaustive des endpoints `api/*`

| Méthode | URI | Action | Consommateur | Statut |
|---|---|---|---|---|
| DELETE | `api/2fa` | TwoFactorController@disable | notification.js | CONSOMMÉ (frontend) |
| POST | `api/2fa/backup-codes` | TwoFactorController@regenerateBackupCodes | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| POST | `api/2fa/confirm` | TwoFactorController@confirm | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| POST | `api/2fa/enable` | TwoFactorController@enable | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| GET | `api/2fa/status` | TwoFactorController@status | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| POST | `api/2fa/verify` | AuthController@verify2fa | auth.js | CONSOMMÉ (frontend) |
| POST | `api/audience/confirm` | AudienceController@confirm | confirm.vue | CONSOMMÉ (frontend) |
| GET | `api/audience/maintenance-page` | AudienceController@maintenancePage | maintenance.vue | CONSOMMÉ (frontend) |
| POST | `api/audience/subscribe` | AudienceController@subscribe | maintenance.vue | CONSOMMÉ (frontend) |
| POST | `api/audience/unsubscribe` | AudienceController@unsubscribe | unsubscribe.vue | CONSOMMÉ (frontend) |
| GET | `api/audit` | CompanyAuditLogController@index | notification.js | CONSOMMÉ (frontend) |
| POST | `api/billing/checkout` | BillingCheckoutController | _BillingPlan.vue | CONSOMMÉ (frontend) |
| GET | `api/billing/checkout/status` | CheckoutStatusController | tests | ORPHELIN UI : backend testé, aucune interface |
| POST | `api/billing/confirm-setup-intent` | CompanyPaymentSetupController@confirmSetupIntent | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/consolidated-preview` | CompanyBillingController@consolidatedPreview | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/invoices` | CompanyBillingController@invoices | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/invoices/{id}` | CompanyBillingController@invoiceDetail | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/invoices/{id}/pdf` | CompanyBillingController@invoicePdf | _BillingInvoices.vue | CONSOMMÉ (frontend) |
| POST | `api/billing/invoices/{id}/retry` | CompanyPaymentMethodController@retryInvoice | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/invoices/outstanding` | InvoiceBatchPayController@listOutstanding | billing.store.js | CONSOMMÉ (frontend) |
| POST | `api/billing/invoices/pay` | InvoiceBatchPayController@createPaymentIntent | billing.store.js | CONSOMMÉ (frontend) |
| POST | `api/billing/invoices/pay/confirm` | InvoiceBatchPayController@confirmPayment | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/next-invoice-preview` | CompanyBillingController@nextInvoicePreview | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/overview` | CompanyBillingController@overview | billing.store.js | CONSOMMÉ (frontend) |
| POST | `api/billing/pay-now` | SubscriptionMutationController@payNow | tests | ORPHELIN UI : backend testé, aucune interface |
| DELETE | `api/billing/pending-subscription` | CompanyBillingController@dismissPendingSubscription | billing.store.js | CONSOMMÉ (frontend) |
| POST | `api/billing/plan-change` | SubscriptionMutationController@planChange | billing.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/billing/plan-change` | SubscriptionMutationController@cancelPlanChange | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/plan-change-preview` | CompanyBillingController@planChangePreview | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/saved-cards` | CompanyPaymentMethodController@savedCards | billing.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/billing/saved-cards/{id}` | CompanyPaymentMethodController@deleteCard | billing.store.js | CONSOMMÉ (frontend) |
| PUT | `api/billing/saved-cards/{id}/debit-day` | CompanyPaymentMethodController@setDebitDay | billing.store.js | CONSOMMÉ (frontend) |
| PUT | `api/billing/saved-cards/{id}/default` | CompanyPaymentMethodController@setDefault | billing.store.js | CONSOMMÉ (frontend) |
| POST | `api/billing/setup-intent` | CompanyPaymentSetupController@createSetupIntent | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/subscription` | CompanyBillingController@subscription | billing.store.js | CONSOMMÉ (frontend) |
| PUT | `api/billing/subscription/billing-day` | SubscriptionMutationController@setBillingDay | tests | ORPHELIN UI : backend testé, aucune interface |
| PUT | `api/billing/subscription/cancel` | SubscriptionMutationController@cancel | billing.store.js | CONSOMMÉ (frontend) |
| GET | `api/billing/subscription/cancel-preview` | SubscriptionMutationController@cancelPreview | _BillingOverview.vue | CONSOMMÉ (frontend) |
| GET | `api/billing/timeline` | CompanyBillingTimelineController | — | CONSOMMÉ (indirect, vérifié) — _BillingTimeline |
| GET | `api/company` | CompanyController@show | notification.js | CONSOMMÉ (frontend) |
| PUT | `api/company` | CompanyController@update | notification.js | CONSOMMÉ (frontend) |
| GET | `api/company/ai-quota/{moduleKey}` | AiQuotaController@show | _DocumentsSettings.vue | CONSOMMÉ (frontend) |
| GET | `api/company/document-activations` | CompanyDocumentActivationController@index | documents.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/document-activations/{code}` | CompanyDocumentActivationController@upsert | CompanyDocumentActivationCatalog.vue | CONSOMMÉ (frontend) |
| GET | `api/company/document-campaigns` | DocumentCampaignController@index | _DocumentsCampaigns.vue | CONSOMMÉ (frontend) |
| POST | `api/company/document-campaigns` | DocumentCampaignController@store | _DocumentsCampaigns.vue | CONSOMMÉ (frontend) |
| GET | `api/company/document-campaigns/{id}` | DocumentCampaignController@show | _DocumentsCampaigns.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/document-campaigns/{id}/cancel` | DocumentCampaignController@cancel | tests | ORPHELIN UI : backend testé, aucune interface |
| POST | `api/company/document-campaigns/{id}/launch` | DocumentCampaignController@launch | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/company/document-campaigns/{id}/recipients` | DocumentCampaignController@recipients | tests | ORPHELIN UI : backend testé, aucune interface |
| POST | `api/company/document-campaigns/preview` | DocumentCampaignController@preview | _DocumentsCampaigns.vue | CONSOMMÉ (frontend) |
| POST | `api/company/document-requests` | DocumentRequestController@store | MemberDocumentsWorkflowPanel.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/document-requests/{id}/cancel` | DocumentRequestController@cancel | documents.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/document-requests/{id}/remind` | DocumentRequestController@remind | documents.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/document-requests/batch` | DocumentRequestController@batchByRole | _DocumentsRequests.vue | CONSOMMÉ (frontend) |
| POST | `api/company/document-requests/bulk-action` | DocumentRequestController@bulkAction | documents.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/document-requests/queue` | DocumentRequestController@queue | documents.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/document-requests/roles` | DocumentRequestController@batchRoles | _DocumentsRequests.vue | CONSOMMÉ (frontend) |
| GET | `api/company/document-settings` | DocumentSettingController@show | documents.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/document-settings` | DocumentSettingController@update | documents.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/document-types/custom` | CustomDocumentTypeController@store | documents.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/document-types/custom/{code}` | CustomDocumentTypeController@update | documents.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/company/document-types/custom/{code}` | CustomDocumentTypeController@destroy | documents.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/document-types/custom/{code}/archive` | CustomDocumentTypeController@archive | documents.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/documents` | CompanyDocumentController@index | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/company/documents/{code}` | CompanyDocumentController@upload | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| DELETE | `api/company/documents/{code}` | CompanyDocumentController@destroy | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| GET | `api/company/documents/{code}/download` | CompanyDocumentController@download | CompanyDocumentsVault.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/documents/{code}/review` | CompanyDocumentController@review | _DocumentsVault.vue | CONSOMMÉ (frontend) |
| GET | `api/company/documents/{code}/versions` | CompanyDocumentController@versions | CompanyDocumentsVault.vue | CONSOMMÉ (frontend) |
| GET | `api/company/documents/{code}/versions/{versionId}/download` | CompanyDocumentController@downloadVersion | tests | CONSOMMÉ (indirect, vérifié) — DocumentVersionsTimeline (URL construite) |
| GET | `api/company/documents/activity` | DocumentComplianceController@activity | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| GET | `api/company/documents/compliance` | DocumentComplianceController@index | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| GET | `api/company/documents/compliance/export` | DocumentComplianceController@exportCsv | _DocumentsCompliance.vue | CONSOMMÉ (frontend) |
| GET | `api/company/documents/search` | DocumentSearchController | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| GET | `api/company/field-activations` | CompanyFieldActivationController@index | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/field-activations` | CompanyFieldActivationController@upsert | members.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/field-definitions` | CompanyFieldDefinitionController@index | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/field-definitions` | CompanyFieldDefinitionController@store | members.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/field-definitions/{id}` | CompanyFieldDefinitionController@update | members.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/company/field-definitions/{id}` | CompanyFieldDefinitionController@destroy | members.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/jobdomain` | CompanyJobdomainController@show | jobdomain.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/jobdomain` | CompanyJobdomainController@update | jobdomain.store.js | MORT prouvé : répond toujours 422 (ADR-134/167a), setJobdomain jamais appelé |
| GET | `api/company/legal-structure` | CompanyLegalStructureController@show | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/legal-structure` | CompanyLegalStructureController@updateLegalStatus | settings.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/members` | MembershipController@index | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/members` | MembershipController@store | members.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/members/{id}` | MembershipController@show | members.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/members/{id}` | MembershipController@update | members.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/company/members/{id}` | MembershipController@destroy | members.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/members/{id}/documents` | MemberDocumentController@index | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/members/{id}/documents/{code}` | MemberDocumentController@upload | _DocumentsRequests.vue | CONSOMMÉ (frontend) |
| DELETE | `api/company/members/{id}/documents/{code}` | MemberDocumentController@destroy | _DocumentsRequests.vue | CONSOMMÉ (frontend) |
| POST | `api/company/members/{id}/documents/{code}/apply-suggestions` | MemberDocumentAiSuggestionController@apply | _DocumentsRequests.vue | CONSOMMÉ (frontend) |
| GET | `api/company/members/{id}/documents/{code}/download` | MemberDocumentController@download | _DocumentsRequests.vue | CONSOMMÉ (frontend) |
| POST | `api/company/members/{id}/documents/{code}/retry-ai` | MemberDocumentController@retryAi | _DocumentsRequests.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/members/{id}/documents/{code}/review` | MemberDocumentController@review | _DocumentsRequests.vue | CONSOMMÉ (frontend) |
| GET | `api/company/members/{id}/documents/{code}/versions` | MemberDocumentController@versions | tests | CONSOMMÉ (indirect, vérifié) (URL construite) |
| GET | `api/company/members/{id}/documents/{code}/versions/{versionId}/download` | MemberDocumentController@downloadVersion | tests | CONSOMMÉ (indirect, vérifié) (URL construite) |
| GET | `api/company/members/{id}/fields` | MembershipController@fields | members.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/members/{id}/password` | MemberCredentialController@setPassword | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/members/{id}/resend-invitation` | MemberAccessController@resendInvitation | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/members/{id}/reset-password` | MemberCredentialController@resetPassword | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/members/{id}/restore` | MemberAccessController@restore | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/members/{id}/suspend` | MemberAccessController@suspend | members.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/payslip-imports` | PayslipImportController@store | _DocumentsPayslipImport.vue | CONSOMMÉ (frontend) |
| GET | `api/company/payslip-imports` | PayslipImportController@index | _DocumentsPayslipImport.vue | CONSOMMÉ (frontend) |
| GET | `api/company/payslip-imports/{id}` | PayslipImportController@show | _DocumentsPayslipImport.vue | CONSOMMÉ (frontend) |
| GET | `api/company/payslip-imports/{id}/items` | PayslipImportController@items | _DocumentsPayslipImport.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/payslip-imports/{id}/items/{itemId}/assign` | PayslipImportController@assign | _DocumentsPayslipImport.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/payslip-imports/{id}/items/{itemId}/exclude` | PayslipImportController@exclude | _DocumentsPayslipImport.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/payslip-imports/{id}/items/{itemId}/period` | PayslipImportController@correctPeriod | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/company/payslip-imports/{id}/items/{itemId}/preview` | PayslipImportController@previewItem | tests | ORPHELIN UI : backend testé, aucune interface |
| PUT | `api/company/payslip-imports/{id}/items/{itemId}/split` | PayslipImportController@split | tests | ORPHELIN UI : backend testé, aucune interface |
| PUT | `api/company/payslip-imports/{id}/pages/{pageId}/exclude` | PayslipImportController@excludePage | tests | ORPHELIN UI : backend testé, aucune interface |
| POST | `api/company/payslip-imports/{id}/publish` | PayslipImportController@publish | _DocumentsPayslipImport.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/payslip-imports/{id}/regroup` | PayslipImportController@regroup | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/company/people` | PersonController@index | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| GET | `api/company/people/{personRef}` | PersonController@show | person.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/people/{personRef}/documents` | PersonDocumentController@index | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/company/people/{personRef}/documents/{code}` | PersonDocumentController@upload | DocumentDepositDialog.vue | CONSOMMÉ (frontend) |
| GET | `api/company/people/{personRef}/documents/{code}/download` | PersonDocumentController@download | tests | CONSOMMÉ (indirect, vérifié) (URL construite) |
| PUT | `api/company/people/{personRef}/documents/{code}/review` | PersonDocumentReviewController@review | tests | CONSOMMÉ (indirect, vérifié) — MemberDocumentsWorkflowPanel base URL |
| GET | `api/company/people/{personRef}/documents/{code}/versions` | PersonDocumentController@versions | tests | CONSOMMÉ (indirect, vérifié) (URL construite) |
| GET | `api/company/people/{personRef}/documents/{code}/versions/{versionId}/download` | PersonDocumentController@downloadVersion | tests | CONSOMMÉ (indirect, vérifié) (URL construite) |
| GET | `api/company/people/{personRef}/history` | PersonController@history | person.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/people/{personRef}/payslips` | PublishedPayslipController@person | person.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/people/{personRef}/payslips/{id}/download` | PublishedPayslipController@downloadPerson | person.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/people/resolve` | PersonController@resolve | person.store.js | CONSOMMÉ (frontend) |
| GET | `api/company/permissions` | CompanyRoleController@permissionCatalog | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/plan` | CompanyPlanController@update | index.vue | CONSOMMÉ (frontend) |
| PUT | `api/company/published-payslips/{payslipId}/revoke` | PayslipImportController@revoke | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/company/roles` | CompanyRoleController@index | settings.store.js | CONSOMMÉ (frontend) |
| POST | `api/company/roles` | CompanyRoleController@store | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/company/roles/{id}` | CompanyRoleController@update | settings.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/company/roles/{id}` | CompanyRoleController@destroy | settings.store.js | CONSOMMÉ (frontend) |
| GET | `api/dashboard/layout` | CompanyDashboardLayoutController@show | useSurfaceEngine.js | CONSOMMÉ (frontend) |
| PUT | `api/dashboard/layout` | CompanyDashboardLayoutController@update | useSurfaceEngine.js | CONSOMMÉ (frontend) |
| GET | `api/dashboard/onboarding` | OnboardingStatusController@__invoke | _OnboardingWidget.vue | CONSOMMÉ (frontend) |
| POST | `api/dashboard/onboarding/dismiss` | OnboardingStatusController@dismiss | _OnboardingWidget.vue | CONSOMMÉ (frontend) |
| POST | `api/dashboard/onboarding/reopen` | OnboardingStatusController@reopen | _OnboardingWidget.vue | CONSOMMÉ (frontend) |
| GET | `api/dashboard/priorities` | HomePrioritiesController | HomePrioritiesSection.vue | CONSOMMÉ (frontend) |
| GET | `api/dashboard/suggestions` | CompanyDashboardLayoutController@suggestions | dashboard.store.js | CONSOMMÉ (frontend) |
| GET | `api/dashboard/widgets/catalog` | CompanyDashboardWidgetController@catalog | useSurfaceEngine.js | CONSOMMÉ (frontend) |
| POST | `api/dashboard/widgets/data` | CompanyDashboardWidgetController@batchResolve | useSurfaceEngine.js | CONSOMMÉ (frontend) |
| GET | `api/feature-flags` | CompanyFeatureFlagController | useFeatureFlag.js | CONSOMMÉ (frontend) |
| POST | `api/forgot-password` | PasswordResetController@forgotPassword | notification.js | CONSOMMÉ (frontend) |
| POST | `api/heartbeat` | Closure | notification.js | CONSOMMÉ (frontend) |
| GET | `api/help-center` | HelpCenterController@index | useHelpCenter.js | CONSOMMÉ (frontend) |
| POST | `api/help-center/article/{id}/feedback` | HelpCenterController@feedback | useHelpCenter.js | CONSOMMÉ (frontend) |
| GET | `api/help-center/article/{topicSlug}/{articleSlug}` | HelpCenterController@article | useHelpCenter.js | CONSOMMÉ (frontend) |
| GET | `api/help-center/search` | HelpCenterController@search | useHelpCenter.js | CONSOMMÉ (frontend) |
| GET | `api/help-center/topic/{slug}` | HelpCenterController@topic | useHelpCenter.js | CONSOMMÉ (frontend) |
| POST | `api/login` | AuthController@login | auth.js | CONSOMMÉ (frontend) |
| POST | `api/logout` | AuthController@logout | auth.js | CONSOMMÉ (frontend) |
| GET | `api/me` | AuthController@me | auth.js | CONSOMMÉ (frontend) |
| GET | `api/modules` | CompanyModuleController@index | module.js | CONSOMMÉ (frontend) |
| GET | `api/modules/{key}/deactivation-preview` | ModuleDeactivationPreviewController | module.js | CONSOMMÉ (frontend) |
| PUT | `api/modules/{key}/disable` | CompanyModuleController@disable | module.js | CONSOMMÉ (frontend) |
| PUT | `api/modules/{key}/enable` | CompanyModuleController@enable | module.js | CONSOMMÉ (frontend) |
| GET | `api/modules/{key}/settings` | CompanyModuleController@getSettings | module.js | CONSOMMÉ (frontend) |
| PUT | `api/modules/{key}/settings` | CompanyModuleController@updateSettings | module.js | CONSOMMÉ (frontend) |
| GET | `api/modules/quote` | ModuleQuoteController | module.js | CONSOMMÉ (frontend) |
| GET | `api/my-companies` | AuthController@myCompanies | auth.js | CONSOMMÉ (frontend) |
| GET | `api/my-deliveries` | MyDeliveryController@index | notification.js | CONSOMMÉ (frontend) |
| GET | `api/my-deliveries/{id}` | MyDeliveryController@show | delivery.store.js | CONSOMMÉ (frontend) |
| PUT | `api/my-deliveries/{id}/status` | MyDeliveryController@updateStatus | delivery.store.js | CONSOMMÉ (frontend) |
| GET | `api/my-invitations` | MyInvitationController@index | PendingInvitationsCard.vue | CONSOMMÉ (frontend) |
| POST | `api/my-invitations/{id}/accept` | MyInvitationController@accept | PendingInvitationsCard.vue | CONSOMMÉ (frontend) |
| POST | `api/my-invitations/{id}/decline` | MyInvitationController@decline | PendingInvitationsCard.vue | CONSOMMÉ (frontend) |
| GET | `api/nav` | NavController@company | nav.js | CONSOMMÉ (frontend) |
| GET | `api/notifications` | NotificationController@index | notification.js | CONSOMMÉ (frontend) |
| DELETE | `api/notifications/{id}` | NotificationController@destroy | notifications.store.js | CONSOMMÉ (frontend) |
| POST | `api/notifications/{id}/read` | NotificationController@markRead | notifications.store.js | CONSOMMÉ (frontend) |
| GET | `api/notifications/preferences` | NotificationPreferenceController@index | tests | CONSOMMÉ (indirect, vérifié) — notification.store _call |
| PUT | `api/notifications/preferences` | NotificationPreferenceController@update | tests | CONSOMMÉ (indirect, vérifié) — notification.store _call |
| POST | `api/notifications/read-all` | NotificationController@markAllRead | tests | CONSOMMÉ (indirect, vérifié) |
| GET | `api/notifications/unread-count` | NotificationController@unreadCount | tests | CONSOMMÉ (indirect, vérifié) |
| DELETE | `api/platform/2fa` | PlatformTwoFactorController@disable | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/2fa/backup-codes` | PlatformTwoFactorController@regenerateBackupCodes | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/2fa/confirm` | PlatformTwoFactorController@confirm | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/2fa/enable` | PlatformTwoFactorController@enable | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/2fa/status` | PlatformTwoFactorController@status | _AccountSecurity.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/2fa/verify` | PlatformAuthController@verify2fa | platformAuth.js | CONSOMMÉ (frontend) |
| GET | `api/platform/activity` | PlatformActivityController@index | activity.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/activity/types` | PlatformActivityController@types | activity.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/ai/config` | PlatformAiController@config | ai-providers.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/ai/config` | PlatformAiMutationController@updateConfig | ai-providers.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/ai/health` | PlatformAiController@health | ai-usage.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/ai/providers` | PlatformAiController@providers | ai-providers.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/ai/providers/{providerKey}/activate` | PlatformAiMutationController@activateProvider | ai-providers.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/ai/providers/{providerKey}/credentials` | PlatformAiMutationController@updateProviderCredentials | ai-providers.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/ai/providers/{providerKey}/deactivate` | PlatformAiMutationController@deactivateProvider | ai-providers.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/ai/providers/{providerKey}/health-check` | PlatformAiMutationController@healthCheck | ai-providers.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/ai/providers/{providerKey}/install` | PlatformAiMutationController@installProvider | ai-providers.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/ai/routing` | PlatformAiController@routing | ai-providers.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/ai/routing` | PlatformAiMutationController@updateRouting | ai-providers.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/ai/usage` | PlatformAiController@usage | ai-usage.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/alerts` | PlatformAlertController@index | alerts.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/alerts/{alert}` | PlatformAlertController@show | alerts.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/alerts/{alert}/acknowledge` | PlatformAlertController@acknowledge | alerts.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/alerts/{alert}/dismiss` | PlatformAlertController@dismiss | alerts.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/alerts/{alert}/resolve` | PlatformAlertController@resolve | alerts.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/alerts/count` | PlatformAlertController@count | alerts.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/audit/actions` | PlatformAuditLogController@actions | audit.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/audit/companies` | PlatformAuditLogController@companyLogs | audit.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/audit/platform` | PlatformAuditLogController@platformLogs | audit.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/automations` | AutomationController@index | automations.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/automations/run` | AutomationController@run | automations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/automations/runs` | AutomationController@runs | automations.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/automations/toggle` | AutomationController@toggle | automations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/aging` | PlatformFinancialController@aging | _BillingReconciliationTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/all-subscriptions` | PlatformBillingController@subscriptions | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/audit-export` | AuditExportController | _BillingForensicsTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/billing-policy` | PlatformBillingPolicyController@show | billingPolicy.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/billing-policy` | PlatformBillingPolicyController@update | billingPolicy.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/commercial-series` | PlatformCommercialSeriesController | _PlatformCommercialWidget.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/companies/{id}/consolidated-preview` | PlatformBillingController@consolidatedPreview | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/companies/{id}/financial-freeze` | PlatformFinancialController@freezeState | billing-financial.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/companies/{id}/financial-freeze` | PlatformFinancialController@toggleFreeze | billing-financial.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/config` | BillingConfigController@showConfig | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/config` | BillingConfigController@updateConfig | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/coupons` | CouponCrudController@index | _BillingCouponsTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/coupons` | CouponCrudController@store | _BillingCouponsTab.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/coupons/{id}` | CouponCrudController@update | _BillingCouponsTab.vue | CONSOMMÉ (frontend) |
| DELETE | `api/platform/billing/coupons/{id}` | CouponCrudController@destroy | _BillingCouponsTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/credit-notes` | PlatformBillingController@creditNotes | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/dead-letters` | PlatformRecoveryController@listDeadLetters | billing-metrics.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/drift-history` | PlatformFinancialController@driftHistory | billing-financial.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/drilldown/receivables` | PlatformFinancialController@receivablesDrilldown | _BillingDashboard.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/drilldown/revenue` | PlatformFinancialController@revenueDrilldown | _BillingDashboard.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/dunning` | PlatformBillingController@dunning | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/financial-periods` | PlatformFinancialController@financialPeriods | billing-financial.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/financial-periods/close` | PlatformFinancialController@closePeriod | billing-financial.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/forensics/snapshots` | PlatformFinancialController@forensicsSnapshots | billing-financial.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/forensics/timeline` | PlatformFinancialController@forensicsTimeline | billing-financial.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/invoices` | PlatformBillingController@invoices | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/invoices/{id}` | PlatformBillingController@invoiceDetail | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/invoices/{id}/pdf` | PlatformBillingController@invoicePdf | _BillingInvoicesTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/invoices/{invoice}/credit-note` | PlatformAdvancedMutationController@issueCreditNote | billing-invoices.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/invoices/{invoice}/dunning-transition` | PlatformAdvancedMutationController@forceDunningTransition | billing-invoices.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/invoices/{invoice}/mark-paid-offline` | PlatformInvoiceMutationController@markPaidOffline | billing-invoices.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/invoices/{invoice}/notes` | PlatformInvoiceMutationController@updateNotes | billing-invoices.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/invoices/{invoice}/refund` | PlatformAdvancedMutationController@refund | billing-invoices.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/invoices/{invoice}/retry-payment` | PlatformAdvancedMutationController@retryPayment | billing-invoices.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/invoices/{invoice}/void` | PlatformInvoiceMutationController@void | billing-invoices.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/invoices/{invoice}/write-off` | PlatformAdvancedMutationController@writeOff | billing-invoices.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/invoices/bulk-retry` | BillingBulkActionController@bulkRetry | billing-invoices.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/invoices/bulk-void` | BillingBulkActionController@bulkVoid | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/invoices/export` | BillingExportController | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/ledger/entries` | PlatformFinancialController@ledgerEntries | billing-financial.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/ledger/trial-balance` | PlatformFinancialController@trialBalance | billing-financial.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/metrics` | PlatformBillingMetricsController | billing-metrics.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/metrics-export` | BillingMetricsExportController | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/platform/billing/payment-modules` | PaymentModuleController@index | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/payment-modules/{providerKey}/activate` | PaymentModuleController@activate | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/payment-modules/{providerKey}/credentials` | PaymentModuleController@updateCredentials | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/payment-modules/{providerKey}/deactivate` | PaymentModuleController@deactivate | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/payment-modules/{providerKey}/health` | PaymentModuleController@health | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/payment-modules/{providerKey}/install` | PaymentModuleController@install | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/payment-rules` | PaymentMethodRuleController@index | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/payment-rules` | PaymentMethodRuleController@store | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/payment-rules/{id}` | PaymentMethodRuleController@update | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/billing/payment-rules/{id}` | PaymentMethodRuleController@destroy | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/payment-rules/preview` | PaymentMethodRuleController@preview | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/payments` | PlatformBillingController@payments | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/policies` | BillingConfigController@policies | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/policies` | BillingConfigController@updatePolicies | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/providers` | BillingConfigController@providers | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/reconcile` | PlatformFinancialController@reconcile | billing-financial.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/reconciliation` | PlatformFinancialController@reconciliation | _BillingReconciliationTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/recover-checkouts` | PlatformRecoveryController@recoverCheckouts | billing-metrics.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/recover-webhooks` | PlatformRecoveryController@recoverWebhooks | billing-metrics.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/recovery-status` | PlatformBillingController@recoveryStatus | billing-metrics.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/replay-dead-letters` | PlatformRecoveryController@replayAllDeadLetters | billing-metrics.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/billing/replay-dead-letters/{id}` | PlatformRecoveryController@replayDeadLetter | billing-metrics.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/scheduled-debits` | PlatformBillingController@scheduledDebits | _BillingScheduledDebitsTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/subscriptions` | BillingConfigController@subscriptions | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/subscriptions/{id}/approve` | BillingConfigController@approveSubscription | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/billing/subscriptions/{id}/reject` | BillingConfigController@rejectSubscription | billing-subscriptions.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/wallets` | PlatformBillingController@wallets | billing-invoices.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/widgets` | PlatformBillingWidgetsController@index | billing-metrics.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/billing/widgets/{key}` | PlatformBillingWidgetsController@show | billing-metrics.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies` | CompanyController@index | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{companyId}/company-requirements` | PlatformPresetEditorController@companyRequirements | _JobdomainAdvanced.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{companyId}/jobdomain-compatibility` | JobdomainModernizationController@companyCompatibility | _JobdomainAdvanced.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{id}` | CompanyController@show | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}` | CompanyProfileAdminController@update | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{id}/activity` | CompanyController@activity | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{id}/billing` | CompanyController@billing | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{id}/members` | CompanyController@members | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}/modules/{key}/disable` | CompanyModuleController@disable | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}/modules/{key}/enable` | CompanyModuleController@enable | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{id}/payment-methods` | CompanySubscriptionAdminController@paymentMethods | companies.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/companies/{id}/payment-methods/{pmId}` | CompanySubscriptionAdminController@deletePaymentMethod | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}/payment-methods/{pmId}/default` | CompanySubscriptionAdminController@setDefaultPaymentMethod | companies.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/companies/{id}/payment-methods/confirm` | CompanySubscriptionAdminController@confirmPaymentMethod | companies.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/companies/{id}/payment-methods/setup-intent` | CompanySubscriptionAdminController@createSetupIntent | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}/plan` | CompanyBillingAdminController@updatePlan | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{id}/plan-preview` | CompanyBillingAdminController@planChangePreview | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}/reactivate` | CompanyController@reactivate | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}/subscription/cancel` | CompanySubscriptionAdminController@cancelSubscription | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{id}/subscription/cancel-preview` | CompanySubscriptionAdminController@cancelPreview | tests | CONSOMMÉ (indirect, vérifié) |
| PUT | `api/platform/companies/{id}/subscription/extend-trial` | CompanySubscriptionAdminController@extendTrial | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}/subscription/undo-cancel` | CompanySubscriptionAdminController@undoCancelSubscription | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/companies/{id}/suspend` | CompanyController@suspend | companies.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/companies/{id}/wallet` | CompanyBillingAdminController@adjustWallet | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/companies/{id}/wallet-history` | CompanyBillingAdminController@walletHistory | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/company-users` | CompanyUserController@index | users.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/contract-templates` | PlatformContractController@index | index.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/contract-templates` | PlatformContractController@store | index.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/contract-templates/{id}` | PlatformContractController@show | index.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/contract-templates/{id}` | PlatformContractController@update | index.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/contract-templates/{id}/archive` | PlatformContractController@archive | index.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/contract-templates/{id}/legal-status` | PlatformContractController@legalStatus | index.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/contract-templates/{id}/publish` | PlatformContractController@publish | index.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/contract-templates/{id}/version` | PlatformContractController@version | index.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/contract-templates/preview-pdf` | PlatformContractController@previewPdf | index.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/contract-templates/variables` | PlatformContractController@variables | index.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/dashboard/attention` | DashboardCockpitController@attention | dashboard.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/dashboard/health` | DashboardCockpitController@health | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/platform/dashboard/layout` | DashboardLayoutController@show | tests | CONSOMMÉ (indirect, vérifié) — surface-engine |
| PUT | `api/platform/dashboard/layout` | DashboardLayoutController@update | tests | CONSOMMÉ (indirect, vérifié) — surface-engine |
| GET | `api/platform/dashboard/layout/presets` | DashboardLayoutController@presets | dashboard.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/dashboard/widgets/catalog` | DashboardWidgetController@catalog | tests | CONSOMMÉ (indirect, vérifié) — surface-engine |
| POST | `api/platform/dashboard/widgets/data` | DashboardWidgetController@batchResolve | companies.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documentation/articles` | PlatformDocArticleController@index | documentation.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/documentation/articles` | PlatformDocArticleController@store | documentation.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documentation/articles/{id}` | PlatformDocArticleController@show | documentation.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/documentation/articles/{id}` | PlatformDocArticleController@update | documentation.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/documentation/articles/{id}` | PlatformDocArticleController@destroy | documentation.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documentation/feedback-stats` | PlatformDocArticleController@feedbackStats | documentation.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documentation/groups` | PlatformDocGroupController@index | documentation.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/documentation/groups` | PlatformDocGroupController@store | documentation.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documentation/groups/{id}` | PlatformDocGroupController@show | documentation.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/documentation/groups/{id}` | PlatformDocGroupController@update | documentation.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/documentation/groups/{id}` | PlatformDocGroupController@destroy | documentation.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documentation/search-misses` | PlatformDocArticleController@searchMisses | documentation.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documentation/topics` | PlatformDocTopicController@index | documentation.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/documentation/topics` | PlatformDocTopicController@store | documentation.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documentation/topics/{id}` | PlatformDocTopicController@show | documentation.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/documentation/topics/{id}` | PlatformDocTopicController@update | documentation.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/documentation/topics/{id}` | PlatformDocTopicController@destroy | documentation.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documents` | DocumentTypeCatalogController@index | jobdomains.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/documents` | DocumentTypeCatalogController@store | jobdomains.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/documents/{id}` | DocumentTypeCatalogController@show | [id].vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/documents/{id}` | DocumentTypeCatalogController@update | [id].vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/documents/{id}/archive` | DocumentTypeCatalogController@archive | [id].vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/documents/{id}/restore` | DocumentTypeCatalogController@restore | [id].vue | CONSOMMÉ (frontend) |
| POST | `api/platform/documents/sync` | DocumentTypeCatalogController@sync | [id].vue | CONSOMMÉ (frontend) |
| GET | `api/platform/email/contacts` | EmailContactController@index | useEmailInbox.js | CONSOMMÉ (frontend) |
| GET | `api/platform/email/health` | EmailHealthController@index | _EmailHealthTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/health/test` | EmailHealthController@test | _EmailHealthTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/email/identity` | EmailSettingsController@showIdentity | _EmailSettingsTab.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/email/identity` | EmailSettingsController@updateIdentity | _EmailSettingsTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/inbound` | EmailInboundController@store | tests | EXTERNE (prestataire/webhook) (secret partagé, fail-closed) |
| GET | `api/platform/email/inbox` | EmailInboxController@index | useEmailInbox.js | CONSOMMÉ (frontend) |
| GET | `api/platform/email/inbox/{id}` | EmailInboxController@show | useEmailInbox.js | CONSOMMÉ (frontend) |
| POST | `api/platform/email/inbox/{id}/read` | EmailInboxController@markRead | useEmailInbox.js | CONSOMMÉ (frontend) |
| POST | `api/platform/email/inbox/{id}/reply` | EmailInboxController@reply | useEmailInbox.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/email/inbox/{id}/status` | EmailInboxController@updateStatus | useEmailInbox.js | CONSOMMÉ (frontend) |
| POST | `api/platform/email/inbox/attachments` | EmailAttachmentController@store | useEmailInbox.js | CONSOMMÉ (frontend) |
| GET | `api/platform/email/inbox/attachments/{id}/download` | EmailAttachmentController@download | tests | CONSOMMÉ (indirect, vérifié) |
| POST | `api/platform/email/inbox/bulk` | EmailInboxController@bulkAction | useEmailInbox.js | CONSOMMÉ (frontend) |
| POST | `api/platform/email/inbox/compose` | EmailInboxController@compose | useEmailInbox.js | CONSOMMÉ (frontend) |
| POST | `api/platform/email/inbox/draft` | EmailDraftController@store | useEmailInbox.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/email/inbox/draft/{id}` | EmailDraftController@update | useEmailInbox.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/email/inbox/draft/{id}` | EmailDraftController@destroy | useEmailInbox.js | CONSOMMÉ (frontend) |
| POST | `api/platform/email/inbox/draft/{id}/send` | EmailDraftController@send | useEmailInbox.js | CONSOMMÉ (frontend) |
| POST | `api/platform/email/inbox/fetch-now` | EmailInboxController@fetchNow | useEmailInbox.js | CONSOMMÉ (frontend) |
| GET | `api/platform/email/inbox/unread-count` | EmailInboxController@unreadCount | useEmailInbox.js | CONSOMMÉ (frontend) |
| GET | `api/platform/email/logs` | EmailLogController@index | _EmailLogsTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/logs/{id}/retry` | EmailLogController@retry | _EmailLogsTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/email/orchestration` | EmailOrchestrationController@index | _EmailOrchestrationTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/orchestration` | EmailOrchestrationController@store | _EmailOrchestrationTab.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/email/orchestration/{id}` | EmailOrchestrationController@update | _EmailOrchestrationTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/email/settings` | EmailSettingsController@show | _EmailSettingsTab.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/email/settings` | EmailSettingsController@update | _EmailSettingsTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/settings/fetch-inbox` | EmailSettingsController@fetchInbox | _EmailSettingsTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/settings/test` | EmailSettingsController@test | _EmailSettingsTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/settings/test-imap` | EmailSettingsController@testImap | _EmailSettingsTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/email/templates` | EmailLogController@templates | — | CONSOMMÉ (indirect, vérifié) |
| POST | `api/platform/email/templates/configurable` | EmailTemplateController@store | _EmailLogsTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/email/templates/configurable` | EmailTemplateController@index | _EmailLogsTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/email/templates/configurable/{key}` | EmailTemplateController@show | _EmailTemplatesTab.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/email/templates/configurable/{key}` | EmailTemplateController@update | _EmailTemplatesTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/templates/configurable/{key}/preview` | EmailTemplateController@preview | _EmailTemplatesTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/email/templates/configurable/{key}/test` | EmailTemplateController@sendTest | _EmailTemplatesTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/feature-flags` | FeatureFlagController@index | _SettingsFeatureFlags.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/feature-flags` | FeatureFlagController@store | _SettingsFeatureFlags.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/feature-flags/{key}` | FeatureFlagController@update | _SettingsFeatureFlags.vue | CONSOMMÉ (frontend) |
| DELETE | `api/platform/feature-flags/{key}` | FeatureFlagController@destroy | _SettingsFeatureFlags.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/feature-flags/{key}/company-override` | FeatureFlagController@companyOverride | — | ORPHELIN confirmé (aucun appel frontend, recherche élargie) |
| POST | `api/platform/feature-flags/{key}/toggle` | FeatureFlagController@toggle | _SettingsFeatureFlags.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/field-activations` | FieldActivationController@index | fields.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/field-activations` | FieldActivationController@upsert | fields.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/field-definitions` | FieldDefinitionController@index | fields.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/field-definitions` | FieldDefinitionController@store | fields.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/field-definitions/{id}` | FieldDefinitionController@update | fields.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/field-definitions/{id}` | FieldDefinitionController@destroy | fields.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/font-families` | TypographyController@createFamily | settings.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/font-families/{familyId}` | TypographyController@deleteFamily | settings.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/font-families/{familyId}/fonts` | TypographyController@uploadFont | settings.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/font-families/{familyId}/fonts/{fontId}` | TypographyController@deleteFont | settings.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/forgot-password` | PlatformPasswordResetController@forgotPassword | forgot-password.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/fx-rates` | FxRateController@index | markets.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/fx-rates/refresh` | FxRateController@refresh | markets.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/general-settings` | GeneralSettingsController@show | _SettingsGeneral.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/general-settings` | GeneralSettingsController@update | _SettingsGeneral.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/heartbeat` | Closure | tests | CONSOMMÉ (indirect, vérifié) — useSessionGovernance |
| GET | `api/platform/jobdomains` | JobdomainController@index | jobdomains.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/jobdomains` | JobdomainController@store | jobdomains.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/jobdomains/{id}` | JobdomainController@show | jobdomains.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/jobdomains/{id}` | JobdomainController@update | jobdomains.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/jobdomains/{id}` | JobdomainController@destroy | jobdomains.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/jobdomains/{id}/initialization-plan` | JobdomainModernizationController@initializationPlan | _JobdomainAdvanced.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/jobdomains/{id}/legacy-drafts` | JobdomainModernizationController@legacyDraft | _JobdomainAdvanced.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/jobdomains/{id}/presets/{kind}/{presetId}/publish` | JobdomainModernizationController@presetPublish | _JobdomainPresetTab.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/jobdomains/{id}/presets/{kind}/draft` | JobdomainModernizationController@presetDraft | _JobdomainPresetTab.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/jobdomains/{id}/profile` | JobdomainModernizationController@profile | [id].vue | CONSOMMÉ (frontend) |
| GET | `api/platform/jobdomains/{jobdomain}/preset-recommendations` | PlatformPresetController@recommendations | [id].vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/jobdomains/{jobdomain}/preset-recommendations` | PlatformPresetController@setRecommendations | [id].vue | CONSOMMÉ (frontend) |
| GET | `api/platform/jobdomains/{jobdomainKey}/overlays` | JobdomainOverlayController@index | tests | ORPHELIN UI depuis ADR-799 (_MarketOverlaySelector plus monté) |
| PUT | `api/platform/jobdomains/{jobdomainKey}/overlays/{marketKey}` | JobdomainOverlayController@upsert | jobdomains.store.js | ORPHELIN UI depuis ADR-799 (méthode du store jamais appelée) |
| DELETE | `api/platform/jobdomains/{jobdomainKey}/overlays/{marketKey}` | JobdomainOverlayController@destroy | jobdomains.store.js | ORPHELIN UI depuis ADR-799 (méthode du store jamais appelée) |
| GET | `api/platform/languages` | LanguageController@index | markets.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/languages` | LanguageController@store | markets.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/languages/{id}` | LanguageController@update | markets.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/languages/{id}` | LanguageController@destroy | markets.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/languages/{id}/toggle-active` | LanguageController@toggleActive | markets.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/languages/export` | LanguageController@export | markets.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/languages/import-apply` | LanguageController@importApply | markets.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/legal-statuses/{id}` | LegalStatusController@update | markets.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/legal-statuses/{id}` | LegalStatusController@destroy | markets.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/login` | PlatformAuthController@login | runtime.js | CONSOMMÉ (frontend) |
| POST | `api/platform/logout` | PlatformAuthController@logout | platformAuth.js | CONSOMMÉ (frontend) |
| GET | `api/platform/maintenance-settings` | MaintenanceSettingsController@show | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/maintenance-settings` | MaintenanceSettingsController@update | settings.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/maintenance/my-ip` | MaintenanceSettingsController@myIp | settings.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/markets` | MarketCrudController@index | markets.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/markets` | MarketCrudController@store | markets.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/markets/{id}` | MarketCrudController@update | markets.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/markets/{id}/set-default` | MarketCrudController@setDefault | markets.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/markets/{id}/toggle-active` | MarketCrudController@toggleActive | markets.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/markets/{key}` | MarketCrudController@show | markets.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/markets/{marketKey}/legal-statuses` | LegalStatusController@store | markets.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/markets/{marketKey}/legal-statuses/reorder` | LegalStatusController@reorder | markets.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/markets/export` | MarketCrudController@export | markets.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/markets/import-apply` | MarketCrudController@importApply | markets.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/markets/import-preview` | MarketCrudController@importPreview | markets.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/me` | PlatformAuthController@me | platformAuth.js | CONSOMMÉ (frontend) |
| GET | `api/platform/me/notification-preferences` | PlatformAccountController@notificationPreferences | notification.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/me/notification-preferences` | PlatformAccountController@updateNotificationPreferences | notification.js | CONSOMMÉ (frontend) |
| GET | `api/platform/me/notifications` | PlatformNotificationController@index | notification.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/me/notifications/{id}` | PlatformNotificationController@destroy | tests | CONSOMMÉ (indirect, vérifié) |
| POST | `api/platform/me/notifications/{id}/read` | PlatformNotificationController@markRead | tests | CONSOMMÉ (indirect, vérifié) |
| POST | `api/platform/me/notifications/read-all` | PlatformNotificationController@markAllRead | tests | CONSOMMÉ (indirect, vérifié) |
| GET | `api/platform/me/notifications/unread-count` | PlatformNotificationController@unreadCount | tests | CONSOMMÉ (indirect, vérifié) |
| PUT | `api/platform/me/password` | PlatformAccountController@updatePassword | notification.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/me/profile` | PlatformAccountController@updateProfile | notification.js | CONSOMMÉ (frontend) |
| GET | `api/platform/modules` | ModuleController@index | settings.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/modules/{key}` | ModuleController@show | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/modules/{key}/config` | ModuleController@updateConfig | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/modules/{key}/toggle` | ModuleController@toggle | settings.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/modules/sync` | ModuleController@sync | settings.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/nav` | NavController@platform | nav.js | CONSOMMÉ (frontend) |
| GET | `api/platform/notifications/topics` | PlatformNotificationTopicController@index | notifications.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/notifications/topics/{key}` | PlatformNotificationTopicController@update | notifications.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/notifications/topics/{key}/toggle` | PlatformNotificationTopicController@toggle | notifications.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/onboarding/funnel` | OnboardingFunnelController | _DashboardFunnel.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/organization-presets` | PlatformOrganizationPresetController@index | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/organization-presets` | PlatformOrganizationPresetController@store | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/organization-presets/{id}` | PlatformOrganizationPresetController@show | [id].vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/organization-presets/{id}` | PlatformOrganizationPresetController@update | [id].vue | CONSOMMÉ (frontend) |
| POST | `api/platform/organization-presets/{id}/archive` | PlatformOrganizationPresetController@archive | index.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/organization-presets/{id}/duplicate` | PlatformOrganizationPresetController@duplicate | [id].vue | CONSOMMÉ (frontend) |
| POST | `api/platform/organization-presets/{id}/publish` | PlatformOrganizationPresetController@publish | [id].vue | CONSOMMÉ (frontend) |
| GET | `api/platform/organization-presets/{id}/simulate` | PlatformOrganizationPresetController@simulate | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/platform/organization-presets/meta` | PlatformOrganizationPresetController@meta | [id].vue | CONSOMMÉ (frontend) |
| GET | `api/platform/permissions` | PermissionController@index | roles.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/plans` | PlanCrudController@index | companies.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/plans` | PlanCrudController@store | companies.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/plans/{id}` | PlanCrudController@update | plans.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/plans/{id}/toggle-active` | PlanCrudController@toggleActive | plans.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/plans/{key}` | PlanCrudController@show | plans.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/plans/{key}/migration-subscriptions` | PlanCrudController@migrationSubscriptions | plans.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/plans/{key}/pricing-impact` | PlanCrudController@pricingImpact | plans.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/platform-users` | UserController@index | users.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/platform-users` | UserController@store | users.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/platform-users/{id}` | UserController@update | users.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/platform-users/{id}` | UserController@destroy | users.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/platform-users/{id}` | UserController@show | users.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/platform-users/{id}/password` | UserController@setPassword | users.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/platform-users/{id}/reset-password` | PlatformPasswordResetController@adminResetPassword | users.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/presets` | PlatformPresetController@index | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/presets` | PlatformPresetController@store | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| PUT | `api/platform/presets/{id}` | PlatformPresetEditorController@update | _PresetEditorDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/presets/{id}/archive` | PlatformPresetEditorController@archive | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/presets/{id}/new-version` | PlatformPresetController@newVersion | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/presets/{kind}/{from}/diff/{to}` | PlatformPresetController@diff | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/presets/{kind}/{id}` | JobdomainModernizationController@presetDetail | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/presets/{kind}/{id}/companies/{companyId}/apply` | PlatformPresetController@apply | tests | ORPHELIN UI : backend testé, aucune interface (volontaire ADR-793 : pas d application silencieuse) |
| GET | `api/platform/presets/{kind}/{id}/companies/{companyId}/preview` | PlatformPresetController@preview | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/presets/{kind}/{id}/publish` | PlatformPresetController@publish | _CatalogPresets.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/presets/meta` | PlatformPresetEditorController@meta | _PresetEditorDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/platform/pricing/confirm-price` | PlanCrudController@confirmContractedPrice | plans.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/pricing/migration/apply` | PlanCrudController@migrationApply | plans.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/pricing/migration/preview` | PlanCrudController@migrationPreview | plans.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/pricing/review-subscriptions` | PlanCrudController@reviewSubscriptions | plans.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/realtime/connections` | RealtimeGovernanceController@connections | realtime.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/realtime/flush` | RealtimeGovernanceController@flush | realtime.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/realtime/kill-switch` | RealtimeGovernanceController@killSwitch | realtime.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/realtime/metrics` | RealtimeGovernanceController@metrics | realtime.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/realtime/status` | RealtimeGovernanceController@status | realtime.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/realtime/stream` | RealtimeStreamController | — | CONSOMMÉ (indirect, vérifié) — RealtimeClient (SSE) |
| POST | `api/platform/reset-password` | PlatformPasswordResetController@resetPassword | reset-password.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/roles` | RoleController@index | roles.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/roles` | RoleController@store | roles.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/roles/{id}` | RoleController@update | roles.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/roles/{id}` | RoleController@destroy | roles.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/search` | PlatformSearchController | NavSearchBar.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/security/alert-types` | SecurityAlertController@alertTypes | security.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/security/alerts` | SecurityAlertController@index | security.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/security/alerts/{id}/acknowledge` | SecurityAlertController@acknowledge | security.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/security/alerts/{id}/false-positive` | SecurityAlertController@falsePositive | security.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/security/alerts/{id}/resolve` | SecurityAlertController@resolve | security.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/session-settings` | SessionSettingsController@show | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/session-settings` | SessionSettingsController@update | settings.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/support/metrics` | PlatformSupportTicketController@metrics | support.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/support/tickets` | PlatformSupportTicketController@index | support.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/support/tickets/{id}` | PlatformSupportTicketController@show | support.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/support/tickets/{id}/assign` | PlatformSupportTicketController@assign | support.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/support/tickets/{id}/close` | PlatformSupportTicketController@close | support.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/support/tickets/{id}/internal-notes` | PlatformSupportMessageController@storeInternal | support.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/support/tickets/{id}/messages` | PlatformSupportMessageController@index | support.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/support/tickets/{id}/messages` | PlatformSupportMessageController@store | support.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/support/tickets/{id}/priority` | PlatformSupportTicketController@updatePriority | support.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/support/tickets/{id}/resolve` | PlatformSupportTicketController@resolve | support.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/system/health` | SystemHealthController@index | _OperationsHealth.vue | CONSOMMÉ (frontend) |
| GET | `api/platform/theme` | ThemeController@show | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/theme` | ThemeController@update | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/theme-preference` | PlatformThemePreferenceController@update | tests | CONSOMMÉ (indirect, vérifié) |
| GET | `api/platform/translations` | TranslationController@index | translations.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/translations/{id}` | TranslationController@update | translations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/translations/{locale}/{namespace}` | TranslationController@show | translations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/translations/export/{locale}` | TranslationController@export | translations.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/translations/import-apply` | TranslationController@importApply | translations.store.js | CONSOMMÉ (frontend) |
| POST | `api/platform/translations/import-preview` | TranslationController@importPreview | translations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/translations/matrix` | TranslationMatrixController@index | translations.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/translations/matrix` | TranslationMatrixController@update | translations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/translations/namespaces` | TranslationMatrixController@namespaces | translations.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/platform/translations/overrides/{id}` | OverrideController@destroy | translations.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/translations/overrides/{marketKey}` | OverrideController@upsert | translations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/translations/overrides/{marketKey}/{locale}` | OverrideController@index | translations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/translations/stats` | TranslationMatrixController@stats | translations.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/typography` | TypographyController@show | settings.store.js | CONSOMMÉ (frontend) |
| PUT | `api/platform/typography` | TypographyController@update | settings.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/usage/company/{companyId}` | UsageMonitoringController@company | usage.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/usage/overview` | UsageMonitoringController@overview | usage.store.js | CONSOMMÉ (frontend) |
| GET | `api/platform/world-settings` | WorldSettingsController@show | — | ORPHELIN confirmé (aucun appel frontend, recherche élargie) |
| PUT | `api/platform/world-settings` | WorldSettingsController@update | — | ORPHELIN confirmé (aucun appel frontend, recherche élargie) |
| GET | `api/profile` | UserProfileController@show | notification.js | CONSOMMÉ (frontend) |
| PUT | `api/profile` | UserProfileController@update | notification.js | CONSOMMÉ (frontend) |
| POST | `api/profile/avatar` | UserProfileController@updateAvatar | AccountSettingsAccount.vue | CONSOMMÉ (frontend) |
| POST | `api/profile/disclosures` | UserProfileController@shareFields | tests | ORPHELIN UI : backend testé, aucune interface |
| POST | `api/profile/disclosures/withdrawal` | UserProfileController@requestWithdrawal | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/profile/documents` | SelfDocumentController@index | employee-home.store.js | CONSOMMÉ (frontend) |
| POST | `api/profile/documents/{code}` | SelfDocumentController@upload | AccountSettingsDocuments.vue | CONSOMMÉ (frontend) |
| GET | `api/profile/documents/{code}/download` | SelfDocumentController@download | AccountSettingsDocuments.vue | CONSOMMÉ (frontend) |
| GET | `api/profile/documents/{code}/versions` | SelfDocumentController@versions | AccountSettingsDocuments.vue | CONSOMMÉ (frontend) |
| GET | `api/profile/documents/{code}/versions/{versionId}/download` | SelfDocumentController@downloadVersion | tests | CONSOMMÉ (indirect, vérifié) (URL construite) |
| PUT | `api/profile/password` | UserProfileController@updatePassword | AccountSettingsSecurity.vue | CONSOMMÉ (frontend) |
| GET | `api/profile/payslips` | PublishedPayslipController@self | MyPayslips.vue | CONSOMMÉ (frontend) |
| GET | `api/profile/payslips/{id}/download` | PublishedPayslipController@downloadSelf | MyPayslips.vue | CONSOMMÉ (frontend) |
| GET | `api/profile/space` | PersonalSpaceController@show | TimeSpaceHeader.vue | CONSOMMÉ (frontend) |
| GET | `api/public/addons` | PublicAddonController@index | usePublicAddons.js | CONSOMMÉ (frontend) |
| GET | `api/public/fields` | PublicFieldController@companyFields | usePublicFields.js | CONSOMMÉ (frontend) |
| GET | `api/public/i18n/{locale}/{namespace?}` | PublicI18nController@bundle | — | CONSOMMÉ (indirect, vérifié) — plugins/vuetify |
| GET | `api/public/markets` | PublicMarketController@index | usePublicMarkets.js | CONSOMMÉ (frontend) |
| GET | `api/public/markets/{key}` | PublicMarketController@show | usePublicMarkets.js | CONSOMMÉ (frontend) |
| GET | `api/public/onboarding` | OnboardingPortalController@show | index.vue | CONSOMMÉ (frontend) |
| POST | `api/public/onboarding/close` | OnboardingPortalController@close | — | CONSOMMÉ (indirect, vérifié) — portail recrue (ofetch baseURL) |
| POST | `api/public/onboarding/code` | OnboardingPortalController@sendCode | — | CONSOMMÉ (indirect, vérifié) — portail recrue |
| POST | `api/public/onboarding/documents/{code}` | OnboardingPortalController@upload | — | CONSOMMÉ (indirect, vérifié) — portail recrue |
| POST | `api/public/onboarding/session` | OnboardingPortalController@open | — | CONSOMMÉ (indirect, vérifié) — portail recrue |
| POST | `api/public/onboarding/submit` | OnboardingPortalController@submit | — | CONSOMMÉ (indirect, vérifié) — portail recrue |
| PUT | `api/public/onboarding/values` | OnboardingPortalController@save | — | CONSOMMÉ (indirect, vérifié) — portail recrue |
| POST | `api/public/onboarding/verify` | OnboardingPortalController@verify | — | CONSOMMÉ (indirect, vérifié) — portail recrue |
| GET | `api/public/plans` | PublicPlanController@index | usePublicPlans.js | CONSOMMÉ (frontend) |
| POST | `api/public/plans/estimate-registration` | PublicPlanController@estimateRegistration | _RegisterStepSummary.vue | CONSOMMÉ (frontend) |
| GET | `api/public/plans/preview` | PublicPlanController@preview | usePublicPlans.js | CONSOMMÉ (frontend) |
| GET | `api/public/signature/{token}` | SignerController@show | [token].vue | CONSOMMÉ (frontend) |
| POST | `api/public/signature/{token}/decline` | SignerController@decline | [token].vue | CONSOMMÉ (frontend) |
| GET | `api/public/signature/{token}/document` | SignerController@document | [token].vue | CONSOMMÉ (frontend) |
| POST | `api/public/signature/{token}/prepare` | SignerController@prepare | [token].vue | CONSOMMÉ (frontend) |
| POST | `api/public/signature/{token}/sign` | SignerController@sign | [token].vue | CONSOMMÉ (frontend) |
| GET | `api/public/theme` | PublicThemeController | usePublicTheme.js | CONSOMMÉ (frontend) |
| POST | `api/public/validate-coupon` | PublicCouponController | _RegisterStepSummary.vue | CONSOMMÉ (frontend) |
| GET | `api/public/version` | Closure | versionCheck.js | CONSOMMÉ (frontend) |
| GET | `api/public/world` | PublicWorldController | world.js | CONSOMMÉ (frontend) |
| GET | `api/realtime/stream` | RealtimeStreamController | tests | CONSOMMÉ (indirect, vérifié) — RealtimeClient (SSE) |
| POST | `api/register` | AuthController@register | auth.js | CONSOMMÉ (frontend) |
| POST | `api/register/confirm-payment` | ConfirmRegistrationPaymentController | auth.js | CONSOMMÉ (frontend) |
| POST | `api/register/funnel-track` | FunnelTrackController | — | ORPHELIN confirmé (aucun appel frontend, recherche élargie) (useAnalytics ne fait que logger) |
| POST | `api/reset-password` | PasswordResetController@resetPassword | notification.js | CONSOMMÉ (frontend) |
| POST | `api/runtime-error` | RuntimeErrorController | errorReporter.js | CONSOMMÉ (frontend) |
| GET | `api/shipments` | ShipmentController@index | notification.js | CONSOMMÉ (frontend) |
| POST | `api/shipments` | ShipmentController@store | notification.js | CONSOMMÉ (frontend) |
| GET | `api/shipments/{id}` | ShipmentController@show | shipment.store.js | CONSOMMÉ (frontend) |
| POST | `api/shipments/{id}/assign` | ShipmentController@assign | shipment.store.js | CONSOMMÉ (frontend) |
| PUT | `api/shipments/{id}/status` | ShipmentController@changeStatus | shipment.store.js | CONSOMMÉ (frontend) |
| GET | `api/support/tickets` | SupportTicketController@index | support.store.js | CONSOMMÉ (frontend) |
| POST | `api/support/tickets` | SupportTicketController@store | support.store.js | CONSOMMÉ (frontend) |
| GET | `api/support/tickets/{id}` | SupportTicketController@show | support.store.js | CONSOMMÉ (frontend) |
| GET | `api/support/tickets/{id}/messages` | SupportMessageController@index | support.store.js | CONSOMMÉ (frontend) |
| POST | `api/support/tickets/{id}/messages` | SupportMessageController@store | support.store.js | CONSOMMÉ (frontend) |
| PUT | `api/theme-preference` | ThemePreferenceController@update | notification.js | CONSOMMÉ (frontend) |
| GET | `api/theme/role-visibility` | ThemeRoleVisibilityController@index | _ThemeRoleVisibility.vue | CONSOMMÉ (frontend) |
| PUT | `api/theme/role-visibility` | ThemeRoleVisibilityController@update | _ThemeRoleVisibility.vue | CONSOMMÉ (frontend) |
| POST | `api/webhooks/billing` | WebhookController | — | EXTERNE (prestataire/webhook) |
| POST | `api/webhooks/payments/{providerKey}` | PaymentWebhookController | tests | EXTERNE (prestataire/webhook) |
| POST | `api/webhooks/signatures/{provider}` | SignatureWebhookController | tests | EXTERNE (prestataire/webhook) |
| GET | `api/whoami` | AuthController@whoami | notification.js | CONSOMMÉ (frontend) |
| GET | `api/workflows` | WorkflowRuleController@index | notification.js | CONSOMMÉ (frontend) |
| POST | `api/workflows` | WorkflowRuleController@store | notification.js | CONSOMMÉ (frontend) |
| GET | `api/workflows/{workflowRule}` | WorkflowRuleController@show | workflows.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workflows/{workflowRule}` | WorkflowRuleController@update | workflows.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workflows/{workflowRule}` | WorkflowRuleController@destroy | workflows.store.js | CONSOMMÉ (frontend) |
| GET | `api/workflows/{workflowRule}/logs` | WorkflowRuleController@logs | workflows.store.js | CONSOMMÉ (frontend) |
| GET | `api/workflows/triggers` | WorkflowRuleController@triggers | workflows.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/config/breaks` | WorkforceConfigController@breaks | _WorkforceRulesSection.vue | CONSOMMÉ (frontend) |
| PUT | `api/workforce/config/breaks/{rule}` | WorkforceConfigController@setBreak | _WorkforceRulesSection.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/config/week-start` | WorkforceConfigController@weekStart | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/config/week-start` | WorkforceConfigController@setWeekStart | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/config/work-time` | WorkforceConfigController@workTime | _WorkforceRulesSection.vue | CONSOMMÉ (frontend) |
| PUT | `api/workforce/config/work-time/{rule}` | WorkforceConfigController@setWorkTime | _WorkforceRulesSection.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/contract/generate` | ContractController@generate | contract.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/contract/preview` | ContractController@preview | contract.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/contract/preview-pdf` | ContractController@previewPdf | contract.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/contract/templates` | ContractController@templates | contract.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/contract/templates/{id}` | ContractController@showTemplate | contract.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/contract/templates/{id}` | ContractController@updateTemplate | contract.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/contract/templates/{id}/archive` | ContractController@archive | contract.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/contract/templates/{id}/default` | ContractController@setDefault | contract.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/contract/templates/{id}/duplicate` | ContractController@duplicate | contract.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/contract/templates/{id}/publish` | ContractController@publish | contract.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/contract/templates/{id}/version` | ContractController@createVersion | contract.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/contract/templates/variables` | ContractController@variables | contract.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/conventions` | ContractController@conventions | employees.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/dashboard/priorities` | WorkforceDashboardController@priorities | workforce-dashboard.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/dashboard/statistics` | WorkforceDashboardController@statistics | workforce-dashboard.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/departments` | DepartmentController@index | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/departments` | DepartmentController@store | employees.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/departments/{id}` | DepartmentController@update | employees.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/departments/{id}` | DepartmentController@destroy | employees.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/documents` | WorkforceDocumentController@index | tests | CONSOMMÉ (indirect, vérifié) — workforce-documents.store |
| GET | `api/workforce/documents/{id}` | WorkforceDocumentController@show | payroll.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/documents/{id}/download` | WorkforceDocumentController@download | workforce-documents.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/documents/{id}/signed` | EmployeeGeneratedDocumentController@signed | workforce-documents.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/documents/by-employee/{employeeId}` | EmployeeGeneratedDocumentController@byEmployee | workforce-documents.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/documents/generate` | WorkforceDocumentController@generate | workforce-documents.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/documents/payroll-run/{runId}` | WorkforceDocumentController@forPayrollRun | workforce-documents.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/documents/payslip-drafts` | WorkforceDocumentController@generatePayslipDrafts | payroll.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/documents/payslip-official` | WorkforceDocumentController@generateOfficialPayslips | payroll.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/documents/statistics` | WorkforceDocumentController@statistics | workforce-documents.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/documents/templates` | WorkforceDocumentController@templates | workforce-documents.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/dsn` | DsnDeclarationController@index | — | CONSOMMÉ (indirect, vérifié) — dsn.store |
| GET | `api/workforce/dsn/{id}` | DsnDeclarationController@show | dsn.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/dsn/{id}/history` | DsnDeclarationController@history | dsn.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/dsn/{id}/poll` | DsnDeclarationController@poll | dsn.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/dsn/{id}/submit` | DsnDeclarationController@submit | dsn.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/dsn/{id}/validation` | DsnDeclarationController@validationErrors | dsn.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/dsn/capability` | DsnDeclarationController@capability | dsn.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/dsn/export` | DsnDeclarationController@export | dsn.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/dsn/statistics` | DsnDeclarationController@statistics | dsn.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees` | EmployeeController@index | EmployeePicker.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees` | EmployeeController@store | EmployeePicker.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/{employeeId}/contracts` | ContractController@index | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{employeeId}/contracts` | ContractController@store | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{employeeId}/contracts/{contractId}/activate` | ContractController@activate | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{employeeId}/contracts/{contractId}/cancel` | ContractController@cancel | _EmployeeContract.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/{employeeId}/leave-balances` | LeaveController@balances | _EmployeeLeaveSection.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/{id}` | EmployeeController@show | person.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/employees/{id}` | EmployeeController@update | person.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/{id}/access` | EmployeeController@accessStatus | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{id}/access` | EmployeeController@grantAccess | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{id}/access/resend` | EmployeeController@resendAccess | person.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{id}/access/restore` | EmployeeController@restoreAccess | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{id}/access/revoke` | EmployeeController@revokeAccess | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{id}/access/suspend` | EmployeeController@suspendAccess | employees.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/{id}/fields` | EmployeeFieldController@show | _EmployeeFields.vue | CONSOMMÉ (frontend) |
| PUT | `api/workforce/employees/{id}/fields` | EmployeeFieldController@update | _EmployeeFields.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/{id}/file` | EmployeeController@file | [id].vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{id}/link-account` | EmployeeAccountLinkController@linkAccount | person.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/{id}/terminate` | EmployeeController@terminate | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/bulk` | EmployeeController@bulk | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/bulk/preview` | EmployeeController@bulkPreview | employees.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/export` | EmployeeController@export | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/from-member` | EmployeeAccountLinkController@createForMember | person.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/import` | EmployeeController@import | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/employees/import/preview` | EmployeeController@importPreview | employees.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/import/template` | EmployeeController@importTemplate | employees.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/link-candidates` | EmployeeAccountLinkController@candidates | person.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/employees/summary` | EmployeeController@summary | employees.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/job-roles` | JobRoleController@index | employees.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/job-roles` | JobRoleController@store | employees.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/job-roles/{id}` | JobRoleController@update | employees.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/job-roles/{id}` | JobRoleController@destroy | employees.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/leaves` | LeaveController@index | leaves.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/leaves` | LeaveController@store | leaves.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/leaves/{id}` | LeaveController@show | leaves.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/leaves/{id}/approve` | LeaveController@approve | leaves.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/leaves/{id}/cancel` | LeaveController@cancel | leaves.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/leaves/{id}/impacted-shifts` | LeaveController@impactedShifts | leaves.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/leaves/{id}/reject` | LeaveController@reject | leaves.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/leaves/calendar` | LeaveController@calendar | leaves.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/leaves/deduction-preview` | LeaveController@deductionPreview | leaves.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/leaves/statistics` | LeaveController@statistics | leaves.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/leaves/types` | LeaveTypeController@index | leaves.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/leaves/types` | LeaveTypeController@store | leaves.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/leaves/types/{id}` | LeaveTypeController@update | leaves.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/leaves/types/{id}` | LeaveTypeController@destroy | leaves.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/me` | EmployeeSelfController@profile | workforce-clock.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/me/break/end` | EmployeeSelfController@endBreak | workforce-clock.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/me/break/start` | EmployeeSelfController@startBreak | workforce-clock.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/me/clock-in` | EmployeeSelfController@clockIn | workforce-clock.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/me/clock-out` | EmployeeSelfController@clockOut | workforce-clock.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/me/documents` | EmployeeSelfController@documents | me.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/me/generated-documents/{id}/download` | EmployeeGeneratedDocumentController@downloadOwn | tests | CONSOMMÉ (indirect, vérifié) |
| GET | `api/workforce/me/hours` | EmployeeSelfHoursController | me.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/me/leave-balances` | EmployeeSelfController@leaveBalances | tests | CONSOMMÉ (indirect, vérifié) |
| GET | `api/workforce/me/leaves` | EmployeeSelfController@leaves | workforce-clock.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/me/leaves` | EmployeeSelfController@createLeave | workforce-clock.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/me/leaves/deduction-preview` | EmployeeSelfController@leaveDeductionPreview | workforce-clock.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/me/shifts` | EmployeeSelfController@shifts | employee-home.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/me/today` | EmployeeSelfController@today | workforce-clock.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/onboarding` | WorkforceOnboardingController@show | onboarding.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/onboarding-files` | OnboardingController@index | _OnboardingInviteDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding-files` | OnboardingController@store | _OnboardingInviteDialog.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/onboarding-files/{id}` | OnboardingController@show | _OnboardingInviteDialog.vue | CONSOMMÉ (frontend) |
| PUT | `api/workforce/onboarding-files/{id}/contact` | OnboardingController@contact | _OnboardingReviewDialog.vue | CONSOMMÉ (frontend) |
| PUT | `api/workforce/onboarding-files/{id}/items` | OnboardingController@revise | _OnboardingReviewDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding-files/{id}/items/preview` | OnboardingController@previewRevision | tests | CONSOMMÉ (indirect, vérifié) |
| POST | `api/workforce/onboarding-files/{id}/link` | OnboardingController@link | _OnboardingInviteDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding-files/{id}/request-changes` | OnboardingController@requestChanges | _OnboardingReviewDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding-files/{id}/revoke` | OnboardingController@revoke | _OnboardingReviewDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding-files/{id}/send` | OnboardingController@send | _OnboardingInviteDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding-files/{id}/uploads/{upload}/rescan` | OnboardingController@rescan | tests | ORPHELIN UI : backend testé, aucune interface |
| POST | `api/workforce/onboarding-files/{id}/validate` | OnboardingController@validateFile | _OnboardingReviewDialog.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/onboarding-files/proposal` | OnboardingController@proposal | _OnboardingInviteDialog.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding/defer` | WorkforceOnboardingController@deferStep | onboarding.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding/finish` | WorkforceOnboardingController@finish | onboarding.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding/start` | WorkforceOnboardingController@start | onboarding.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/onboarding/step` | WorkforceOnboardingController@completeStep | onboarding.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/organization/preset/apply` | OrganizationPresetController@apply | _OrganizationPresetCard.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/organization/preset/preview` | OrganizationPresetController@preview | _OrganizationPresetCard.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/organization/profile` | OrganizationPresetController@profile | onboarding.vue | CONSOMMÉ (frontend) |
| PUT | `api/workforce/organization/profile` | OrganizationPresetController@setProfile | onboarding.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/payroll` | PayrollRunController@index | PayrollRunPicker.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/payroll` | PayrollRunController@store | PayrollRunPicker.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/payroll/{id}` | PayrollRunController@show | payroll.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/payroll/{id}` | PayrollRunController@destroy | payroll.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/payroll/{id}/calculations` | PayrollRunController@calculations | payroll.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/payroll/{id}/compute` | PayrollRunController@compute | payroll.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/payroll/{id}/compute-calculations` | PayrollRunController@computeCalculations | payroll.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/payroll/{id}/documents` | PayrollRunController@documents | payroll.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/payroll/{id}/export` | PayrollRunController@export | payroll.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/payroll/{id}/lines` | PayrollRunController@lines | tests | CONSOMMÉ (indirect, vérifié) |
| GET | `api/workforce/payroll/{id}/lines/{lineId}` | PayrollRunController@showLine | payroll.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/payroll/{id}/recompute` | PayrollRunController@recompute | payroll.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/payroll/{id}/validate` | PayrollRunController@validate | payroll.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/payroll/statistics` | PayrollRunController@statistics | payroll.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/activities` | ScheduledActivityController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/activities` | ScheduledActivityController@store | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/planning/activities/{id}` | ScheduledActivityController@update | planning.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/planning/activities/{id}` | ScheduledActivityController@destroy | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/activities/{id}/cancel` | ScheduledActivityController@cancel | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/activities/{id}/duplicate` | ScheduledActivityController@duplicate | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/activity-presets` | ActivityPresetController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/activity-presets` | ActivityPresetController@store | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/planning/activity-presets/{id}` | ActivityPresetController@update | planning.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/planning/activity-presets/{id}` | ActivityPresetController@destroy | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/coverage` | CoverageRequirementController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/coverage` | CoverageRequirementController@store | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/coverage-categories` | CoverageCategoryController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/coverage-categories` | CoverageCategoryController@store | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/planning/coverage-categories/{id}` | CoverageCategoryController@update | planning.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/planning/coverage-categories/{id}` | CoverageCategoryController@destroy | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/planning/coverage/{id}` | CoverageRequirementController@update | planning.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/planning/coverage/{id}` | CoverageRequirementController@destroy | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/coverage/replicate` | CoverageRequirementController@replicate | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/coverage/summary` | CoverageRequirementController@summary | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/favorites` | WorkforceFavoriteController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/favorites/toggle` | WorkforceFavoriteController@toggle | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/locations` | WorkLocationController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/locations` | WorkLocationController@store | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/planning/locations/{id}` | WorkLocationController@update | planning.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/planning/locations/{id}` | WorkLocationController@destroy | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/operations/{id}/redo` | ShiftController@redoOperation | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/operations/{id}/undo` | ShiftController@undoOperation | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/presets/import` | WorkforcePresetController@import | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/presets/import-preview` | WorkforcePresetController@preview | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/shifts` | ShiftController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts` | ShiftController@store | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/planning/shifts/{id}` | ShiftController@update | planning.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/planning/shifts/{id}` | ShiftController@destroy | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/{id}/cancel` | ShiftController@cancel | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/{id}/duplicate` | ShiftController@duplicate | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/{id}/replace` | ShiftController@replace | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/shifts/{id}/replacement-candidates` | ShiftController@replacementCandidates | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/shifts/availability` | ShiftController@availability | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/shifts/board` | ShiftController@board | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/bulk` | ShiftController@bulkCreate | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/bulk-template` | ShiftController@bulkApplyTemplate | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/bulk-template/preview` | ShiftController@bulkApplyTemplatePreview | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/shifts/calendar` | ShiftController@calendar | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/shifts/comparison` | ShiftController@comparison | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/copy` | ShiftController@copy | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/copy-week` | ShiftController@copyWeek | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/copy-week/apply` | ShiftController@copyWeekApply | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/copy-week/preview` | ShiftController@copyWeekPreview | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/copy/preview` | ShiftController@copyPreview | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/overstaffing-preview` | ShiftController@overstaffingPreview | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/shifts/publish` | ShiftController@publish | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/shifts/statistics` | ShiftController@statistics | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/shifts/week` | ShiftController@week | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/templates` | ScheduleTemplateController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/templates` | ScheduleTemplateController@store | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/planning/templates/{id}` | ScheduleTemplateController@update | planning.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/planning/templates/{id}` | ScheduleTemplateController@destroy | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/planning/week-templates` | WeekTemplateController@index | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/week-templates` | WeekTemplateController@store | planning.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/planning/week-templates/{id}` | WeekTemplateController@update | planning.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/planning/week-templates/{id}` | WeekTemplateController@destroy | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/week-templates/{id}/apply` | WeekTemplateController@apply | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/week-templates/{id}/preview` | WeekTemplateController@preview | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/week-templates/{id}/repeat-apply` | WeekTemplateController@repeatApply | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/week-templates/{id}/repeat-preview` | WeekTemplateController@repeatPreview | planning.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/planning/week-templates/capture` | WeekTemplateController@capture | planning.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/requirements` | RequirementConfigController@index | index.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/requirements` | RequirementConfigController@save | index.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/requirements/catalog` | RequirementConfigController@catalog | index.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/requirements/legacy` | RequirementConfigController@legacy | index.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/requirements/modules` | ModuleContributionController@index | _ModuleProposals.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/requirements/modules/{module}/decisions` | ModuleContributionController@decide | _ModuleProposals.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/requirements/modules/{module}/preview` | ModuleContributionController@preview | _ModuleProposals.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/requirements/preview` | RequirementConfigController@preview | index.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/settings` | WorkforceSettingsController@index | [tab].vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature` | SignatureController@index | signature.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/signature` | SignatureController@store | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/{id}` | SignatureController@show | signature.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/signature/{id}/cancel` | SignatureController@cancel | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/{id}/download` | SignatureController@downloadSigned | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/{id}/evidence` | SignatureController@evidence | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/{id}/evidence-package` | SignatureController@evidencePackage | signature.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/signature/{id}/remind` | SignatureController@remind | signature.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/signature/{id}/signers/{signerId}/reset-key` | SignatureController@resetSignerKey | tests | ORPHELIN UI : backend testé, aucune interface |
| GET | `api/workforce/signature/{id}/verify` | SignatureController@verify | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/by-employee/{employeeId}` | SignatureController@byEmployee | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/certificates` | SignatureController@certificates | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/eligible-documents` | SignatureController@eligibleDocuments | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/me` | MySignatureController@index | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/me/{id}/download` | MySignatureController@download | MySignatures.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/signature/me/{id}/open` | MySignatureController@open | MySignatures.vue | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/options` | SignatureController@options | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/reconciliation` | SignatureController@reconciliation | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/settings` | SignatureController@getSettings | signature.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/signature/settings` | SignatureController@updateSettings | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/signature/supervision` | SignatureController@supervision | signature.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/time-entries` | TimeEntryController@index | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/time-entries` | TimeEntryController@store | timesheets.store.js | CONSOMMÉ (frontend) |
| PUT | `api/workforce/time-entries/{id}` | TimeEntryController@update | timesheets.store.js | CONSOMMÉ (frontend) |
| DELETE | `api/workforce/time-entries/{id}` | TimeEntryController@destroy | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/time-entries/{id}/break/end` | TimeEntryController@endBreak | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/time-entries/{id}/break/start` | TimeEntryController@startBreak | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/time-entries/{id}/clock-out` | TimeEntryController@clockOut | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/time-entries/active` | TimeEntryController@active | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/time-entries/anomalies` | TimeEntryController@anomalies | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/time-entries/clock-in` | TimeEntryController@clockIn | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/time-entries/cockpit/board` | TimeEntryController@cockpitBoard | tests | CONSOMMÉ (indirect, vérifié) — timesheets.store |
| GET | `api/workforce/time-entries/cockpit/summary` | TimeEntryController@cockpitSummary | tests | CONSOMMÉ (indirect, vérifié) — timesheets.store |
| GET | `api/workforce/time-entries/company` | TimeEntryController@companyIndex | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/timesheets` | TimesheetController@index | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/timesheets` | TimesheetController@generate | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/timesheets/{id}` | TimesheetController@show | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/timesheets/{id}/approve` | TimesheetController@approve | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/timesheets/{id}/compliance` | TimesheetController@compliance | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/timesheets/{id}/history` | TimesheetController@history | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/timesheets/{id}/lock` | TimesheetController@lock | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/timesheets/{id}/reject` | TimesheetController@reject | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/timesheets/{id}/reopen` | TimesheetController@reopen | timesheets.store.js | CONSOMMÉ (frontend) |
| POST | `api/workforce/timesheets/{id}/submit` | TimesheetController@submit | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/timesheets/statistics` | TimesheetController@statistics | timesheets.store.js | CONSOMMÉ (frontend) |
| GET | `api/workforce/working-time-setup` | WorkingTimeSetupController@show | _WorkingTimeSetup.vue | CONSOMMÉ (frontend) |
| PUT | `api/workforce/working-time-setup` | WorkingTimeSetupController@save | _WorkingTimeSetup.vue | CONSOMMÉ (frontend) |
| POST | `api/workforce/working-time-setup/convention` | WorkingTimeSetupController@confirmConvention | _WorkingTimeSetup.vue | CONSOMMÉ (frontend) |
