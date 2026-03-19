# AUDIT SYSTÉMIQUE GLOBAL — Leezr SaaS Platform
**Date** : 2026-03-18
**Scope** : Navigation, Auth, RBAC, Surfaces, Modules, Billing, Notifications, Support, Documentation, Settings, Dashboard, Jobdomains — Backend + Frontend + Données + Tests

---

## SOMMAIRE

1. [Métriques clés](#1-métriques-clés)
2. [Causes racines systémiques (CR)](#2-causes-racines-systémiques)
3. [Incohérences UX/UI par surface](#3-incohérences-uxui)
4. [Simplifications structurelles](#4-simplifications-structurelles)
5. [Sources de vérité dupliquées](#5-sources-de-vérité-dupliquées)
6. [Écarts Backend ↔ Frontend ↔ RBAC](#6-écarts-backend--frontend--rbac)
7. [Invariants à garantir](#7-invariants-à-garantir)
8. [Architecture cible](#8-architecture-cible)
9. [Roadmap de migration](#9-roadmap-de-migration)
10. [Tests à ajouter](#10-tests-à-ajouter)
11. [ADR à créer/mettre à jour](#11-adr-à-créermettre-à-jour)

---

## 1. Métriques clés

| Axe | Nombre |
|-----|--------|
| Modules totaux | 37 (12 core + 20 platform + 4 logistics + 1 payment) |
| Controllers backend | ~80 |
| Endpoints API | ~350 (dont 109 billing) |
| Pages Vue routables (company) | ~36 |
| Pages Vue routables (platform) | ~33 |
| Sous-composants `_*.vue` | ~82 |
| Stores Pinia | 34 (7 core + 11 company + 16 platform) |
| Composables | 25+ |
| Dashboard widgets | 17 (12 billing + 5 compliance) |
| Tests existants navigation/layout | ~20 (4 suites) |
| ADR documentées | 358+ |
| Scheduled commands billing | 11 |

---

## 2. Causes racines systémiques

### CR-1 · Duplication massive entre surfaces Company/Platform (HAUTE)

**Symptôme** : Code quasi-identique répété pour les deux surfaces au lieu d'être partagé.

**Manifestations** :
- Pages notifications : `company/notifications/index.vue` (353L) ≈ `platform/notifications/index.vue` (323L) — ~250 lignes identiques
- `useCompanyNav.js` ≈ `usePlatformNav.js` — ~90% identique
- `TwoFactorController` (company) ≈ `PlatformTwoFactorController` (platform) — mêmes 5 méthodes
- `PasswordResetController` ≈ `PlatformPasswordResetController` — mêmes 2 méthodes
- `NotificationController` ≈ `PlatformNotificationController` — mêmes 5 méthodes
- `timeAgo()` dupliquée 3 fois (NavBarNotifications, 2 pages notifications)
- Stores `shipment.store.js` ≈ `delivery.store.js` — ~90% structure identique
- `dashboard.vue` ≈ `home.vue` — ~95% template identique

**Cause racine** : Pas de pattern d'extraction systématique (traits backend, composants/composables partagés frontend) pour le code multi-surface.

### CR-2 · Billing éparpillé et surdimensionné (HAUTE)

**Symptôme** : Le billing est le sous-système le plus critique et le plus complexe, mais aussi le plus éclaté.

**Manifestations** :
- 5 locations backend : `Core/Billing/` (90+), `Modules/Core/Billing/` (15), `Modules/Platform/Billing/` (26), `Modules/Platform/Companies/` (12 endpoints billing), `Modules/Billing/Dashboard/` (legacy)
- 3 interfaces contracts empilées : `BillingProvider` (legacy) → `PaymentGatewayProvider` → `PaymentProviderAdapter`
- 2 managers : `BillingManager` (legacy, config-driven) + `PaymentGatewayManager` (moderne, DB-driven)
- 109 endpoints billing total — le plus gros module n'a que 1 UseCase (`DeletePaymentMethodUseCase`) vs 13 pour core.members
- Legacy `BillingWidgetRegistry` doublon du système moderne `DashboardWidgetRegistry`
- Frontend : 13 onglets billing répartis sur 2 routes (8 standard + 5 advanced) — navigation UX lourde
- `CompanySubscriptionAdminController` et `CompanyBillingAdminController` sont dans `Platform/Companies/` au lieu de `Platform/Billing/`

**Cause racine** : Le billing a évolué par couches successives (BillingProvider → PaymentGateway → PaymentProvider) sans supprimer les couches obsolètes.

### CR-3 · Pages surdimensionnées sans extraction de sous-composants (MOYENNE)

**Symptôme** : Plusieurs pages dépassent 1000 lignes avec toute la logique inline.

**Manifestations** :
- `company/modules/index.vue` — 1530 lignes
- `company/plan.vue` — 1230 lignes
- `company/members/index.vue` — 1200 lignes
- `platform/modules/[key].vue` — 1501 lignes
- `platform/companies/[id].vue` — 1241 lignes
- `company/billing/pay.vue` — 960 lignes

**Cause racine** : Pas de règle de découpage systématique. Certaines pages ont été bien découpées (billing/[tab].vue avec ses _*.vue), d'autres non.

### CR-4 · `definePage()` meta incohérent (HAUTE)

**Symptôme** : Certaines pages manquent des meta critiques, d'autres utilisent des conventions différentes.

**Pages sans `surface` (company)** :
- `account-settings/[tab].vue` — pas de surface, pas de module
- `company/audit/index.vue` — pas de surface
- `company/support/index.vue` + `[id].vue` — pas de surface
- `dashboard.vue`, `home.vue` — pas de definePage du tout
- `company/billing/pay.vue`, `company/billing/invoices/[id].vue` — pas de definePage

**Pages sans `permission` (platform)** :
- `platform/settings/[tab].vue` — pas de permission
- `platform/international/[tab].vue` — pas de permission
- `platform/audit/index.vue` — pas de permission
- `platform/realtime/index.vue` — pas de permission
- `platform/security/index.vue` — pas de permission
- `platform/notifications/index.vue` — pas de permission
- `platform/support/index.vue` + `[id].vue` — pas de permission
- `platform/documents/index.vue` + `[id].vue` — pas de permission

**Conventions divergentes** :
- Support utilise `navActiveKey` au lieu de `navActiveLink`
- Pas de convention unifiée pour les pages qui n'ont pas de definePage

**Cause racine** : Pas de test CI vérifiant que toute page company a un `surface` et un `module`, et toute page platform un `permission`.

### CR-5 · Patterns CRUD incohérents (MOYENNE)

**Symptôme** : Les patterns de création/édition varient entre les pages sans raison fonctionnelle.

| Pattern | Pages utilisant |
|---------|----------------|
| VNavigationDrawer (Teleport) | Users, Roles, Fields, Documentation |
| VNavigationDrawer (sans Teleport) | Documentation articles |
| VDialog | Plans, Jobdomains, Documents, Markets legal |
| Page séparée | Plan detail, Jobdomain detail, Module detail |

**Backend :**
| Pattern | Modules utilisant |
|---------|-------------------|
| UseCases + DTOs | core.members (13), core.settings (9), platform.documents (8), platform.jobdomains (7) |
| Logique dans controller | core.billing (1 UseCase pour 109 endpoints), platform.billing, la plupart des CRUD simples |
| ReadModels | Billing (7 dans Core), Shipments (2), Fields/Jobdomains/Markets/etc. (1 chacun dans module) |
| Queries directes | Support, Notifications, Theme, Audit |

**Cause racine** : Pas de convention BMAD explicite sur quand utiliser un drawer vs dialog vs page, ni quand extraire un UseCase.

### CR-6 · Store Pinia : 3 patterns coexistent (BASSE)

**Symptôme** : Les stores utilisent Composition API, Options API, ou des appels API directs sans store.

| Pattern | Stores |
|---------|--------|
| Composition API (setup) | dashboard, home, compliance, platformBilling (facade) |
| Options API | members, settings, jobdomain, audit, shipment, delivery, notification |
| Pas de store (appels directs) | platform/documents, platform/company-users |

**Cause racine** : Pas de convention imposée. Les stores plus récents utilisent Composition API, les plus anciens Options API.

### CR-7 · API clients : 3 implémentations (MOYENNE)

**Symptôme** : Le frontend utilise 3 moyens différents pour appeler l'API.

| Client | Fichiers utilisant |
|--------|-------------------|
| `$api` (ofetch, company) | Majorité des pages company |
| `$platformApi` (ofetch, platform) | Majorité des pages platform |
| `fetch()` natif | `audience/confirm.vue`, `audience/unsubscribe.vue` |
| axios (via useHelpCenter) | Help center pages |

**Anomalie** : `platformDocumentation` store utilise `$api` au lieu de `$platformApi`.

**Cause racine** : Pages publiques (audience, help center) ont été implémentées avant la standardisation sur ofetch.

### CR-8 · Documentation company-side = trou fonctionnel (MOYENNE)

**Symptôme** : Le module `core.documentation` est déclaré mais n'a aucun controller ni page company-side.

**Manifestations** :
- Aucune page dans `pages/company/documentation/`
- Aucun controller dans `Modules/Core/Documentation/Http/`
- Le Help Center est public (layout: blank) — un user authentifié ne voit pas de contenu contextuel
- Les articles `audience: company` ne sont pas filtrés pour la company

**Cause racine** : Le Help Center a été implémenté comme public-only. La documentation contextuelle (par plan, par module actif) n'a jamais été spécifiée.

### CR-9 · Permissions frontend non vérifiées par CI (MOYENNE)

**Symptôme** : 8 pages platform n'ont pas de `permission` dans leur meta. Le router guard ne bloque pas l'accès.

**Cause racine** : `PageLayoutMetaTest` vérifie le layout mais pas les permissions. `PlatformModuleNavContractTest` vérifie les navItems mais pas les pages.

### CR-10 · Absence de `CompanyModuleNavContractTest` (HAUTE)

**Symptôme** : 5 tests d'invariants pour platform, 0 pour company. Un module company peut oublier ses navItems.

**Cause racine** : Le test platform a été écrit (ADR-110) mais le miroir company jamais créé.

---

## 3. Incohérences UX/UI

### 3.1 Pages à fusionner en tabs

| Pages actuelles | Proposition | Justification |
|-----------------|-------------|---------------|
| `platform/security/index.vue` + `platform/audit/index.vue` | **1 page "Security & Audit"** avec 3 tabs : Platform Logs, Company Logs, Security Alerts | Sujets liés, même persona admin, réduit les items nav |
| `platform/realtime/index.vue` | **Tab dans Settings** : "Realtime & DevOps" | Outil d'ops, pas un domaine métier. 1 page avec status/kill switch |
| `company/plan.vue` | **Tab de `company/billing/[tab].vue`** : tab "plan" | Plan et Billing sont le même domaine. L'utilisateur navigue déjà entre les deux |
| `platform/billing/advanced/[tab].vue` (5 tabs) | **Intégrer dans `platform/billing/index.vue`** avec section pliable "Advanced" ou tabs de 2ème niveau | Brise la navigation par onglets. Un lien "Advanced" → nouvelle page est déroutant |
| `platform/company/users.vue` | **Tab dans `platform/companies/[id].vue`** (déjà un tab Members) ou **supprimer** car redondant avec le tab Members de la vue 360 | Page read-only qui duplique l'info du tab Members |

### 3.2 Items de navigation à regrouper

| Nav actuelle | Proposition | Impact |
|-------------|-------------|--------|
| Company: "Plan" + "Billing" (2 items séparés) | **1 item "Billing"** avec plan comme tab | Réduit 1 item nav |
| Platform: "Security" + "Audit" (2 items) | **1 item "Security & Audit"** | Réduit 1 item nav |
| Platform: "Realtime" (item séparé) | **Sous Settings** ou supprimer de la nav principale | Outil ops rare, pas un domaine quotidien |
| Platform: "Document Types" (item séparé) | Évaluer s'il doit être sous "Modules" ou rester seul | Petit module, 2 pages CRUD |

### 3.3 Patterns UX à normaliser

| Pattern | Convention cible | Pages à corriger |
|---------|-----------------|------------------|
| Création d'entité simple | VNavigationDrawer avec Teleport | Plans (actuellement VDialog), Jobdomains (VDialog), Documents (VDialog) |
| `navActiveLink` | Utiliser `navActiveLink` partout | Support: `navActiveKey` → `navActiveLink` |
| `definePage()` meta | Toujours présent avec surface (company) ou permission (platform) | 15+ pages à corriger (voir CR-4) |
| Confirmation destructive | `useConfirm()` composable | Jobdomains, Plans (actuellement VDialog ad-hoc) |

### 3.4 Pages quasi-vides / stubs

| Page | Constat | Action |
|------|---------|--------|
| `company/settings.vue` | 14 lignes, redirect vers `/company/profile/overview` | Supprimer, remplacer par alias de route |
| `audience/confirm.vue` + `unsubscribe.vue` | Pattern identique (token + POST + success/error) | Fusionner en 1 page avec param `action` |

---

## 4. Simplifications structurelles

### 4.1 Backend — Supprimer les couches legacy

| Cible | Action | Fichiers | Risque |
|-------|--------|----------|--------|
| `BillingManager` | Supprimer, migrer vers `PaymentGatewayManager` | `app/Core/Billing/BillingManager.php`, config/billing.php | Moyen — vérifier tous les usages |
| `BillingProvider` interface | Supprimer, unifier sur `PaymentProviderAdapter` | `app/Core/Billing/Contracts/BillingProvider.php` | Moyen — migration progressive |
| `WebhookController` legacy | Supprimer, garder `PaymentWebhookController` | `app/Modules/Infrastructure/Webhooks/` | Faible — vérifier route active |
| `BillingWidgetRegistry` legacy | Supprimer | `app/Modules/Billing/Dashboard/` (tout le dossier) | Nul — non utilisé |
| `resources/ui/presets/navigation/` | Supprimer | 16 fichiers morts | Nul — non importés |

### 4.2 Backend — Déplacer les fichiers mal placés

| Fichier | De → Vers | Justification |
|---------|-----------|---------------|
| `Core/Billing/ReadModels/PlatformBilling*` (5 fichiers) | `Modules/Platform/Billing/ReadModels/` | ReadModels platform-only dans Core |
| `CompanySubscriptionAdminController` | `Platform/Companies/` → `Platform/Billing/Http/Admin/` | Controller billing dans companies |
| `CompanyBillingAdminController` | `Platform/Companies/` → `Platform/Billing/Http/Admin/` | Idem |

### 4.3 Backend — Extraire des traits pour code dupliqué

| Trait | Méthodes | Consommateurs |
|-------|----------|---------------|
| `HandlesTwoFactor` | enable, confirm, disable, regenerateBackupCodes, status | `TwoFactorController`, `PlatformTwoFactorController` |
| `HandlesNotifications` | index, unreadCount, markRead, markAllRead, destroy | `NotificationController`, `PlatformNotificationController` |
| `HandlesPasswordReset` | forgotPassword, resetPassword | `PasswordResetController`, `PlatformPasswordResetController` |

### 4.4 Frontend — Composants partagés à extraire

| Composant/Composable | Remplace | Fichiers impactés |
|----------------------|----------|-------------------|
| `DashboardWorkspace.vue` | Duplication dashboard.vue / home.vue | 2 pages → 1 composant + 2 pages légères |
| `NotificationInbox.vue` | Duplication notifications company/platform | 2 pages → 1 composant partagé |
| `useTimeAgo()` composable | 3 duplications de `timeAgo()` | NavBarNotifications + 2 pages notifications |
| `_ShipmentDetailView.vue` | Duplication shipments/[id] / my-deliveries/[id] | 2 pages → 1 composant partagé |
| `useNavTransformer()` composable | Duplication useCompanyNav / usePlatformNav (~90%) | 2 composables → 1 factory |
| `useCrudResourceStore()` factory | Stores quasi-identiques (shipment/delivery) | Réutilisable pour tout CRUD simple |

### 4.5 Frontend — Pages à découper en sous-composants

| Page | Lignes | Sous-composants à extraire |
|------|--------|---------------------------|
| `company/modules/index.vue` | 1530 | `_ModuleCard`, `_ModuleQuoteDialog`, `_ModuleDeactivatePreview`, `_ModuleSearchBar` |
| `company/plan.vue` | 1230 | `_PlanCards`, `_PlanChangePreview`, `_PlanPendingAlerts` |
| `company/members/index.vue` | 1200 | `_MemberFieldSettings`, `_MemberQuickView` |
| `company/billing/pay.vue` | 960 | `_PaymentForm`, composable `useStripePayment` |
| `platform/modules/[key].vue` | 1501 | `_PricingEditor`, `_ModuleIdentity`, `_ModuleJobdomains` |
| `platform/jobdomains/[id].vue` | 1000+ | `_JobdomainModules`, `_JobdomainRoles`, `_JobdomainDocuments`, `_JobdomainOverlays` |

---

## 5. Sources de vérité dupliquées

| Donnée | Source 1 | Source 2 | Résolution |
|--------|----------|----------|------------|
| Navigation platform au login | Cookie `platform_modules` (PlatformAuthController) | API `GET /api/platform/nav` | Supprimer le cookie, utiliser uniquement l'API |
| Permissions frontend | Cache en mémoire (store auth) | DB (CompanyRole.permissions) | Acceptable (defense-in-depth), mais refresh SSE/polling nécessaire |
| Billing interfaces | `BillingProvider` | `PaymentProviderAdapter` | Supprimer BillingProvider |
| Billing managers | `BillingManager` | `PaymentGatewayManager` | Supprimer BillingManager |
| Widget registry | `BillingWidgetRegistry` (legacy) | `DashboardWidgetRegistry` (moderne) | Supprimer le legacy |
| `timeAgo()` | 3 copies | — | Extraire composable |
| Invoice mutations (frontend) | `platformBilling.store` | `platformPayments.store` | Consolider dans un seul store |

---

## 6. Écarts Backend ↔ Frontend ↔ RBAC

### 6.1 Surface guard asymétrique (intentionnel mais non documenté)

- **Frontend** : router guard filtre par `to.meta.surface === 'structure'` → bloque les rôles opérationnels
- **Backend** : middleware `company.access:manage-structure` → vérifie `is_administrative`
- **Écart** : Un rôle opérationnel avec `settings.view` peut accéder à l'API settings mais pas à la page

**Verdict** : Asymétrie par design (backend plus permissif). À documenter dans un ADR.

### 6.2 Permissions platform : 8 pages sans guard frontend

Les pages platform sans `permission` dans le meta sont accessibles à tout admin authentifié. Le backend peut avoir ses propres checks, mais le frontend ne filtre pas.

**Pages concernées** : settings, international, audit, realtime, security, notifications, support, documents

**Verdict** : Ajouter `permission` dans le meta de chaque page, aligné avec le middleware backend.

### 6.3 Account Settings hors du système manifest

L'item "Account Settings" dans la navigation company est hardcodé dans `useCompanyNav.js:70-75`, hors du pipeline manifest-driven.

**Verdict** : Migrer dans le manifest du module `core.settings`.

### 6.4 i18n des navItems non vérifié

Les clés i18n sont construites par convention (`nav.{scope}.{key}`) mais aucun test CI ne vérifie que chaque navItem a ses traductions.

**Verdict** : Ajouter `NavI18nContractTest`.

### 6.5 platformDocumentation store utilise le mauvais API client

`platformDocumentation.store.js` utilise `$api` (company) au lieu de `$platformApi` (platform).

**Verdict** : Bug à corriger.

### 6.6 Help Center utilise axios au lieu d'ofetch

Le composable `useHelpCenter` utilise axios alors que 100% du reste utilise ofetch.

**Verdict** : Migrer vers `$api` ou un client public ofetch.

---

## 7. Invariants à garantir

### Navigation

| ID | Invariant | Vérifié CI | Action |
|----|-----------|-----------|--------|
| NAV-1 | Tout navItem a une clé i18n valide dans toutes les locales | ❌ | Créer NavI18nContractTest |
| NAV-2 | Tout module visible avec routes déclare des navItems | Platform ✅ / Company ❌ | Créer CompanyModuleNavContractTest |
| NAV-3 | Tout navItem déclare une route existante | Platform ✅ / Company ❌ | Idem |
| NAV-4 | Zéro duplication de navItem keys dans un scope | Platform ✅ / Company ❌ | Idem |
| NAV-5 | Tout navItem structure a une permission non-null | ✅ | Maintenir |
| NAV-6 | Zéro item de navigation hardcodé hors manifest | ❌ (Account Settings) | Migrer dans manifest |

### Pages & Layout

| ID | Invariant | Vérifié CI | Action |
|----|-----------|-----------|--------|
| PAGE-1 | Toute page platform déclare `layout: 'platform', platform: true` | ✅ | Maintenir |
| PAGE-2 | Aucune page company ne déclare `layout: 'platform'` | ✅ | Maintenir |
| PAGE-3 | Toute page company déclare `surface` et `module` | ❌ | Ajouter test |
| PAGE-4 | Toute page platform avec module gate déclare `permission` | ❌ | Ajouter test |
| PAGE-5 | Les sous-composants `_*.vue` ne déclarent pas `definePage()` | ✅ | Maintenir |
| PAGE-6 | Pas de coexistence `foo.vue` + `foo/` | ✅ | Maintenir |

### RBAC

| ID | Invariant | Vérifié CI | Action |
|----|-----------|-----------|--------|
| RBAC-1 | Un rôle non-administratif ne peut pas recevoir permissions `is_admin` | ✅ | Maintenir |
| RBAC-2 | Owner bypass s'applique à tout SAUF `use-module` | ✅ | Maintenir |
| RBAC-3 | Non-owner membership DOIT avoir `company_role_id` | ✅ | Maintenir |
| RBAC-4 | Chaque module avec permissions déclare des bundles | ✅ | Maintenir |

### Billing

| ID | Invariant | Vérifié CI | Action |
|----|-----------|-----------|--------|
| BILL-1 | LedgerEntry est immutable (no update/delete) | ✅ (model boot) | Ajouter test |
| BILL-2 | Subscription transitions sont gardées | ✅ (model boot) | Ajouter test |
| BILL-3 | Une seule interface payment provider active | ❌ (3 coexistent) | Supprimer legacy |

---

## 8. Architecture cible

### 8.1 Convention CRUD normalisée

```
Entité simple (< 5 champs) :
  Liste : VDataTableServer + VNavigationDrawer (Teleport) pour create/edit
  Pas de page détail

Entité complexe (>= 5 champs ou tabs) :
  Liste : VDataTableServer + VNavigationDrawer pour create
  Détail : Page séparée avec tabs

Confirmation destructive : useConfirm() composable (jamais VDialog ad-hoc)
```

### 8.2 Convention definePage meta

```
Company pages :
  definePage({ meta: { module: '{module_key}', surface: '{structure|operations}' } })

Platform pages :
  definePage({ meta: { layout: 'platform', platform: true, module: '{module_key}', permission: '{perm_key}' } })

Auth pages (toute surface) :
  definePage({ meta: { layout: 'blank', platform: true/false, public: true } })

Sous-composants _*.vue :
  Pas de definePage()
```

### 8.3 Convention Store Pinia

```
Nouveau store = Composition API (defineStore('id', () => {}))
Pas de refactoring des stores existants Options API (sauf si modifié pour autre raison)
Pas d'appels API directs dans les composants — toujours via store
```

### 8.4 Convention extraction sous-composants

```
Page > 500 lignes → obligatoire d'extraire en _*.vue
Page avec tabs → chaque tab = un _Tab*.vue
Drawer complexe (> 200 lignes) → _*Drawer.vue
Dialog complexe → _*Dialog.vue
Logique réutilisable → composable use*.js
```

### 8.5 Convention billing

```
Interface unique : PaymentProviderAdapter (supprimer BillingProvider + PaymentGatewayProvider)
Manager unique : PaymentGatewayManager (supprimer BillingManager)
ReadModels platform-only → dans Modules/Platform/Billing/ReadModels/
Billing admin controllers → dans Modules/Platform/Billing/Http/Admin/
```

### 8.6 Navigation company cible (après simplifications)

```
Company Sidebar
├── Dashboard .............. management workspace
├── Home ................... operational workspace
├── [Operations]
│   ├── Shipments
│   └── My Deliveries
├── [Structure]
│   ├── Company ........... profile + documents tabs
│   ├── Members ........... list + detail
│   ├── Roles ............. CRUD + permissions
│   ├── Billing ........... overview + invoices + payment + plan + activity (5 tabs)
│   ├── Modules ........... marketplace + settings
│   ├── Notifications ..... inbox + preferences
│   └── Audit ............. logs
├── Support ............... tickets
└── [Avatar Menu]
    └── Account Settings .. account + security + preferences + documents
```

### 8.7 Navigation platform cible (après simplifications)

```
Platform Sidebar
├── Dashboard .............. widgets engine
├── [Management]
│   ├── Companies .......... list + 360 detail
│   ├── Users .............. platform users CRUD
│   ├── Roles .............. platform roles CRUD
├── [Configuration]
│   ├── Plans .............. pricing CRUD
│   ├── Modules ............ toggle + config
│   ├── Fields ............. definitions + activations
│   ├── Jobdomains ......... config 5 tabs
│   ├── International ...... markets + languages + translations + fx
│   ├── Document Types ..... catalog CRUD
├── [Billing & Finance]
│   ├── Billing ............ 8 tabs standard + 5 tabs advanced (intégrés)
├── [Operations]
│   ├── Security & Audit ... security alerts + platform/company logs (fusionnés)
│   ├── Notifications ...... topic governance
│   ├── Support ............ ticket triage
│   ├── Documentation ...... topics + articles + feedback + search misses
├── [System]
│   └── Settings ........... general + theme + sessions + maintenance + billing + notifications + realtime (intégré)
└── [Avatar Menu]
    └── My Account ......... account + security + notifications
```

---

## 9. Roadmap de migration

### Phase 0 — Tests d'invariants (ZÉRO changement de comportement)

| # | Action | Fichiers | Risque |
|---|--------|----------|--------|
| 0.1 | Créer `CompanyModuleNavContractTest` (5 tests) | Nouveau test | Nul |
| 0.2 | Créer `NavI18nContractTest` (4 tests) | Nouveau test | Nul |
| 0.3 | Étendre `PageLayoutMetaTest` : vérifier surface+module (company), permission (platform) | Test existant | Nul |
| 0.4 | Exécuter et corriger les meta manquants révélés | ~15 pages | Faible |

### Phase 1 — Nettoyage legacy (suppressions pures, zéro impact fonctionnel)

| # | Action | Fichiers | Risque |
|---|--------|----------|--------|
| 1.1 | Supprimer `app/Modules/Billing/Dashboard/` (legacy widget registry) | 4 fichiers | Nul |
| 1.2 | Supprimer `resources/ui/presets/navigation/` (16 fichiers morts) | 16 fichiers | Nul |
| 1.3 | Supprimer `company/settings.vue` stub (14 lignes) + ajouter redirect dans router | 1 fichier | Faible |
| 1.4 | Corriger `platformDocumentation.store.js` : `$api` → `$platformApi` | 1 fichier | Faible |
| 1.5 | Corriger `navActiveKey` → `navActiveLink` dans support pages | 2 fichiers | Faible |

### Phase 2 — Extractions partagées (factorisation, zéro changement fonctionnel)

| # | Action | Fichiers | Risque |
|---|--------|----------|--------|
| 2.1 | Extraire composable `useTimeAgo()` | 1 nouveau + 3 modifiés | Faible |
| 2.2 | Extraire `NotificationInbox.vue` partagé | 1 nouveau + 2 modifiés | Faible |
| 2.3 | Extraire `DashboardWorkspace.vue` partagé | 1 nouveau + 2 modifiés | Faible |
| 2.4 | Extraire `_ShipmentDetailView.vue` partagé | 1 nouveau + 2 modifiés | Faible |
| 2.5 | Extraire `useNavTransformer()` factory | 1 nouveau + 2 modifiés | Faible |
| 2.6 | Extraire trait `HandlesTwoFactor` (backend) | 1 trait + 2 controllers | Faible |
| 2.7 | Extraire trait `HandlesNotifications` (backend) | 1 trait + 2 controllers | Faible |

### Phase 3 — Simplifications structurelles (changements UX)

| # | Action | Fichiers | Risque |
|---|--------|----------|--------|
| 3.1 | Fusionner `company/plan.vue` comme tab de `company/billing/[tab].vue` | 2 fichiers + nav | Moyen |
| 3.2 | Fusionner `platform/security` + `platform/audit` en 1 page | 3 fichiers + nav | Moyen |
| 3.3 | Intégrer `platform/realtime` dans `platform/settings` | 2 fichiers + nav | Moyen |
| 3.4 | Intégrer billing advanced comme section de billing standard | 2 routes → 1 | Moyen |
| 3.5 | Fusionner `audience/confirm` + `audience/unsubscribe` | 2 fichiers → 1 | Faible |
| 3.6 | Migrer Account Settings navItem dans manifest `core.settings` | 3 fichiers | Faible |

### Phase 4 — Découpage des pages volumineuses

| # | Action | Fichiers |
|---|--------|----------|
| 4.1 | `company/modules/index.vue` → 4 sous-composants | 1 → 5 |
| 4.2 | `company/plan.vue` → 3 sous-composants (si pas fusionné en Phase 3) | 1 → 4 |
| 4.3 | `company/members/index.vue` → 2 sous-composants | 1 → 3 |
| 4.4 | `company/billing/pay.vue` → composable + sous-composant | 1 → 3 |
| 4.5 | `platform/modules/[key].vue` → 3 sous-composants | 1 → 4 |
| 4.6 | `platform/jobdomains/[id].vue` → 5 sous-composants (1 par tab) | 1 → 6 |

### Phase 5 — Nettoyage billing (migration progressive)

| # | Action | Risque |
|---|--------|--------|
| 5.1 | Supprimer `BillingProvider` interface + `BillingManager` | Moyen |
| 5.2 | Déplacer 5 ReadModels platform de `Core/Billing/` vers `Platform/Billing/` | Faible |
| 5.3 | Déplacer billing admin controllers de `Platform/Companies/` vers `Platform/Billing/` | Faible |
| 5.4 | Supprimer `WebhookController` legacy | Moyen |

### Phase 6 — ADR & Documentation

| # | Action |
|---|--------|
| 6.1 | Écrire les ADR listées en section 11 |
| 6.2 | Mettre à jour `docs/bmad/02-domain.md` (obsolète) |
| 6.3 | Marquer ADR-020, ADR-029, ADR-059 comme supersédées |

---

## 10. Tests à ajouter

### Nouveaux fichiers de tests

| Test | Type | Invariants couverts |
|------|------|---------------------|
| `CompanyModuleNavContractTest` | Feature | NAV-2, NAV-3, NAV-4 |
| `NavI18nContractTest` | Feature | NAV-1 |
| `PageMetaCompleteness` (extension de PageLayoutMetaTest) | Feature | PAGE-3, PAGE-4 |
| `BillingLedgerImmutabilityTest` | Unit | BILL-1 |
| `SubscriptionStateMachineTest` | Unit | BILL-2 |

### Tests à écrire après chaque phase

| Phase | Tests |
|-------|-------|
| Phase 0 | Les 3 nouveaux tests ci-dessus |
| Phase 2 | Tests unitaires pour `useTimeAgo`, `useNavTransformer` |
| Phase 3 | Tests E2E de navigation pour vérifier les fusions |
| Phase 5 | Vérifier que l'ancien `BillingProvider` n'est plus référencé |

---

## 11. ADR à créer/mettre à jour

### Nouvelles ADR

| ADR | Titre | Contenu |
|-----|-------|---------|
| ADR-3XX | Convention CRUD UI (Drawer vs Dialog vs Page) | Quand utiliser chaque pattern, normalisation |
| ADR-3XX | Convention definePage meta par surface | surface + module (company), permission (platform), enforcement CI |
| ADR-3XX | Surface guard asymétrie documentée | Frontend surface-based, backend permission-based, intentionnel |
| ADR-3XX | Suppression BillingProvider/BillingManager legacy | Migration vers PaymentGatewayManager unique |
| ADR-3XX | Fusion Plan + Billing company-side | Justification UX, impact nav |
| ADR-3XX | Fusion Security + Audit platform-side | Justification UX, impact nav |
| ADR-3XX | Convention extraction sous-composants (règle 500 lignes) | Quand et comment découper |
| ADR-3XX | Convention Store Pinia (Composition API pour nouveaux) | Standard technique |

### ADR à mettre à jour

| ADR | Mise à jour |
|-----|-------------|
| ADR-020 | Marquer comme supersédée par ADR-031/032 (guard platform, identity model) |
| ADR-029 | Marquer comme supersédée par ADR-035 (middleware platform.role → platform.permission) |
| ADR-049 | Ajouter note : `company.permission` deprecated par ADR-061 `company.access` |
| ADR-058 | Marquer comme supersédée par ADR-060 (redirect 404 → 403) |
| ADR-059 | Marquer comme supersédée par ADR-086 (STRUCTURE_ROUTES Set supprimé) |

---

## ADDENDUM : Auto-approve edits

Pour l'implémentation de ce plan, Claude avance de manière autonome sans redemander validation à chaque edit. Chaque phase est exécutée séquentiellement, et le résultat consolidé est présenté à la fin de chaque phase.
