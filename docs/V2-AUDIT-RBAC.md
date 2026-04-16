# V2-AUDIT-RBAC — Permissions & Contrôle d'Accès Frontend

> Mode : Security Architect | Audit exhaustif du système RBAC backend + gap critique frontend

## 1. Contexte actuel

Leezr dispose d'un système RBAC complet en backend, avec une architecture 2-tiers (Company + Platform), centralisée autour de `CompanyAccess` (ADR-061). Le backend protège chaque route via des middlewares combinant module activation (`use-module`) et vérification de permissions (`use-permission`).

Côté frontend, les fondations existent (composables, route guards, auth stores) mais ne sont **pas systématiquement appliquées**. La navigation est filtrée par permissions, mais les composants UI (boutons, actions, champs) ne sont pas gatés. Il n'existe aucune directive `v-can`, aucun masquage conditionnel des actions CRUD.

## 2. État existant

### Backend — Architecture RBAC Company

**CompanyPermission** (`app/Company/RBAC/CompanyPermission.php`) : Modèle simple (`key`, `label`, `module_key`, `is_admin`). Relations: `belongsToMany(CompanyRole)`.

**CompanyRole** (`app/Company/RBAC/CompanyRole.php`) : Attributs: `company_id`, `key`, `name`, `is_system`, `is_administrative`, `archetype`, `required_tags`, `field_config`, `doc_config`. Méthodes: `hasPermission(key)`, `syncPermissionsSafe(permissionIds)` — valide que les rôles non-admin ne reçoivent pas de permissions admin.

**User** (`app/Core/Models/User.php`) : `hasCompanyPermission(Company, permissionKey)` — Owner = bypass complet. Sinon vérifie via `membership.companyRole.hasPermission()`.

**CompanyAccess** (`app/Company/Security/CompanyAccess.php`) : Single source of truth pour toutes les vérifications company-scoped. 4 abilities: `access-surface` (structure = admin), `use-module` (activation check), `use-permission` (RBAC), `manage-structure` (admin role required). Owner bypass tout SAUF `use-module`.

**EnsureCompanyAccess** (middleware) : Pattern `company.access:{ability},{key?}`. Exemples: `company.access:use-permission,shipments.view`, `company.access:use-module,logistics_shipments`.

### Backend — Permissions par module (exhaustif)

**Company Scope** :
| Module | Permissions | Admin-only |
|--------|-------------|------------|
| core.settings | settings.view, settings.manage | manage |
| core.members | members.view, members.invite, members.manage, members.credentials, members.sensitive_read | manage, credentials, sensitive |
| core.roles | roles.view, roles.manage | all |
| core.documents | documents.view, documents.manage, documents.configure | manage, configure |
| core.audit | audit.view | all |
| core.billing | billing.manage | all |
| core.jobdomain | jobdomain.view, jobdomain.manage | manage |
| core.theme | theme.view, theme.manage | non |
| core.support | support.view, support.create | non |
| core.modules | modules.manage | all |
| logistics_shipments | shipments.view, shipments.create, shipments.manage_status, shipments.assign, shipments.view_own | — |

**Platform Scope** :
| Module | Permissions |
|--------|-------------|
| platform.roles | manage_roles |
| platform.users | manage_platform_users, manage_platform_user_credentials |
| platform.plans | manage_plans |
| platform.security | security.view, security.manage, security.alerts.view, security.alerts.manage, security.audit.view |
| platform.ai | view_ai, manage_ai |
| platform.automations | manage_automations |
| platform.settings | manage_theme_settings, manage_session_settings, manage_maintenance |
| platform.documentation | manage_documentation, view_documentation |
| platform.audit | view_audit_logs |
| platform.markets | manage_markets |
| platform.realtime | realtime.view, realtime.manage, realtime.metrics.view, realtime.connections.view, realtime.governance |
| platform.support | manage_support, assign_support |
| platform.jobdomains | manage_jobdomains |
| platform.fields | manage_field_definitions |
| platform.documents | manage_document_catalog |

### Backend — Routes protégées (exemples)

| Route | Middleware | Permission |
|-------|-----------|-----------|
| PUT /theme-preference | use-module,core.theme + use-permission,theme.manage | theme.manage |
| PUT /company/plan | use-module,core.billing + use-permission,billing.manage + manage-structure | Admin + billing.manage |
| GET /billing/invoices | use-module,core.billing + use-permission,billing.manage | billing.manage |
| PUT /company | use-module,core.settings + use-permission,settings.manage | settings.manage |
| GET /company/roles | use-module,core.roles + use-permission,roles.view | roles.view |
| GET /shipments | use-module,logistics_shipments + use-permission,shipments.view | shipments.view |

### Frontend — Auth Stores

**Company Auth** (`resources/js/core/stores/auth.js`) :
- `permissions` getter: `company?.company_role?.permissions || []`
- `isOwner` getter: `company?.role === 'owner'`
- `isAdministrative` getter: `company?.is_administrative === true`
- `hasPermission(key)`: Owner = true, else check array

**Platform Auth** (`resources/js/core/stores/platformAuth.js`) :
- `permissions`: array de clés (stockées en cookies)
- `isSuperAdmin`: `roles.includes('super_admin')`
- `hasPermission(key)`: SuperAdmin = true, else check array

### Frontend — Composables existants

**useCompanyPermissionContext** (`resources/js/composables/useCompanyPermissionContext.js`) :
```javascript
const can = permission => auth.hasPermission(permission)
const moduleActive = key => moduleStore.isActive(key)
function checkAccess(meta) { /* module + permission check */ }
```

**usePlatformPermissionContext** (`resources/js/composables/usePlatformPermissionContext.js`) :
```javascript
const can = permission => platformAuth.hasPermission(permission)
function checkAccess(meta) { /* permission check */ }
```

### Frontend — Route Guards

**Platform** (guards.js lignes 56-86) : Module guard + Permission guard → redirect `/platform`
**Company** (guards.js lignes 88-142) : Surface guard (structure → admin required) + Module guard + Permission guard → redirect 403

### Frontend — Navigation Filtering

**useCompanyNav** (composables/useCompanyNav.js) : Filtre les items par `auth.hasPermission(item.permission)`. Fonctionne correctement.

### API /me — PROBLÈME CRITIQUE

**Company /me** : Retourne `user`, `ui_theme`, `ui_session`, `theme_preference` — **AUCUNE permission retournée directement**. Les permissions sont inférées via `company_role.permissions` dans la réponse `/my-companies`.

**Platform /me** : Retourne `user`, `roles`, `permissions` (array de clés), `platform_modules`, `disabled_modules` — **Complet**.

## 3. Problèmes identifiés

### P0 — CRITIQUE

**P0-1 : Aucun masquage des actions UI par permission**
Les boutons "Supprimer", "Modifier", "Inviter" sont visibles pour tous les utilisateurs, même ceux sans la permission correspondante. Le backend rejette l'action (403), mais l'UX est confuse — l'utilisateur pense qu'il peut agir, puis reçoit une erreur.

**P0-2 : Pas de directive `v-can`**
Aucune directive Vue pour masquer conditionnellement un élément selon une permission. Les développeurs doivent manuellement importer le store et écrire des `v-if`.

### P1 — URGENT

**P1-1 : Le composable useCompanyPermissionContext n'est utilisé nulle part dans les pages métier**
Il existe mais n'est pas importé dans les pages de l'application. Chaque page devrait utiliser `can('permission.key')` pour conditionner l'affichage des actions.

**P1-2 : Route meta incomplètes**
Certaines routes company n'ont pas de `meta.permission` défini, ce qui signifie que le route guard ne vérifie pas les permissions pour ces pages. Le backend protège quand même, mais l'UX permet la navigation vers des pages inaccessibles.

**P1-3 : Réponse 403 sans contexte**
Quand le backend rejette une action, le frontend affiche un toast générique "Permission denied" sans indiquer quelle permission manque ni comment l'obtenir.

### P2 — AMÉLIORATIONS

**P2-1 : Pas de composant PermissionGate**
Pas de composant wrapper `<PermissionGate permission="documents.manage">` qui masquerait son contenu si la permission est absente.

**P2-2 : Permissions non rafraîchies au changement de rôle**
Si un admin change le rôle d'un utilisateur connecté, les permissions dans le store ne sont pas mises à jour en temps réel. Il faut un refresh de page.

## 4. Risques

### Risques techniques
- **UX incohérente** : Actions visibles mais non autorisées → frustration utilisateur
- **Faux sentiment de sécurité** : Le frontend semble "ouvert" → les utilisateurs découvrent les limites de leur rôle par des erreurs
- **Maintenance** : Sans directive centralisée, chaque développeur réinvente le gating → incohérences

### Risques produit
- **Onboarding** : Un nouvel utilisateur avec un rôle limité voit toutes les actions → confusion
- **Confiance admin** : L'administrateur ne peut pas vérifier visuellement que le RBAC fonctionne
- **Vente** : Les prospects B2B demandent systématiquement le RBAC dans les démos

## 5. Gaps architecturels

| Gap | Gravité | Existant | Cible |
|-----|---------|----------|-------|
| Directive v-can | ÉLEVÉE | Aucune | `v-can="'documents.manage'"` |
| PermissionGate component | ÉLEVÉE | Aucun | `<PermissionGate>` wrapper |
| Actions UI gatées | CRITIQUE | 0% | 100% des actions CRUD |
| Route meta.permission | MOYENNE | Partiel | 100% des routes |
| Refresh permissions SSE | MOYENNE | Aucun | Via `rbac.changed` topic |
| 403 UX contextuelle | BASSE | Toast générique | Page 403 avec détails |

## 6. Contrats manquants

### Backend
- Endpoint `/me` company doit retourner les permissions directement (pas seulement via company_role)
- Endpoint de rafraîchissement des permissions (ou enrichir le topic SSE `rbac.changed` avec les nouvelles permissions)

### Frontend
- Directive `v-can` globale
- Composant `<PermissionGate permission="key" :fallback="component">`
- Composable `useCan()` simplifié : `const { can, isAdmin, isOwner } = useCan()`
- Hook `onPermissionsChanged` pour réagir aux changements SSE
- Route meta obligatoire : chaque route company DOIT avoir `permission` ou `surface`

## 7. UX Impact

- **Boutons masqués** : Les utilisateurs ne voient que ce qu'ils peuvent faire
- **Tooltips permission** : Un bouton désactivé affiche "Nécessite la permission X" au hover
- **403 contextuelle** : Page dédiée avec le nom de la permission manquante et le rôle de l'utilisateur
- **Admin preview** : Un admin peut "voir comme" un rôle pour vérifier le gating

## 8. Proposition V2 — Architecture cible

### Directive v-can

```javascript
// plugins/directives/can.js
export const vCan = {
  mounted(el, binding) {
    const auth = useAuthStore()
    if (!auth.hasPermission(binding.value)) {
      el.style.display = 'none'
    }
  },
  updated(el, binding) {
    const auth = useAuthStore()
    el.style.display = auth.hasPermission(binding.value) ? '' : 'none'
  }
}
```

### Composable useCan

```javascript
export function useCan() {
  const auth = useAuthStore()
  const moduleStore = useModuleStore()

  const can = (permission) => auth.hasPermission(permission)
  const canModule = (moduleKey) => moduleStore.isActive(moduleKey)
  const canAll = (...permissions) => permissions.every(can)
  const canAny = (...permissions) => permissions.some(can)

  return { can, canModule, canAll, canAny, isOwner: auth.isOwner, isAdmin: auth.isAdministrative }
}
```

### Enrichissement /me

```php
// AuthController::me()
return [
    'user' => $user,
    'permissions' => $membership->companyRole?->permissions->pluck('key') ?? [],
    'is_owner' => $membership->isOwner(),
    'is_administrative' => $membership->isAdmin(),
    // ...existing fields
];
```

### SSE Permission Refresh

Quand `rbac.changed` est reçu, le frontend appelle `/me` pour rafraîchir les permissions dans le store. L'UX affiche un toast "Vos permissions ont été mises à jour".

## 9. Règles non négociables

1. **Toute action CRUD visible DOIT être gatée** par `v-can` ou `useCan()` — aucune action visible pour un utilisateur non autorisé
2. **Le backend reste l'autorité** — le frontend masque, le backend rejette. Double validation obligatoire.
3. **Route meta.permission est OBLIGATOIRE** sur toute route company qui accède à des données protégées
4. **Le owner bypass est frontend ET backend** — même le frontend reconnaît le owner
5. **Les permissions sont stockées dans le auth store** et rafraîchies via SSE — jamais en cache local non synchronisé

## 10. Plan d'implémentation

| Phase | Scope | Effort | Dépendance |
|-------|-------|--------|------------|
| Phase 1 | Enrichir /me avec permissions | 0.5j | Aucune |
| Phase 2 | Créer directive v-can + composable useCan | 0.5j | Phase 1 |
| Phase 3 | Gater toutes les actions UI dans les pages company | 2j | Phase 2 |
| Phase 4 | Compléter route meta.permission | 0.5j | Phase 2 |
| Phase 5 | SSE permission refresh (rbac.changed → /me) | 0.5j | V2-AUDIT-REALTIME |
| Phase 6 | 403 UX contextuelle | 0.5j | Phase 2 |
| **Total** | | **4.5j** | |

## 11. Impacts sur autres modules

- **Tous les modules company** : Chaque page CRUD doit intégrer `v-can` sur ses actions (create, edit, delete, configure)
- **Navigation** : Déjà filtré par permissions — aucun changement
- **Documents** : Actions upload, review, configure doivent être gatées
- **Billing** : Actions manage, checkout doivent être gatées (admin-only)
- **Members** : Actions invite, edit, credentials doivent être gatées
- **Shipments** : Actions create, manage_status, assign doivent être gatées

## 12. Dépendances avec autres audits

- **V2-AUDIT-TENANCY** : Le RBAC s'appuie sur le même middleware stack que le tenancy. Les deux sont complémentaires (tenancy = isolation données, RBAC = contrôle actions)
- **V2-AUDIT-REALTIME** : Le topic `rbac.changed` existe mais n'est consommé par aucun store. L'audit realtime doit mapper ce topic vers un comportement frontend
- **V2-AUDIT-AI-ENGINE** : Les actions AI (retry, configure) doivent être gatées par permission
- **V2-AUDIT-AUTOMATION** : Les automations sont platform-only, donc gatées par platform.permission (pas company RBAC)

---

> **Verdict** : Le RBAC backend est **complet et solide**. Le gap est 100% frontend : les fondations existent (stores, composables, guards) mais ne sont pas systématiquement appliquées. L'effort est de 4.5 jours pour un RBAC frontend complet.
