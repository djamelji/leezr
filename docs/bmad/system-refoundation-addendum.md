# ADDENDUM D'ARCHITECTURE — Unification du pipeline d'accès

**Date** : 2026-03-18
**Statut** : DRAFT — en attente de validation avant implémentation
**Dépend de** : `system-audit-2026-03-18.md` (audit systémique)
**Scope** : Permissions, Navigation, Widgets, Routes, Meta, Presets, CI

---

## SOMMAIRE

1. [Matrice complète rôle → bundles → permissions → surfaces → pages → widgets → nav items](#1-matrice-complète)
2. [Stratégie de resync durable des presets sans dérive DB](#2-stratégie-de-resync)
3. [Contrat d'invariants CI pour company ET platform](#3-contrat-dinvariants-ci)
4. [Suppression de toute logique d'accès hors pipeline unique](#4-pipeline-unique)
5. [Plan de migration complet sans régression](#5-plan-de-migration)
6. [Validation de cohérence produit/UX/métier](#6-validation-produit)
7. [Invariants UX](#7-invariants-ux)
8. [Architecture informationnelle — pages, menus, tabs](#8-architecture-informationnelle)

---

## 1. Matrice complète

### 1.1 Jobdomain : Logistique (seul jobdomain actif)

#### Owner (hors matrice — bypass total)

| Dimension | Valeur |
|-----------|--------|
| **permissions** | `null` (bypass — voit tout) |
| **roleLevel** | `management` |
| **workspace** | `dashboard` |
| **surfaces** | structure + operations |
| **nav items** | TOUS (aucun filtrage) |
| **widgets** | TOUS (bypass permission dans DashboardCatalogService) |
| **pages** | TOUTES |

> L'owner n'a pas de `CompanyRole` — son bypass est hardcodé dans `CompanyAccess::can()`, `auth.js::hasPermission()` et `DashboardCatalogService::forArchetype()`.

---

#### MANAGER (Gérant / Directeur)

| Dimension | Valeur |
|-----------|--------|
| **archetype** | `management` |
| **is_administrative** | `true` |
| **roleLevel** | `management` |
| **workspace** | `dashboard` → landing `/dashboard` |

**Bundles (11)** :

| # | Bundle | Module |
|---|--------|--------|
| 1 | `theme.full` | core.theme |
| 2 | `members.team_access` | core.members |
| 3 | `members.team_management` | core.members |
| 4 | `members.sensitive_data` | core.members |
| 5 | `settings.company_info` | core.settings |
| 6 | `settings.company_management` | core.settings |
| 7 | `roles.governance` | core.roles |
| 8 | `jobdomain.info` | core.jobdomain |
| 9 | `jobdomain.management` | core.jobdomain |
| 10 | `shipments.operations` | logistics_shipments |
| 11 | `shipments.administration` | logistics_shipments |

**Permissions résolues (19)** :

| Permission | is_admin | Source bundle |
|------------|----------|---------------|
| `theme.view` | false | theme.full |
| `theme.manage` | false | theme.full |
| `members.view` | false | members.team_access |
| `members.invite` | false | members.team_access |
| `members.manage` | **true** | members.team_management |
| `members.credentials` | **true** | members.team_management |
| `members.sensitive_read` | **true** | members.sensitive_data |
| `settings.view` | false | settings.company_info |
| `settings.manage` | **true** | settings.company_management |
| `roles.view` | **true** | roles.governance |
| `roles.manage` | **true** | roles.governance |
| `jobdomain.view` | false | jobdomain.info |
| `jobdomain.manage` | **true** | jobdomain.management |
| `shipments.view` | false | shipments.operations |
| `shipments.create` | false | shipments.operations |
| `shipments.manage_status` | false | shipments.operations |
| `shipments.assign` | false | shipments.operations |
| `shipments.manage_fields` | **true** | shipments.administration |
| `shipments.delete` | **true** | shipments.administration |

**Nav items attendus** :

| Nav item | Key | Permission requise | Surface | Visible ? |
|----------|-----|--------------------|---------|-----------|
| Dashboard | `dashboard` | aucune | — | OUI |
| Members | `members` | `members.view` | `structure` | OUI |
| Roles | `company-roles` | `roles.view` | `structure` | OUI |
| Company Profile | `company-profile` | `settings.view` | `structure` | OUI |
| Shipments | `shipments` | `shipments.view` | `operations` | OUI |
| My Deliveries | `my-deliveries` | `shipments.view_own` | `operations` + `operationalOnly` | NON (management voit Shipments) |

**Widgets attendus** :

| Widget | Permission requise | Visible ? |
|--------|--------------------|-----------|
| `compliance.rate` | aucune | OUI |
| `compliance.pending` | `members.manage` | OUI |
| `compliance.roles` | `members.manage` | OUI |
| `compliance.types` | `members.manage` | OUI |
| `compliance.overdue` | `members.manage` | OUI |

**Pages accessibles** : `/dashboard`, `/company/members`, `/company/members/:id`, `/company/roles`, `/company/profile/:tab`, `/company/shipments`, `/company/shipments/create`, `/company/shipments/:id`

---

#### OPS_MANAGER (Responsable d'exploitation)

| Dimension | Valeur |
|-----------|--------|
| **archetype** | `management` |
| **is_administrative** | `true` |
| **roleLevel** | `management` |
| **workspace** | `dashboard` → landing `/dashboard` |

**Bundles (9)** — diff vs Manager : PAS de `settings.company_management`, `jobdomain.management`

| # | Bundle | Module |
|---|--------|--------|
| 1 | `theme.full` | core.theme |
| 2 | `members.team_access` | core.members |
| 3 | `members.team_management` | core.members |
| 4 | `members.sensitive_data` | core.members |
| 5 | `settings.company_info` | core.settings |
| 6 | `roles.governance` | core.roles |
| 7 | `jobdomain.info` | core.jobdomain |
| 8 | `shipments.operations` | logistics_shipments |
| 9 | `shipments.administration` | logistics_shipments |

**Permissions résolues (17)** — diff vs Manager : PAS de `settings.manage`, `jobdomain.manage`

**Nav items attendus** : identiques au Manager (5 items visibles)

**Widgets attendus** : identiques au Manager (5 widgets)

**Pages accessibles** : identiques au Manager, MAIS `/company/profile/settings` en lecture seule (pas de `settings.manage`)

---

#### DISPATCHER (Exploitant / Répartiteur)

| Dimension | Valeur |
|-----------|--------|
| **archetype** | `operations_center` |
| **is_administrative** | `false` |
| **roleLevel** | `operational` |
| **workspace** | `dashboard` → landing `/dashboard` |

**Bundles (5)** :

| # | Bundle | Module |
|---|--------|--------|
| 1 | `theme.full` | core.theme |
| 2 | `members.team_access` | core.members |
| 3 | `settings.company_info` | core.settings |
| 4 | `shipments.operations` | logistics_shipments |
| 5 | `shipments.delivery` | logistics_shipments |

**Permissions résolues (10)** :

| Permission | is_admin | Source bundle |
|------------|----------|---------------|
| `theme.view` | false | theme.full |
| `theme.manage` | false | theme.full |
| `members.view` | false | members.team_access |
| `members.invite` | false | members.team_access |
| `settings.view` | false | settings.company_info |
| `shipments.view` | false | shipments.operations |
| `shipments.create` | false | shipments.operations |
| `shipments.manage_status` | false | shipments.operations + shipments.delivery |
| `shipments.assign` | false | shipments.operations |
| `shipments.view_own` | false | shipments.delivery |

**Nav items attendus** :

| Nav item | Key | Permission requise | Surface | Visible ? |
|----------|-----|--------------------|---------|-----------|
| Dashboard | `dashboard` | aucune | — | OUI |
| Members | `members` | `members.view` | `structure` | **NON** (roleLevel=operational, surface=structure filtré) |
| Roles | `company-roles` | `roles.view` | `structure` | **NON** (pas de permission + surface filtrée) |
| Company Profile | `company-profile` | `settings.view` | `structure` | **NON** (surface filtrée) |
| Shipments | `shipments` | `shipments.view` | `operations` | OUI |
| My Deliveries | `my-deliveries` | `shipments.view_own` | `operations` + `operationalOnly` | **NON** (operationalOnly ET roleLevel!=management, MAIS dispatcher n'est PAS field_worker — operationalOnly est pour field workers qui n'ont PAS shipments.view) |

> **Attendu** : 2 nav items (Dashboard + Shipments)

**Widgets attendus** :

| Widget | Permission requise | Visible ? |
|--------|--------------------|-----------|
| `compliance.rate` | aucune | OUI |
| `compliance.pending` | `members.manage` | **NON** |
| `compliance.roles` | `members.manage` | **NON** |

> **Attendu** : 1 widget (compliance.rate)

**Pages accessibles** : `/dashboard`, `/company/shipments`, `/company/shipments/create`, `/company/shipments/:id`

**Pages INTERDITES** (double lock : surface backend + pas de permission) : `/company/members`, `/company/roles`, `/company/profile/*`

---

#### DRIVER (Conducteur / Chauffeur)

| Dimension | Valeur |
|-----------|--------|
| **archetype** | `field_worker` |
| **is_administrative** | `false` |
| **roleLevel** | `operational` |
| **workspace** | `home` → landing `/home` |

**Bundles (4)** :

| # | Bundle | Module |
|---|--------|--------|
| 1 | `theme.full` | core.theme |
| 2 | `members.team_access` | core.members |
| 3 | `settings.company_info` | core.settings |
| 4 | `shipments.delivery` | logistics_shipments |

**Permissions résolues (7)** :

| Permission | is_admin | Source bundle |
|------------|----------|---------------|
| `theme.view` | false | theme.full |
| `theme.manage` | false | theme.full |
| `members.view` | false | members.team_access |
| `members.invite` | false | members.team_access |
| `settings.view` | false | settings.company_info |
| `shipments.view_own` | false | shipments.delivery |
| `shipments.manage_status` | false | shipments.delivery |

**Nav items attendus** :

| Nav item | Key | Permission requise | Surface | Visible ? |
|----------|-----|--------------------|---------|-----------|
| Dashboard | `dashboard` | aucune | — | OUI (mais redirect → `/home`) |
| Members | `members` | `members.view` | `structure` | **NON** |
| Roles | `company-roles` | `roles.view` | `structure` | **NON** |
| Company Profile | `company-profile` | `settings.view` | `structure` | **NON** |
| Shipments | `shipments` | `shipments.view` | `operations` | **NON** (pas de `shipments.view`) |
| My Deliveries | `my-deliveries` | `shipments.view_own` | `operations` + `operationalOnly` | OUI |

> **Attendu** : 2 nav items (Dashboard/Home + My Deliveries)

**Widgets attendus** : 1 widget (`compliance.rate`)

**Pages accessibles** : `/home`, `/company/my-deliveries`, `/company/my-deliveries/:id`

**Pages INTERDITES** : `/dashboard` (redirect → `/home`), `/company/shipments` (pas de `shipments.view`), `/company/members`, `/company/roles`, `/company/profile/*`

---

### 1.2 Tableau comparatif synthétique

| | Manager | Ops Manager | Dispatcher | Driver |
|--|---------|-------------|------------|--------|
| **archetype** | management | management | operations_center | field_worker |
| **is_administrative** | true | true | false | false |
| **roleLevel** | management | management | operational | operational |
| **workspace** | dashboard | dashboard | dashboard | home |
| **bundles** | 11 | 9 | 5 | 4 |
| **permissions** | 19 | 17 | 10 | 7 |
| **surfaces** | structure+ops | structure+ops | ops only | ops only |
| **nav items** | 5 | 5 | 2 | 2 |
| **widgets** | 5 | 5 | 1 | 1 |
| **pages** | 8+ | 8+ | 4 | 3 |

### 1.3 Éléments hors pipeline (problèmes identifiés)

| Élément | Localisation | Problème |
|---------|-------------|----------|
| OnboardingWidget | `pages/dashboard.vue` L64 | Hardcodé sans `v-if`, hors DashboardCatalogService |
| PlanBadgeWidget | `pages/dashboard.vue` | Hardcodé sans `v-if`, hors DashboardCatalogService |
| Account Settings nav | `useCompanyNav.js` L70-76 | Hardcodé hors ModuleManifest |
| Permission guard company | `guards.js` | **MANQUANT** — existe pour platform, pas pour company |
| `members.view` sur GET `/company/members` | `routes/company.php` | Module gate sans permission check |

---

## 2. Stratégie de resync durable des presets sans dérive DB

### 2.1 Diagnostic : pourquoi la dérive existe

Le système actuel a un **trou structurel** entre l'étape 4 (SystemSeeder sync catalog) et l'étape 6 (JobdomainGate assign roles) du lifecycle :

```
SystemSeeder (deploy-time)    →  Sync company_permissions catalog ✅
                                  Cleanup stale permissions       ✅
                                  Resync role permissions         ❌ ABSENT

JobdomainGate (registration)  →  Assign roles + permissions      ✅ (one-shot)
                                  Re-apply on preset change       ❌ ABSENT
```

**Résultat** : les permissions d'un rôle sont un **snapshot immuable** de l'instant de registration. Toute évolution ultérieure crée un drift silencieux.

### 2.2 Points de dérive identifiés (8)

| # | Drift | Cause | Impact |
|---|-------|-------|--------|
| D1 | Bundle non déployé | `resolveBundles()` retourne `[]` silencieusement | Rôle créé sans permissions |
| D2 | Bundle étendu après registration | Pas de resync | Anciennes companies n'ont pas la nouvelle permission |
| D3 | Permission retirée d'un bundle | Pas de resync | Anciennes companies gardent la permission retirée |
| D4 | Permission ajoutée au catalog | SystemSeeder sync, mais pas aux rôles | Feature invisible pour anciennes companies |
| D5 | `is_admin` changé rétroactivement | `updateOrCreate` met à jour la permission, pas les pivots | Rôles opérationnels gardent une permission devenue admin |
| D6 | Preset modifié via platform UI | Seulement pour nouvelles registrations | Incohérence entre anciennes et nouvelles companies |
| D7 | Module désactivé | Permissions restent dans les rôles | Confusion UX (permissions visibles mais non fonctionnelles) |
| D8 | `cleanupStalePermissions()` cascade-delete | Supprime pivots sans audit trail | Permissions retirées sans trace |

### 2.3 Architecture cible : Preset Reconciliation Engine

#### Principe fondamental

> Les permissions d'un rôle company sont **dérivées** du preset jobdomain, pas stockées indépendamment. Le preset est la source de vérité ; la DB est un cache qui DOIT être resynchronisable.

#### Composants à créer

**A. `PresetReconciler` (service)** — `app/Core/Jobdomains/PresetReconciler.php`

```
PresetReconciler::reconcile(Company $company, ?string $dryRun = null): ReconciliationReport
```

Logique :
1. Charger le preset actuel du jobdomain de la company
2. Pour chaque rôle du preset :
   a. Résoudre les bundles → permissions attendues
   b. Charger les permissions actuelles du rôle en DB
   c. Calculer le diff (ajouts, suppressions)
   d. Si `$dryRun` : retourner le diff sans appliquer
   e. Sinon : appliquer via `syncPermissionsSafe()`
3. Logger chaque changement dans l'audit trail

**B. `PresetReconcileCommand` (artisan)** — `app/Console/Commands/PresetReconcileCommand.php`

```bash
# Dry-run pour toutes les companies
php artisan permissions:reconcile --dry-run

# Dry-run pour une company spécifique
php artisan permissions:reconcile --company=42 --dry-run

# Appliquer pour toutes les companies
php artisan permissions:reconcile

# Appliquer pour un jobdomain spécifique
php artisan permissions:reconcile --jobdomain=logistique
```

**C. Intégration dans le deploy pipeline** — `deploy/deploy_release.sh`

```bash
# [6/9] Run SystemSeeder (idempotent)
$PHP_BIN artisan db:seed --class=SystemSeeder --force

# [6.5/9] Reconcile role permissions (NEW)
$PHP_BIN artisan permissions:reconcile --log
```

**D. Logging strict dans `resolveBundles()`**

`ModuleRegistry::resolveBundles()` DOIT logger un warning si un bundle key n'est trouvé dans aucun manifest. Actuellement silencieux.

**E. Snapshot de preset à la registration**

Table `company_preset_snapshots` :
- `company_id` — FK
- `jobdomain_key` — string
- `preset_hash` — hash du preset JSON (pour diff rapide)
- `preset_data` — JSON du preset au moment de la registration
- `created_at`

Permet de tracer quelle version du preset était appliquée et de diff vs preset actuel.

### 2.4 Politique de resync

| Situation | Action | Automatique ? |
|-----------|--------|---------------|
| Deploy avec nouveau bundle | `permissions:reconcile` dans pipeline | OUI (deploy) |
| Permission ajoutée à un bundle | Reconciler ajoute aux rôles existants | OUI (deploy) |
| Permission retirée d'un bundle | Reconciler retire des rôles existants | OUI (deploy) |
| `is_admin` changé | Reconciler re-valide via `syncPermissionsSafe()` | OUI (deploy) |
| Preset modifié via platform UI | Reconciler appelé manuellement ou via hook | SEMI-AUTO |
| Module désactivé | Strip permissions du module des rôles | NOUVEAU (à implémenter) |

### 2.5 Garde-fous

1. **Dry-run obligatoire en staging** avant tout deploy qui modifie des presets
2. **Audit trail** : chaque changement de permission loggé avec `[RECONCILE]` prefix
3. **Rollback** : snapshot de preset permet de restaurer l'état précédent
4. **Non-destructif pour customisations** : si un admin a manuellement ajouté une permission hors preset, le reconciler la CONSERVE (union, pas remplacement)
5. **Exception : permissions retirées du catalog** — supprimées par `cleanupStalePermissions()` (cascade), impossible de les conserver

---

## 3. Contrat d'invariants CI pour company ET platform

### 3.1 Invariants existants (déjà testés)

| Test | Fichier | Invariant |
|------|---------|-----------|
| `PageLayoutMetaTest` | `tests/Feature/PageLayoutMetaTest.php` | Platform pages ont `layout: 'platform'` + `platform: true` |
| `PageLayoutMetaTest` | idem | Company pages n'ont PAS de `layout:` |
| `PageLayoutMetaTest` | idem | Sub-components `_*.vue` n'ont PAS de `definePage()` |

### 3.2 Invariants à ajouter — Pipeline d'accès

#### INV-PERM-001 : Cohérence bundle → permission

```
POUR chaque module manifest :
  POUR chaque bundle :
    POUR chaque permission key dans bundle.permissions :
      ASSERT permission key EXISTE dans manifest.permissions
```

> Garantit qu'un bundle ne référence pas une permission inexistante.

#### INV-PERM-002 : Cohérence preset → bundle

```
POUR chaque jobdomain dans JobdomainRegistry :
  POUR chaque rôle dans jobdomain.defaultRoles :
    POUR chaque bundle key dans role.bundles :
      ASSERT bundle key EXISTE dans au moins un module manifest
```

> Garantit que les presets ne référencent pas des bundles fantômes.

#### INV-PERM-003 : is_admin coherence

```
POUR chaque jobdomain dans JobdomainRegistry :
  POUR chaque rôle avec is_administrative = false :
    resolved_permissions = resolveBundles(role.bundles)
    POUR chaque permission dans resolved_permissions :
      ASSERT permission.is_admin = false
```

> Garantit que les presets ne donnent jamais de permissions admin à un rôle opérationnel.

#### INV-PERM-004 : Pas de permission orpheline dans nav items

```
POUR chaque module manifest :
  POUR chaque navItem dans manifest.navItems :
    SI navItem.permission != null :
      ASSERT navItem.permission EXISTE dans manifest.permissions OU dans un autre manifest
```

> Garantit que la nav ne référence pas une permission inexistante.

#### INV-PERM-005 : Route company permission → middleware

```
POUR chaque page Vue dans pages/company/ :
  SI page.definePage().meta.permission != null :
    ASSERT route backend correspondante a middleware 'company.access:use-permission,{key}'
```

> Garantit la cohérence frontend meta ↔ backend middleware.

### 3.3 Invariants à ajouter — Navigation

#### INV-NAV-001 : Pas de nav item hardcodé hors manifest

```
DANS useCompanyNav.js ET usePlatformNav.js :
  ASSERT aucun item statique ajouté en dehors du payload backend /nav
EXCEPTION AUTORISÉE : aucune (tout doit être manifest-driven)
```

#### INV-NAV-002 : Filtrage surface cohérent

```
POUR chaque nav item avec surface='structure' :
  roleLevel='operational' → item ABSENT du payload NavBuilder
POUR chaque nav item avec operationalOnly=true :
  roleLevel='management' → item ABSENT du payload NavBuilder
```

> Déjà implémenté dans NavBuilder, mais à tester en CI.

### 3.4 Invariants à ajouter — Widgets

#### INV-WIDGET-001 : Pas de widget hors DashboardCatalogService

```
DANS pages/dashboard.vue :
  ASSERT aucun composant widget importé et rendu statiquement
  TOUS les widgets viennent du DashboardCatalogService via API
```

#### INV-WIDGET-002 : Cohérence widget permission

```
POUR chaque widget dans DashboardWidgetRegistry :
  POUR chaque permission dans widget.permissions() :
    ASSERT permission EXISTE dans CompanyPermissionCatalog::keys()
```

### 3.5 Invariants à ajouter — Routes

#### INV-ROUTE-001 : Toute route company mutante a une permission

```
POUR chaque route company (POST, PUT, PATCH, DELETE) :
  ASSERT route a middleware 'company.access:use-permission,{key}'
  OU route a middleware 'company.access:manage-structure'
```

> Les GET peuvent être module-only (philosophie transparence interne), mais les mutations DOIVENT avoir une permission.

#### INV-ROUTE-002 : Toute route platform a module + permission

```
POUR chaque route platform (sauf auth/2fa/me/nav) :
  ASSERT route a middleware 'module.active:{key}'
  ASSERT route a middleware 'platform.permission:{key}'
```

### 3.6 Invariants à ajouter — Frontend

#### INV-FE-001 : Router guard company vérifie les permissions

```
DANS guards.js, scope company :
  SI to.meta.permission est défini :
    ASSERT guard vérifie auth.hasPermission(to.meta.permission)
    ASSERT redirect vers company403 si refusé
```

#### INV-FE-002 : Pas de v-if permission hardcodé

```
DANS pages/ et views/ :
  ASSERT aucun v-if qui test auth.hasPermission() sur un composant entier
  (Les permissions doivent être gérées par le routeur ou le backend, pas par v-if)
EXCEPTION AUTORISÉE : boutons d'action (edit, delete) dans une page déjà autorisée
```

### 3.7 Implémentation CI

Fichier : `tests/Feature/AccessPipelineInvariantsTest.php`

Ce test PHP vérifie les invariants INV-PERM-001 à 005, INV-WIDGET-002, INV-ROUTE-001 et INV-ROUTE-002 en scannant les manifests, routes et registries.

Fichier : `tests/Feature/FrontendAccessInvariantsTest.php`

Ce test PHP parse les fichiers Vue pour vérifier INV-NAV-001, INV-WIDGET-001, INV-FE-002 (analyse statique des fichiers source).

---

## 4. Suppression de toute logique d'accès hors pipeline unique

### 4.1 Inventaire des accès hors pipeline

| # | Élément | Localisation | Type de problème |
|---|---------|-------------|------------------|
| H1 | OnboardingWidget hardcodé | `pages/dashboard.vue` L64 | Widget rendu sans aucun filtre, hors DashboardCatalogService |
| H2 | PlanBadgeWidget hardcodé | `pages/dashboard.vue` | Widget rendu sans filtre |
| H3 | Account Settings nav item | `useCompanyNav.js` L70-76 | Nav item statique hors manifest |
| H4 | Permission guard company manquant | `guards.js` | Platform a le guard, company non |
| H5 | Tabs international avec v-if | `platform/international/[tab].vue` L70-91 | Permission check dans le template au lieu de route meta |
| H6 | Owner bypass dupliqué 3× | `CompanyAccess`, `auth.js`, `DashboardCatalogService` | Même logique copié-collé |

### 4.2 Corrections requises

#### H1 + H2 : Intégrer les widgets hardcodés dans DashboardCatalogService

**Problème** : `OnboardingWidget` et `PlanBadgeWidget` sont rendus directement dans `dashboard.vue` sans passer par le widget engine.

**Solution** :
1. Créer `OnboardingWidget.php` et `PlanBadgeWidget.php` dans `app/Modules/Dashboard/Widgets/`
2. Déclarer `audience()`, `scope()`, `permissions()`, `archetypes()` appropriés
3. Enregistrer dans `DashboardWidgetRegistry`
4. Retirer les imports statiques de `dashboard.vue`
5. OnboardingWidget : `archetypes() => ['management']` + `permissions() => ['settings.manage']` (owner-only via bypass)
6. PlanBadgeWidget : `archetypes() => null` + `permissions() => []` (visible à tous)

#### H3 : Migrer Account Settings dans un manifest

**Problème** : `useCompanyNav.js` ajoute "Account Settings" en dur (L70-76).

**Solution** :
1. Ajouter un nav item `account-settings` dans le manifest du module `core.settings` (ou un nouveau module `core.account`)
2. Attributs : `permission: null`, `surface: null`, `group: 'account'`
3. NavBuilder le rend comme tout autre item
4. Retirer le bloc hardcodé de `useCompanyNav.js`

#### H4 : Ajouter le permission guard company dans le router

**Problème** : Le router guard vérifie `to.meta.permission` pour platform mais PAS pour company.

**Solution** :
Ajouter dans `guards.js`, section company (après le module guard) :

```js
// Permission guard (company)
if (to.meta.permission && !auth.hasPermission(to.meta.permission)) {
  createToast('Permission insuffisante', { type: 'error' })
  return { name: 'company403' }
}
```

Puis ajouter `meta.permission` aux pages company qui en ont besoin :
- `/company/members` → `permission: 'members.view'`
- `/company/roles` → `permission: 'roles.view'`
- `/company/profile` → `permission: 'settings.view'`

> Note : le backend middleware bloque déjà ces accès, donc pas de faille de sécurité actuelle. Mais l'UX est incohérente (pas de toast immédiat, la requête API échoue en 403 après navigation).

#### H5 : Migrer les tabs international dans des route meta

**Problème** : `platform/international/[tab].vue` utilise `v-if="platformAuth.hasPermission()"` pour cacher des tabs.

**Solution** :
1. Séparer les tabs en routes distinctes ou utiliser un système de tabs piloté par les permissions backend
2. Alternative : laisser le `v-if` sur les tabs MAIS ajouter aussi `meta.permission` sur les routes enfants pour que le router guard bloque l'accès URL direct

#### H6 : Centraliser le owner bypass

**Problème** : Owner bypass est copié dans 3 endroits indépendants.

**Solution** :
1. Créer un trait `HasOwnerBypass` ou une méthode statique `OwnerBypass::applies(User, Company, string $ability)`
2. L'utiliser dans `CompanyAccess`, `DashboardCatalogService`, et tout nouveau code
3. Frontend : `auth.hasPermission()` continue d'être le point unique (déjà centralisé dans le store)

### 4.3 Pipeline unifié cible

Après corrections, le pipeline d'accès company sera :

```
┌─────────────────────────────────────────────────────────────────┐
│ COUCHE 1 — SOURCE DE VÉRITÉ                                    │
│ ModuleManifest → permissions, bundles, navItems, widgets        │
│ JobdomainRegistry → presets (bundles par rôle)                  │
│ PresetReconciler → sync presets → DB (deploy-time)              │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ COUCHE 2 — MIDDLEWARE BACKEND (autorité)                        │
│ auth:sanctum → SetCompanyContext → company.access               │
│   → use-module (ModuleGate)                                     │
│   → use-permission (CompanyAccess → CompanyRole.permissions)    │
│   → access-surface (is_administrative check)                    │
│   → manage-structure (is_administrative check)                  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ COUCHE 3 — NAVIGATION BACKEND (pré-filtrage)                    │
│ NavBuilder → items filtrés par module + permission + surface    │
│ DashboardCatalogService → widgets filtrés par permission +      │
│   module + archetype                                            │
│ 100% manifest-driven (plus de hardcoded)                        │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ COUCHE 4 — ROUTER GUARD FRONTEND (UX)                           │
│ guards.js → surface guard + module guard + PERMISSION GUARD     │
│ Toast immédiat si accès refusé (au lieu de 403 API)             │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ COUCHE 5 — NAV FRONTEND (last-barrier)                          │
│ useCompanyNav → re-filtre items backend (defense-in-depth)      │
│ 100% depuis payload /nav (plus de hardcoded)                    │
└─────────────────────────────────────────────────────────────────┘
```

**Aucune logique d'accès ne doit exister en dehors de ces 5 couches.**

---

## 5. Plan de migration complet sans régression

### 5.1 Principes de migration

1. **Pas de big-bang** — chaque phase est indépendante et déployable
2. **Tests avant code** — écrire l'invariant CI avant d'implémenter le fix
3. **Backward-compatible** — chaque phase fonctionne avec l'état précédent
4. **Dry-run d'abord** — toute modification de données en production passe par dry-run
5. **ADR par phase** — chaque phase génère un ADR dans `04-decisions.md`

### 5.2 Phase 0 : CI Invariants (fondation)

**But** : Poser les tests qui vérifieront que le système est cohérent, AVANT de corriger.

**Actions** :
1. Créer `tests/Feature/AccessPipelineInvariantsTest.php` avec les invariants INV-PERM-001 à 005
2. Créer `tests/Feature/FrontendAccessInvariantsTest.php` avec INV-NAV-001, INV-WIDGET-001, INV-FE-002
3. Marquer les tests qui échouent comme `@group skip-until-phase-X` (pas `@skip`, pour traçabilité)
4. Vérifier que les tests qui DOIVENT passer passent déjà

**Fichiers touchés** :
- `tests/Feature/AccessPipelineInvariantsTest.php` (nouveau)
- `tests/Feature/FrontendAccessInvariantsTest.php` (nouveau)

**Risque de régression** : ZÉRO (ajout de tests seulement)

**ADR** : ADR-360 — CI invariants for access pipeline

---

### 5.3 Phase 1 : Permission guard company frontend

**But** : Aligner le router guard company sur le même niveau que platform.

**Actions** :
1. Ajouter le permission guard dans `guards.js` (section company, après module guard)
2. Ajouter `meta.permission` aux pages company qui le nécessitent :
   - `/company/members` → `members.view`
   - `/company/roles` → `roles.view`
   - `/company/profile` → `settings.view`
   - `/company/shipments` → `shipments.view`
   - `/company/my-deliveries` → `shipments.view_own`
3. Retirer le `@group skip-until-phase-1` des tests concernés

**Fichiers touchés** :
- `resources/js/plugins/1.router/guards.js`
- `resources/js/pages/company/members/index.vue` (definePage meta)
- `resources/js/pages/company/roles.vue` (definePage meta)
- `resources/js/pages/company/profile/[tab].vue` (definePage meta)
- `resources/js/pages/company/shipments/index.vue` (definePage meta)
- `resources/js/pages/company/my-deliveries/index.vue` (definePage meta)

**Risque de régression** : FAIBLE — le backend bloque déjà ces accès, le frontend ajoute un toast UX immédiat. Si un utilisateur avait accès via URL direct avant, il sera maintenant redirigé proprement.

**ADR** : ADR-361 — Company frontend permission guard

---

### 5.4 Phase 2 : Widgets dans le pipeline

**But** : Éliminer les widgets hardcodés dans `dashboard.vue`.

**Actions** :
1. Créer `app/Modules/Dashboard/Widgets/OnboardingWidget.php` avec :
   - `audience()` → `'company'`
   - `scope()` → `'company'`
   - `permissions()` → `['settings.manage']` (seul l'owner verra via bypass)
   - `archetypes()` → `['management']`
2. Créer `app/Modules/Dashboard/Widgets/PlanBadgeWidget.php` avec :
   - `permissions()` → `[]` (visible à tous)
   - `archetypes()` → `null` (tous archetypes)
3. Enregistrer les deux dans `DashboardWidgetRegistry`
4. Modifier `dashboard.vue` pour rendre ces widgets via le catalog au lieu de les importer statiquement
5. Retirer le `@group skip-until-phase-2` des tests concernés

**Fichiers touchés** :
- `app/Modules/Dashboard/Widgets/OnboardingWidget.php` (nouveau)
- `app/Modules/Dashboard/Widgets/PlanBadgeWidget.php` (nouveau)
- `app/Modules/Dashboard/DashboardWidgetRegistry.php` (registration)
- `resources/js/pages/dashboard.vue` (retirer imports statiques)
- Widget Vue components (adapter pour le système dynamique si nécessaire)

**Risque de régression** : MOYEN — le widget engine doit supporter le rendu de ces widgets. Tester sur staging.

**ADR** : ADR-362 — All widgets through DashboardCatalogService

---

### 5.5 Phase 3 : Nav items 100% manifest-driven

**But** : Éliminer le nav item Account Settings hardcodé.

**Actions** :
1. Ajouter un nav item `account-settings` dans le manifest `SettingsModule` (ou créer un `AccountModule`)
2. Configurer : `permission: null`, `group: 'account'`
3. NavBuilder rend le groupe `account` en fin de sidebar
4. Retirer le bloc hardcodé de `useCompanyNav.js` L70-76
5. Retirer le `@group skip-until-phase-3` des tests concernés

**Fichiers touchés** :
- `app/Modules/Core/Settings/SettingsModule.php` (navItems)
- `resources/js/composables/useCompanyNav.js` (retirer hardcoded)
- `app/Core/Navigation/NavBuilder.php` (supporter groupe `account` si nécessaire)

**Risque de régression** : FAIBLE — le nav item existe déjà, on change juste sa source.

**ADR** : ADR-363 — Manifest-driven navigation (no hardcoded items)

---

### 5.6 Phase 4 : PresetReconciler + deploy integration

**But** : Éliminer la dérive de permissions entre anciennes et nouvelles companies.

**Actions** :
1. Créer `app/Core/Jobdomains/PresetReconciler.php`
2. Créer `app/Console/Commands/PresetReconcileCommand.php`
3. Ajouter logging strict dans `ModuleRegistry::resolveBundles()` (warning si bundle inconnu)
4. Créer migration pour table `company_preset_snapshots`
5. Modifier `JobdomainGate::assignToCompany()` pour sauvegarder un snapshot
6. Intégrer `permissions:reconcile` dans `deploy/deploy_release.sh`
7. Exécuter `permissions:reconcile --dry-run` en staging pour valider
8. Exécuter `permissions:reconcile` en production

**Fichiers touchés** :
- `app/Core/Jobdomains/PresetReconciler.php` (nouveau)
- `app/Console/Commands/PresetReconcileCommand.php` (nouveau)
- `app/Core/Modules/ModuleRegistry.php` (logging dans resolveBundles)
- `app/Core/Jobdomains/JobdomainGate.php` (snapshot à la registration)
- `database/migrations/YYYY_MM_DD_XXXXXX_create_company_preset_snapshots_table.php` (nouveau)
- `deploy/deploy_release.sh` (ajouter étape 6.5)
- `database/seeders/SystemSeeder.php` (optionnel : appeler reconciler)

**Risque de régression** : ÉLEVÉ — modifie les permissions de TOUTES les companies existantes. Dry-run obligatoire, rollback préparé.

**Mitigation** :
- Dry-run en staging + production AVANT application
- Audit trail de chaque changement
- Rollback via snapshot (table `company_preset_snapshots`)
- Test spécifique : `ReconcilePermissionsTest.php`

**ADR** : ADR-364 — Preset reconciliation engine

---

### 5.7 Phase 5 : Cleanup et audit

**But** : Finaliser l'unification et nettoyer les vestiges.

**Actions** :
1. Ajouter audit de drift automatique post-deploy (commande qui compare presets vs DB, log les écarts)
2. Migrer tabs `platform/international` dans des route meta au lieu de `v-if` (H5)
3. Centraliser owner bypass dans un trait `HasOwnerBypass` (H6)
4. Ajouter `meta.permission` manquantes aux routes backend GET qui en ont besoin
5. Retirer tous les `@group skip-until-phase-X` restants
6. Vérifier que TOUS les tests CI passent au vert

**Fichiers touchés** :
- `app/Company/Security/OwnerBypass.php` (nouveau trait)
- `resources/js/pages/platform/international/[tab].vue` (route meta)
- `routes/company.php` (ajouter permissions sur GET routes)
- Tests (retirer skip groups)

**Risque de régression** : FAIBLE (nettoyage, pas de changement fonctionnel majeur)

**ADR** : ADR-365 — Access pipeline unification cleanup

---

### 5.8 Ordre de déploiement

```
Phase 0 (CI)       ──── Aucun risque, deploy immédiat
     ↓
Phase 1 (Guard)    ──── Risque faible, deploy après tests staging
     ↓
Phase 2 (Widgets)  ──── Risque moyen, deploy après validation UX staging
     ↓
Phase 3 (Nav)      ──── Risque faible, deploy après tests staging
     ↓
Phase 4 (Resync)   ──── Risque élevé, dry-run obligatoire prod + staging
     ↓
Phase 5 (Cleanup)  ──── Risque faible, deploy final
```

Chaque phase est un commit (ou PR) séparé. Rollback possible par phase.

---

## Annexe A : Catalogue complet des bundles

| Bundle Key | Module | Permissions incluses | is_admin |
|------------|--------|----------------------|----------|
| `theme.full` | core.theme | `theme.view`, `theme.manage` | false |
| `theme.readonly` | core.theme | `theme.view` | false |
| `members.team_access` | core.members | `members.view`, `members.invite` | false |
| `members.team_management` | core.members | `members.manage`, `members.credentials` | true |
| `members.sensitive_data` | core.members | `members.sensitive_read` | true |
| `settings.company_info` | core.settings | `settings.view` | false |
| `settings.company_management` | core.settings | `settings.manage` | true |
| `roles.governance` | core.roles | `roles.view`, `roles.manage` | true |
| `jobdomain.info` | core.jobdomain | `jobdomain.view` | false |
| `jobdomain.management` | core.jobdomain | `jobdomain.manage` | true |
| `shipments.operations` | logistics_shipments | `shipments.view`, `shipments.create`, `shipments.manage_status`, `shipments.assign` | false |
| `shipments.administration` | logistics_shipments | `shipments.manage_fields`, `shipments.delete` | true |
| `shipments.delivery` | logistics_shipments | `shipments.view_own`, `shipments.manage_status` | false |

## Annexe B : Tous les points de contrôle d'accès (inventaire)

### Backend (autorité)

| Couche | Composant | Fichier | Rôle |
|--------|-----------|---------|------|
| Middleware | `auth:sanctum` | Laravel | Authentification |
| Middleware | `SetCompanyContext` | `app/Company/Http/Middleware/` | Résout company + vérifie membership + suspension |
| Middleware | `EnsureCompanyAccess` | `app/Company/Http/Middleware/` | Vérifie abilities (module, permission, surface, structure) |
| Middleware | `auth:platform` | Laravel | Auth platform |
| Middleware | `EnsurePlatformPermission` | `app/Platform/Http/Middleware/` | Permission platform |
| Middleware | `RequirePlatform2FA` | `app/Http/Middleware/` | 2FA obligatoire |
| Middleware | `EnsureModuleActive` | `app/Http/Middleware/` | Module actif (global ou company) |
| Middleware | `SessionGovernance` | `app/Http/Middleware/` | Idle timeout |
| Service | `CompanyAccess` | `app/Company/Security/` | Logique unifiée d'accès company |
| Service | `ModuleGate` | `app/Core/Modules/` | Activation module (source de vérité) |
| Service | `NavBuilder` | `app/Core/Navigation/` | Filtrage nav items |
| Service | `DashboardCatalogService` | `app/Modules/Dashboard/` | Filtrage widgets |

### Frontend (UX)

| Couche | Composant | Fichier | Rôle |
|--------|-----------|---------|------|
| Router guard | beforeEach | `plugins/1.router/guards.js` | Surface + module + (bientôt) permission |
| Nav composable | useCompanyNav | `composables/useCompanyNav.js` | Re-filtre items backend |
| Nav composable | usePlatformNav | `composables/usePlatformNav.js` | Re-filtre items backend |
| Store | auth.js | `core/stores/auth.js` | `hasPermission()`, `roleLevel`, `isOwner` |
| Store | platformAuth.js | `core/stores/platformAuth.js` | `hasPermission()`, `isSuperAdmin` |

---

---

## 6. Validation de cohérence produit/UX/métier

### 6.1 Grille d'évaluation : chaque rôle dans un SaaS logistique réel

> Pour chaque rôle, la question n'est pas "est-ce techniquement correct ?" mais "est-ce qu'un vrai utilisateur de ce métier peut travailler sans friction ?"

---

#### OWNER — Pilote d'entreprise

| Critère | Verdict | Détail |
|---------|---------|--------|
| Landing page | ✅ OK | `/dashboard` avec KPI compliance — approprié pour un dirigeant |
| Sidebar | ✅ OK | Accès complet, tous modules visibles |
| Dashboard | ⚠️ LACUNE | Seulement 5 widgets compliance. **ZERO KPI business** : pas de CA, marge, nb expéditions, taux livraison à temps |
| Billing | ✅ OK | Accès complet, mutations autorisées |
| Onboarding | ⚠️ BUG CONNU | Visible pour tous (devrait être owner/admin only) |
| Pages utiles | ✅ | Toutes accessibles |
| Friction majeure | **Dashboard trop pauvre** | Un dirigeant veut voir la santé business d'un coup d'oeil, pas juste la compliance documentaire |

**Ajustements produit** :
- P1 : Widgets business à créer pour le dashboard Owner (cf. section 6.3)
- P2 : Raccourcis "Quick actions" sur le dashboard (invite member, create shipment, view last invoice)

---

#### MANAGER — Responsable RH/équipe

| Critère | Verdict | Détail |
|---------|---------|--------|
| Landing page | ⚠️ INADAPTÉ | Atterrit sur `/dashboard` — devrait atterrir sur `/company/members` ou au minimum un dashboard centré compliance |
| Sidebar | ⚠️ BRUIT | Voit "Modules" sans pouvoir les activer (frustrant), PAS de Plan ni Billing (correct) |
| Dashboard | ✅ BON | 5 widgets compliance = exactement son métier (conformité documents équipe) |
| Onboarding | ❌ INCOHÉRENT | Voit le widget onboarding Owner (invite member, payment method, plan) alors qu'il n'a PAS accès au plan/billing |
| Pages utiles | ✅ | Members + Roles + Company Profile = son périmètre |
| Friction majeure | **Onboarding trompeur** | Le widget lui dit "setup your plan" mais il ne peut PAS accéder au plan |

**Ajustements produit** :
- P0 : Onboarding filtré par rôle (owner-only pour steps billing)
- P1 : Cacher "Modules" dans la sidebar si pas de `modules.manage`
- P2 : Option workspace → atterrir sur Members au lieu du Dashboard

---

#### OPS MANAGER — Chef d'exploitation

| Critère | Verdict | Détail |
|---------|---------|--------|
| Landing page | ❌ CATASTROPHIQUE | Atterrit sur `/dashboard` qui est **quasi vide** pour lui (il a `members.manage` donc 5 widgets compliance, mais ZERO widget opérations) |
| Sidebar | ⚠️ PAUVRE | Voit Dashboard + Members + Roles + Company Profile + Shipments = 5 items. Manque un hub opérations |
| Dashboard | ❌ INADAPTÉ | Les widgets compliance sont utiles mais secondaires — son job principal est Shipments, pas la conformité doc |
| Billing | ❌ FUITE | Routes GET billing non protégées : accès lecture direct par URL (`/company/billing/overview`) même sans nav item visible |
| Pages | ⚠️ | Shipments list = table basique, pas de Kanban, pas de filters avancés |
| Friction majeure | **Aucun KPI opérations** | Ne voit pas : nb expéditions today, en transit, livrées, en retard, drivers actifs |

**Ajustements produit** :
- P0 : Widgets opérations à créer (shipments.today, in_transit, late, drivers.active)
- P0 : Redirect Ops Manager vers `/home` (workspace opérations) avec ces widgets
- P1 : Routes GET billing → ajouter `manage-structure` sur les données sensibles (prix plan, factures, moyens de paiement)
- P2 : Shipments Kanban (Draft / Planned / In Transit / Delivered)

---

#### DISPATCHER — Répartiteur

| Critère | Verdict | Détail |
|---------|---------|--------|
| Landing page | ❌ CATASTROPHIQUE | Atterrit sur `/dashboard` qui est **VIDE** (1 seul widget : compliance.rate sans permission detail) |
| Sidebar | ❌ TROP PAUVRE | 2 items utiles : Dashboard (vide) + Shipments. C'est tout. |
| Dashboard | ❌ VIDE | ZERO widget pertinent pour son métier (aucun KPI expéditions, drivers, livraisons) |
| Shipments | ⚠️ | Peut voir/créer/assigner mais pas de contexte driver (disponibilité, charge, localisation) |
| Billing | ❌ FUITE | Même fuite que Ops Manager (accès lecture direct URL) |
| Friction majeure | **Assignation aveugle** | Assigne un driver sans savoir s'il est libre/chargé/absent |

**Ajustements produit** :
- P0 : Widgets opérations (mêmes que Ops Manager)
- P0 : Redirect Dispatcher vers `/home` (workspace opérations)
- P1 : Driver picker enrichi (photo, statut, nb deliveries in progress)
- P1 : Expéditions non-assignées comme KPI prioritaire
- P2 : Quick filters "Unassigned today", "Planned today"

---

#### DRIVER — Chauffeur-livreur

| Critère | Verdict | Détail |
|---------|---------|--------|
| Landing page | ❌ CATASTROPHIQUE | Workspace = `home` mais redirect fonctionne vers `/home`. Le **vrai problème** : si `/home` est aussi vide que `/dashboard`, c'est une page blanche |
| Sidebar | ⚠️ BRUIT | Voit Dashboard (vide/inutile) + Home + My Deliveries + Notifications + Support. Dashboard est un leurre |
| Dashboard | ❌ NON APPLICABLE | ZERO widget, page 100% vide — ne devrait JAMAIS atterrir ici |
| My Deliveries | ⚠️ BASIQUE | Table sans quick actions (boutons "Start" / "Delivered"), sans segmentation Today/Tomorrow, sans navigation GPS |
| Billing | ❌ FUITE | Même fuite (accès lecture URL) — un driver peut voir les factures de l'entreprise |
| Friction majeure | **Aucun outil terrain** | Pas de photo proof, signature, notes livraison, bouton "naviguer vers", bouton "appeler client" |

**Ajustements produit** :
- P0 : Cacher "Dashboard" de la sidebar pour archetype `field_worker` (inutile, confusant)
- P0 : Widget "Mes livraisons du jour" + "Prochaine livraison" sur `/home`
- P1 : Quick actions sur My Deliveries : "Start" → in_transit, "Delivered" → delivered
- P1 : Bouton "Naviguer" (deep link Google Maps/Waze)
- P2 : Photo proof livraison, notes, signature
- P3 : Mode offline (PWA)

---

### 6.2 Incohérences métier détectées

| # | Incohérence | Impact | Correction produit |
|---|-------------|--------|-------------------|
| IM-1 | **Billing en lecture pour TOUS via URL** | Un driver peut voir les factures, le prix du plan, les 4 derniers chiffres des cartes en tapant l'URL | Ajouter `manage-structure` sur les routes GET billing sensibles |
| IM-2 | **Onboarding universel** | Le widget onboarding dit "Setup your plan" à un Dispatcher qui n'a PAS accès au plan | Filtrer steps onboarding par permissions réelles du rôle |
| IM-3 | **Dashboard vide pour 3 rôles sur 5** | Dispatcher, Driver, et partiellement Ops Manager voient un dashboard quasi-vide | Créer des widgets opérations pertinents par archetype |
| IM-4 | **Sidebar "Dashboard" pour field_worker** | Le Driver voit "Dashboard" dans le menu mais la page est vide | Cacher l'item si aucun widget disponible pour ce rôle |
| IM-5 | **Modules visible sans pouvoir agir** | Le Manager voit "Modules" mais ne peut rien activer | Cacher si pas de permission `modules.manage` |
| IM-6 | **Plan et Billing séparés dans la nav** | 2 entrées pour le même sujet métier (facturation) | Fusionner en un seul item avec tabs |
| IM-7 | **Assignation driver sans contexte** | Le Dispatcher assigne un driver aveuglément (pas d'info disponibilité/charge) | Enrichir le picker driver avec statut et charge |
| IM-8 | **Pas de KPI opérations** | 17 widgets disponibles : 5 compliance + 12 billing(platform). ZERO opérations | Créer 6-8 widgets opérations (shipments, deliveries, drivers) |
| IM-9 | **My Deliveries sans outils terrain** | Table basique sans quick actions, map, photo, signature | Enrichir progressivement (P1-P3) |
| IM-10 | **Audit invisible** | Module core.audit existe mais AUCUN nav item déclaré | Ajouter nav item ou supprimer le module/page |

### 6.3 Widgets opérations manquants (détail)

Le système de widgets est EXCELLENT techniquement (DashboardCatalogService avec filtrage multi-critères), mais le catalog est **vide côté opérations**. Voici les widgets à créer :

| Widget | Permissions | Archetypes | Audience | Description métier |
|--------|------------|------------|----------|-------------------|
| `shipments.today` | `shipments.view` | management, operations_center | company | Nb expéditions prévues aujourd'hui |
| `shipments.in_transit` | `shipments.view` | management, operations_center | company | Expéditions en cours de livraison |
| `shipments.late` | `shipments.view` | management, operations_center | company | Livraisons en retard (deadline dépassée) |
| `shipments.unassigned` | `shipments.assign` | operations_center | company | Expéditions non assignées à un driver |
| `drivers.active` | `shipments.assign` | management, operations_center | company | Drivers avec livraisons en cours |
| `deliveries.my_today` | `shipments.view_own` | field_worker | company | Mes livraisons du jour |
| `deliveries.next` | `shipments.view_own` | field_worker | company | Ma prochaine livraison (destination + heure) |
| `deliveries.completed_today` | `shipments.view_own` | field_worker | company | Livraisons terminées aujourd'hui / total |

> Ces widgets passent par le même DashboardCatalogService — le filtrage par archetype + permissions garantit que chaque rôle voit uniquement les siens.

### 6.4 Matrice produit corrigée : ce que chaque rôle DEVRAIT vivre

| Dimension | Owner | Manager | Ops Manager | Dispatcher | Driver |
|-----------|-------|---------|-------------|------------|--------|
| **Landing** | `/dashboard` | `/dashboard` | `/home` | `/home` | `/home` |
| **Dashboard** | 5 compliance + business KPI | 5 compliance | (uses /home) | (uses /home) | (uses /home) |
| **Home** | — | — | shipments KPI + drivers | unassigned + in transit | my today + next delivery |
| **Sidebar** | 10+ items | 6 items (sans Modules) | 4 items | 3 items | 3 items (sans Dashboard) |
| **Billing visible** | OUI (complet) | NON | NON | NON | NON |
| **Shipments** | vue complète | vue complète | vue complète + Kanban | vue complète + assign | MES livraisons uniquement |
| **Onboarding** | OUI (toutes les steps) | OUI (steps RH) | NON | NON | NON |
| **Notifications** | all | compliance + team | shipments + delays | shipments assigned | delivery assigned |

---

## 7. Invariants UX

> Un invariant UX est une propriété qui doit être VRAIE à tout instant, indépendamment du rôle, de la page ou de l'état. Si un invariant est violé, c'est un bug produit, pas un bug technique.

### 7.1 Invariants de navigation

| ID | Invariant | Justification |
|----|-----------|---------------|
| **UX-NAV-01** | **Aucun item de sidebar ne mène à une page vide ou inaccessible** | Un clic sur un menu doit toujours produire du contenu utile. Si la page est vide (dashboard sans widgets, page 403), l'item ne doit PAS être visible. |
| **UX-NAV-02** | **Aucun item de sidebar ne montre une action impossible** | Si l'utilisateur ne peut pas activer de module, "Modules" ne doit pas apparaître. Voir sans pouvoir agir = frustration. Exception : lecture utile (Members en read-only). |
| **UX-NAV-03** | **La landing page contient du contenu pertinent pour le rôle** | Si le rôle atterrit sur `/dashboard` et qu'il n'y a aucun widget → violation. Le workspace doit être enrichi ou le rôle redirigé. |
| **UX-NAV-04** | **Le nombre d'items sidebar est proportionnel au périmètre du rôle** | Un Driver avec 6 items sidebar dont 4 inutiles = pollution cognitive. Réduire à l'essentiel. |

### 7.2 Invariants d'accès

| ID | Invariant | Justification |
|----|-----------|---------------|
| **UX-ACC-01** | **Aucun accès refusé APRÈS un clic** | Si l'utilisateur clique sur un lien/bouton/item visible, il ne doit JAMAIS recevoir une erreur 403 ou une page "Permission required". Si l'action est interdite, le bouton/lien est caché ou désactivé. |
| **UX-ACC-02** | **Aucune donnée sensible accessible par URL directe** | Si l'information n'est pas visible dans la sidebar, elle ne doit pas être accessible en tapant l'URL. Le router guard frontend + le middleware backend doivent bloquer de manière cohérente. |
| **UX-ACC-03** | **Les mutations correspondent aux permissions** | Si un bouton "Supprimer" est visible, l'utilisateur PEUT supprimer. Si l'action va échouer en 403, le bouton n'est pas rendu. |
| **UX-ACC-04** | **Le toast d'erreur arrive AVANT le chargement de page** | Si l'utilisateur n'a pas la permission, le router guard le bloque immédiatement avec un toast clair — pas un chargement de page suivi d'un 403 API. |

### 7.3 Invariants d'onboarding

| ID | Invariant | Justification |
|----|-----------|---------------|
| **UX-ONB-01** | **L'onboarding ne montre que des steps actionnables** | Si une step dit "Setup your plan" mais que le rôle n'a PAS accès au plan, la step ne doit PAS être affichée. Chaque step visible = cliquable + réalisable. |
| **UX-ONB-02** | **L'onboarding disparaît quand terminé ou non pertinent** | Pas de widget onboarding persistant pour un rôle qui n'est pas concerné. Auto-hide après completion ou après 7 jours. |
| **UX-ONB-03** | **L'onboarding est ciblé par archetype** | Owner = steps business (plan, billing, invite). Manager = steps RH (members, documents). Ops/Dispatcher/Driver = pas d'onboarding générique (la prise en main se fait via le workspace). |

### 7.4 Invariants de contenu

| ID | Invariant | Justification |
|----|-----------|---------------|
| **UX-CTN-01** | **Aucune page visible qui n'apporte pas de valeur au rôle** | Si une page est accessible mais ne contient rien d'utile (dashboard vide, profile en lecture seule sans info), elle ne doit pas être dans la navigation. |
| **UX-CTN-02** | **Les widgets dashboard sont pertinents pour le métier du rôle** | Un Driver ne voit pas des widgets compliance (pas son métier). Un Owner ne voit pas que de la compliance (il veut aussi du business). |
| **UX-CTN-03** | **Les actions visibles correspondent au workflow du rôle** | Un Driver ne voit que "Start delivery" et "Mark delivered", pas "Assign driver" ni "Delete shipment". |
| **UX-CTN-04** | **Les notifications sont filtrées par pertinence métier** | Un Driver reçoit "New delivery assigned to you", pas "Plan upgrade required" ni "Member document overdue". |

### 7.5 Invariants de cohérence

| ID | Invariant | Justification |
|----|-----------|---------------|
| **UX-COH-01** | **Backend et frontend sont d'accord sur ce qui est visible** | Si le backend filtre un nav item, le frontend ne doit pas le réintroduire (pas de hardcoded). Si le frontend cache un bouton, le backend ne doit pas accepter l'action quand même. |
| **UX-COH-02** | **Un item de menu mène toujours à la même page** | Pas de redirect surprise. "Billing" → `/billing/overview`, pas parfois `/billing/plan` et parfois `/billing/overview` selon le rôle. |
| **UX-COH-03** | **Le même concept a le même nom partout** | "Shipments" dans la sidebar, "Shipments" dans le breadcrumb, "Shipments" dans le titre de page. Pas "Deliveries" à un endroit et "Shipments" à un autre (sauf "My Deliveries" = scope différent). |

### 7.6 Violations actuelles des invariants UX

| Invariant violé | Lieu | Détail |
|-----------------|------|--------|
| **UX-NAV-01** | Dashboard pour Driver | Dashboard = page vide (0 widget), mais item visible dans sidebar |
| **UX-NAV-02** | Modules pour Manager | Visible mais aucune action possible (ni activate ni deactivate) |
| **UX-NAV-03** | Dashboard pour Dispatcher | Landing = dashboard quasi-vide (1 widget non pertinent) |
| **UX-ACC-01** | Onboarding "Setup plan" pour Manager | Clic → redirect vers page plan → 403 ou page inaccessible |
| **UX-ACC-02** | Billing GET pour Dispatcher/Driver | URL directe `/company/billing/overview` → affiche factures + prix plan |
| **UX-ACC-04** | Company pages sans permission guard | Pas de toast immédiat, l'API renvoie 403 après chargement |
| **UX-ONB-01** | Onboarding pour tous les rôles | Steps billing visibles pour rôles sans accès billing |
| **UX-ONB-02** | Onboarding persistant | Jamais masqué pour les rôles non concernés |
| **UX-CTN-01** | Dashboard pour Dispatcher/Driver | Page accessible mais 0 contenu utile |
| **UX-CTN-02** | Dashboard pour Ops Manager | Widgets compliance uniquement, 0 widget opérations (son vrai métier) |
| **UX-COH-01** | Account Settings hardcodé | Frontend ajoute un nav item que le backend ne connaît pas |

---

## 8. Architecture informationnelle — pages, menus, tabs

### 8.1 Fusions recommandées (company)

#### F1 — Plan + Billing → un seul espace "Billing"

**Problème** : 2 entrées de nav pour le même sujet métier. Un propriétaire pense "facturation" comme un tout.

**Solution** : Fusionner en `/billing/[tab].vue` avec 5 onglets :

```
Billing & Plan
  ├─ Plan          → choix/upgrade de plan (actuel plan.vue)
  ├─ Overview      → résumé facturation
  ├─ Invoices      → historique factures
  ├─ Payment Methods → cartes / SEPA
  └─ Activity      → timeline actions billing
```

**Impact nav** : 2 items → 1 item. Plus clair, plus logique.

#### F2 — Settings (redirect) → supprimer

**Problème** : `/company/settings` ne fait que rediriger vers `/company/profile/overview`. Page morte.

**Solution** : Supprimer `settings.vue`. Le manifest pointe directement vers `/company/profile/overview`.

#### F3 — Audit → visible ou supprimé

**Problème** : Module `core.audit` existe, page `/company/audit/index.vue` existe, mais AUCUN nav item déclaré. Page orpheline.

**Solution** :
- Si l'audit est utile : ajouter nav item (surface `structure`, permission `audit.view`)
- Si pas encore prêt : supprimer la page et le module de la nav (garder le backend pour plus tard)

### 8.2 Fusions recommandées (platform)

#### F4 — Users + Roles + Fields → "Users & Access"

**Problème** : 3 entrées de nav séparées pour des pages légères qui relèvent du même sujet (gouvernance utilisateurs platform).

**Solution** : Fusionner en `/users/[tab].vue` avec 3 onglets :

```
Users & Access
  ├─ Users    → liste/CRUD platform users
  ├─ Roles    → liste/CRUD platform roles
  └─ Fields   → liste/CRUD custom fields
```

**Impact nav** : 3 items → 1 item. Sidebar platform moins encombrée.

### 8.3 Pages correctement organisées (ne pas toucher)

| Page | Organisation | Verdict |
|------|-------------|---------|
| Company Profile (2 tabs) | overview + documents | ✅ Bien calibré |
| Company Billing (4 tabs) | overview + invoices + payment + activity | ✅ Bien calibré |
| Company Members (list + detail) | index + [id] avec tabs embedded | ✅ Pattern standard |
| Company Shipments (CRUD) | index + create + [id] | ✅ Pattern standard |
| Platform Billing (8 tabs + 5 advanced) | Séparation courante/avancée | ✅ Excellent |
| Platform Settings (6 tabs) | general, theme, sessions, maintenance, billing, notifications | ✅ Bien calibré |
| Platform International (4 tabs) | markets, languages, translations, fx | ✅ Cohérent |
| Platform Companies detail (4 tabs) | overview, billing, members, activity | ✅ Pattern "360° client" |

### 8.4 Pages qui NE doivent PAS être fusionnées

| Pages | Raison de séparation |
|-------|---------------------|
| Members + Roles (company) | Trop denses individuellement, utilisateurs les pensent comme 2 tâches distinctes |
| Shipments + My Deliveries | Périmètres métier différents (vue globale vs mes livraisons), rôles différents |
| Jobdomains + International (platform) | Concepts métier distincts malgré la proximité sémantique |

### 8.5 Sidebar cible par rôle (après corrections)

#### Owner

```
── Dashboard                    (KPI compliance + business)
── [OPERATIONS]
   ├─ Shipments                 (si module actif)
── [STRUCTURE]
   ├─ Members
   ├─ Roles
   ├─ Company Profile
── [GOVERNANCE]
   ├─ Billing & Plan
   ├─ Modules
   ├─ Audit                     (si nav item ajouté)
── [ACCOUNT]
   ├─ Notifications
   ├─ Support
   ├─ Account Settings
```

#### Manager

```
── Dashboard                    (KPI compliance)
── [STRUCTURE]
   ├─ Members
   ├─ Roles
   ├─ Company Profile
── [OPERATIONS]
   ├─ Shipments                 (si module actif)
── [ACCOUNT]
   ├─ Notifications
   ├─ Support
   ├─ Account Settings
```

> PAS de Modules (pas de `modules.manage`). PAS de Billing (pas de `billing.manage`). PAS d'Audit (si pas de permission).

#### Ops Manager

```
── Home                         (workspace ops : widgets shipments + drivers)
── [OPERATIONS]
   ├─ Shipments
── [STRUCTURE]
   ├─ Members
   ├─ Roles
   ├─ Company Profile
── [ACCOUNT]
   ├─ Notifications
   ├─ Support
   ├─ Account Settings
```

> Dashboard remplacé par Home comme point d'entrée. Structure accessible (is_administrative=true).

#### Dispatcher

```
── Home                         (workspace ops : widgets unassigned + in transit)
── [OPERATIONS]
   ├─ Shipments
── [ACCOUNT]
   ├─ Notifications
   ├─ Support
   ├─ Account Settings
```

> 4 items seulement. Pas de structure (operational). Pas de Dashboard (vide = inutile).

#### Driver

```
── Home                         (mes livraisons today + prochaine)
── [OPERATIONS]
   ├─ My Deliveries
── [ACCOUNT]
   ├─ Notifications
   ├─ Support
   ├─ Account Settings
```

> 4 items seulement. Pas de Dashboard (vide = caché). Pas de Shipments (pas de `shipments.view`).

### 8.6 Métriques d'architecture informationnelle

| Métrique | Avant | Après corrections |
|----------|-------|-------------------|
| Nav items company (Owner) | 12 | 10 (fusion Plan+Billing, suppression Settings redirect) |
| Nav items company (Manager) | 7 | 6 (retrait Modules) |
| Nav items company (Dispatcher) | 4 | 4 (Dashboard → Home) |
| Nav items company (Driver) | 5 | 4 (retrait Dashboard) |
| Pages vides accessibles | 3 (dashboard pour 3 rôles) | 0 |
| Nav items platform | 20 | 18 (fusion Users+Roles+Fields) |
| Violations UX-NAV-01 | 3 | 0 |
| Violations UX-ACC-02 | 1 (billing GET) | 0 |

---

## Synthèse : ordre de priorité produit

### P0 — Bloquant (SaaS inexploitable pour rôles opérationnels)

| # | Action | Sections liées |
|---|--------|----------------|
| 1 | Créer 6-8 widgets opérations (shipments, deliveries, drivers) | 6.3, 6.4 |
| 2 | Redirect landing page par archetype (ops → /home, driver → /home) | 6.4, 7.1 |
| 3 | Filtrer onboarding par permissions réelles | 6.2-IM2, 7.3 |
| 4 | Protéger routes GET billing sensibles (`manage-structure`) | 6.2-IM1, 7.2 |

### P1 — Critique (friction UX significative)

| # | Action | Sections liées |
|---|--------|----------------|
| 5 | Permission guard company dans le router (toast immédiat) | 4, 7.2-UX-ACC-04 |
| 6 | Cacher Dashboard de la sidebar si 0 widgets disponibles | 7.1-UX-NAV-01 |
| 7 | Cacher Modules si pas de `modules.manage` | 7.1-UX-NAV-02 |
| 8 | Fusionner Plan + Billing dans la nav | 8.1-F1 |
| 9 | My Deliveries : quick actions (Start/Delivered) | 6.1 Driver |
| 10 | Supprimer Settings redirect | 8.1-F2 |

### P2 — Amélioration (UX complète)

| # | Action | Sections liées |
|---|--------|----------------|
| 11 | Intégrer widgets dans DashboardCatalogService (plus de hardcoded) | 4-H1,H2 |
| 12 | Nav 100% manifest-driven (retirer Account Settings hardcoded) | 4-H3 |
| 13 | PresetReconciler + deploy integration | 2 |
| 14 | Fusionner Users+Roles+Fields (platform) | 8.2-F4 |
| 15 | Shipments Kanban view | 6.1 Ops Manager |
| 16 | Driver picker enrichi (disponibilité, charge) | 6.2-IM7 |

### P3 — Amélioration future

| # | Action |
|---|--------|
| 17 | Bouton "Naviguer" (deep link Maps/Waze) pour Driver |
| 18 | Photo proof livraison + notes |
| 19 | CI invariants complets (INV-PERM-001 à INV-FE-002) |
| 20 | Owner Executive Dashboard (CA, marge, KPI business) |
| 21 | Mode offline Driver (PWA) |

---

**FIN DE L'ADDENDUM — En attente de validation avant implémentation.**
