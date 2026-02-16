# 00 — Vision produit Leezr

> Document de reference produit. Aligne sur le code, les ADR, et les intentions strategiques du fondateur.
> Derniere mise a jour : 2026-02-16 (audit strategique complet).

---

## 1. Vision produit

Leezr est une **plateforme SaaS modulaire multi-tenant multi-vertical**, en construction.

Chaque entreprise (company) dispose d'un **espace de gestion adapte a son activite**, assemble automatiquement lors de la souscription via un systeme de **jobdomain** (variante produit) et de **modules** (logique metier autonome).

Via certains modules, Leezr permet aux entreprises de creer une **presence en ligne operationnelle** (pages publiques ou mini-site), accessible sous un sous-domaine dedie (`company.leezr.com`) ou via un domaine personnalise, gere par la plateforme.

### Ce que Leezr construit

Un socle SaaS ou :
- Le **core** est invariant et ne connait aucun metier
- Les **modules** portent toute la logique metier, activables par company
- Le **jobdomain** assemble la variante produit adaptee au metier de la company
- La **company** est l'unite d'isolation des donnees (tenant)
- L'**organisation interne** de la company est definie par le jobdomain (roles, permissions, visibilite)
- La **presence en ligne** est un module comme les autres, consommant l'infrastructure de domaines du core

### Strategie de validation

Premier vertical concret : **Logistique**. Ce vertical valide la separation core/modules, l'activation par jobdomain, la bulle UX et le modele organisationnel avant d'ajouter d'autres metiers. Un deuxieme vertical (non choisi) servira de test de scalabilite architecturale.

---

## 2. Modele conceptuel

### Concepts et roles

| Concept | Role | Porte de la logique ? |
|---|---|---|
| **Core** | Socle invariant : auth, tenancy, gouvernance, modules registry, jobdomain registry, runtime SPA, champs dynamiques, infrastructure domaines | Non — aucune logique metier verticale |
| **Module** | Unite autonome de logique metier. Activable par company. Declare ses capabilities (navigation, routes, guards). | Oui — toute logique metier vit dans un module |
| **Jobdomain** | Variante produit. Assemble modules, champs, configuration, organisation interne, et UX pour un metier donne. | Non — il configure, il ne calcule pas |
| **Company** | Tenant. Unite d'isolation des donnees. Possede un jobdomain, des modules actifs, et une organisation interne. | Non — la company est un conteneur |
| **Platform** | Administration SaaS. Gouvernance globale, RBAC platform, gestion des companies, modules, jobdomains, champs. | Non — le platform administre, il ne fait pas de metier |

### Types de modules

| Type | Exemples | Peut etre desactive ? | Porte du metier ? |
|---|---|---|---|
| **Core module** | `core.members`, `core.settings` | Oui (mais deconseille) | Non — gouvernance company |
| **Business module** | `logistics_shipments`, futurs `fleet`, `dispatch`, `billing` | Oui | Oui — logique verticale |
| **Presence en ligne** (futur) | pages publiques, mini-sites | Oui | Oui — gestion de contenu |

Un module peut etre compatible avec **un ou plusieurs jobdomains**. La compatibilite est declaree dans le jobdomain (via `default_modules`), pas dans le module.

### Les 5 roles du jobdomain

Le jobdomain remplit cinq roles distincts :

| # | Role | Mecanisme | Exemple |
|---|---|---|---|
| 1 | **Selecteur d'experience** | `nav_profile`, `landing_route` | Navigation et landing page differentes par metier |
| 2 | **Assembleur produit** | `default_modules`, `default_fields` | A l'assignation, active automatiquement les modules et champs du profil |
| 3 | **Gate de capabilities** | `allow_custom_fields` (et futurs flags) | Autorise ou non la creation de champs custom par la company |
| 4 | **Definisseur du modele organisationnel** | Roles internes types (cible) | Un transporteur a des livreurs, un salon a des coiffeurs |
| 5 | **Porteur de configuration initiale** | Presets declaratifs appliques a l'assignation | La company demarre avec une configuration coherente pour son metier |

**Invariant** : le jobdomain ne porte aucune logique. Il selectionne, assemble, gate et configure. Aucun `if (jobdomain === 'xxx')` hors `JobdomainGate`/`JobdomainRegistry`.

**Separation presets / activations** : les presets sont des templates appliques uniquement a l'assignation. Modifier un preset apres assignation n'a aucun effet retroactif sur les companies deja assignees. Chaque company prend le controle total de ses modules et champs apres assignation.

---

## 3. Architecture fonctionnelle

### Deux scopes hermetiques

```
┌─────────────────────────────────────────────────┐
│                  PLATFORM                        │
│  Administration SaaS (control plane)             │
│  - PlatformUser (table separee)                  │
│  - RBAC : PlatformRole + PlatformPermission      │
│  - Guard : auth:platform                         │
│  - Gestion : companies, modules, jobdomains,     │
│    champs, roles platform                        │
├─────────────────────────────────────────────────┤
│                   CORE                           │
│  Socle partage (invariant, non metier)           │
│  - Models : User, Company, Membership            │
│  - Auth : Sanctum SPA cookie-based               │
│  - Tenancy : company_id + middleware             │
│  - Modules : registry + gate + capabilities      │
│  - Jobdomains : registry + gate + catalog        │
│  - Fields : EAV multi-scope                      │
│  - Runtime SPA : orchestrateur transactionnel    │
│  - Infrastructure domaines (futur)               │
├─────────────────────────────────────────────────┤
│                  COMPANY                         │
│  Espace client (tenant)                          │
│  - User (table partagee, lies via memberships)   │
│  - Roles : owner / admin / user (actuel)         │
│  - Roles organisationnels par jobdomain (cible)  │
│  - Guard : auth:sanctum + company.context        │
│  - Modules actifs : business + core              │
│  - Champs dynamiques : EAV                       │
└─────────────────────────────────────────────────┘
```

**Regles d'hermeticite** (ADR-020, ADR-031) :
- Tables separees : `users` (company) vs `platform_users` (platform)
- Guards separes : `auth:sanctum` vs `auth:platform`
- Sessions independantes (Laravel multi-guard natif)
- Aucun controller Platform dans Company, aucun controller Company dans Platform
- Un PlatformUser n'a jamais de company membership
- Un User n'a jamais de platform_roles

### Couches applicatives

```
┌─────────────────────────────────────────────────┐
│  Bulle UX (pages/presets par jobdomain)          │  ← Stock Vuexy, assemblage different par jobdomain
├─────────────────────────────────────────────────┤
│  Public Serving (futur — ADR-012 a ADR-016)     │  ← Pages publiques companies, hors SPA
├─────────────────────────────────────────────────┤
│  Modules metier (logistics_shipments, futurs)   │  ← Logique metier autonome
├─────────────────────────────────────────────────┤
│  Core (auth, tenancy, governance, runtime)      │  ← Invariant, ne connait aucun metier
├─────────────────────────────────────────────────┤
│  Infrastructure (Laravel 12, Vue 3.5, Vuexy)    │  ← Framework + infra
└─────────────────────────────────────────────────┘
```

### Systeme de modules (ADR-021, ADR-022)

**Platform-defined, company-activated.** La plateforme definit le catalogue. La company active ce qui est disponible.

Regle d'activation :
1. `platform_modules.is_enabled_globally = true`
2. `company_modules` row existe pour cette company + module_key
3. `company_modules.is_enabled_for_company = true`

Si l'une des 3 conditions est fausse, le module est inactif.

Chaque module declare ses **capabilities** (ADR-022) :
- `nav_items` : entrees de navigation injectees si le module est actif
- `route_names` : routes filtrees cote router frontend
- `middleware_key` : cle pour le middleware `module.active:{key}` backend

### Tenancy (ADR-008)

- DB partagee avec colonne `company_id` sur toute table scopee tenant
- Isolation par query scoping + middleware `SetCompanyContext`
- Header `X-Company-Id` sur chaque requete API company
- La table `users` n'a PAS de `company_id` — le user est global, lie aux companies via `memberships`

### Runtime SPA (ADR-047a-d, ADR-048)

Orchestrateur transactionnel de boot :
- Phase machine validee (`cold → auth → tenant → features → ready | error`)
- Single-writer : un seul run actif a la fois
- Per-job abort : chaque ressource a son AbortController
- Guard non-bloquant : attend auth seulement, tenant/features en arriere-plan
- Retry partiel : relance uniquement les jobs en erreur
- 14 invariants formels (DEV-only)
- Stress harness : 5 scenarios reproductibles

---

## 4. Organisation interne d'une company

### Etat actuel

L'autorisation company repose sur **3 roles fixes** dans `memberships.role` :

| Role | Droits | Stockage |
|---|---|---|
| `owner` | Tous les droits. Ne peut pas etre retire ni retrograde. 1 par company. | `memberships.role` |
| `admin` | Gestion membres, modules, configuration, mutations metier. | `memberships.role` |
| `user` | Lecture seule. Aucun droit de gestion. | `memberships.role` |

Le middleware `EnsureRole` enforce une hierarchie numerique : `user(0) < admin(1) < owner(2)`.

**Limitation actuelle** : un admin peut tout faire dans tous les modules. Pas de permissions granulaires. Pas de roles organisationnels. Un admin logistique qui ne devrait gerer que les expeditions peut aussi modifier les membres et la configuration.

### Modele cible

Le modele cible introduit deux couches supplementaires :

#### Couche 1 — Roles organisationnels par jobdomain

Chaque jobdomain definit des **roles internes types** correspondant a la structure du metier.

Exemple pour le jobdomain `logistique` :

| Role organisationnel | Description |
|---|---|
| RH | Gestion du personnel |
| Manager | Supervision operationnelle |
| Dispatcher | Planification des tournees |
| Livreur | Execution terrain, mise a jour des statuts |

Ces roles organisationnels sont **orthogonaux** aux roles techniques (`owner`/`admin`/`user`). Un Livreur peut etre `admin` techniquement mais n'avoir acces qu'aux fonctionnalites de livraison.

#### Couche 2 — Permissions par module et par capacite metier

| Dimension | Description |
|---|---|
| Permission par module | Acces a un module specifique (ex: peut acceder aux expeditions) |
| Permission par capacite | Action dans un module (ex: peut changer le statut d'une expedition) |
| Visibilite par role | Ce que le role organisationnel voit dans l'interface |

**Etat** : non implemente. La couche permissions company est explicitement reportee (ADR-022). Le modele organisationnel est une intention architecturale, pas une decision implementee.

---

## 5. Cycle de souscription d'une company

### Flux actuel (simplifie)

```
1. Register (user + company)
   └── Cree un User + une Company + un Membership (role: owner)

2. Assignation jobdomain (admin+)
   └── JobdomainGate::assignToCompany() — transaction :
       ├── Assigne le jobdomain a la company (pivot company_jobdomain)
       ├── Active les default_modules du profil
       └── Active les default_fields du profil (FieldActivation)

3. Boot SPA (runtime)
   └── Hydrate : auth → tenant (companies, jobdomain) → features (modules)
       └── AppShellGate rend la page quand ready

4. Utilisation
   └── L'utilisateur voit la bulle UX assemblee par le jobdomain
       ├── Navigation filtree par nav_profile + modules actifs
       ├── Modules metier accessibles selon activation
       └── Champs dynamiques resolus par scope
```

### Flux cible (avec organisation interne)

```
1. Register (user + company)

2. Assignation jobdomain
   └── Active modules + champs + structure organisationnelle du metier

3. Configuration interne (admin/owner)
   └── Assigne les roles organisationnels aux membres
   └── Configure les permissions par role

4. Utilisation
   └── Chaque membre voit une interface adaptee a son role organisationnel
```

**Etat** : le flux cible n'est pas implemente. L'assignation jobdomain active modules et champs. La structure organisationnelle et les permissions par role sont des intentions.

---

## 6. Etat actuel vs cible

### Ce qui existe (code + ADR implementees)

| Composant | Etat | ADR |
|---|---|---|
| Core auth (Sanctum SPA cookie) | Implemente | ADR-019 |
| Dual identity (users / platform_users) | Implemente | ADR-031 |
| Tenancy (company_id + middleware) | Implemente | ADR-008 |
| Gouvernance company (owner/admin/user) | Implemente | ADR-020 |
| Module system (platform-defined, company-activated) | Implemente | ADR-021, ADR-022 |
| Jobdomain system (registry + gate + catalog) | Implemente | ADR-024, ADR-025 |
| Jobdomain presets (default_modules + default_fields) | Implemente | ADR-040, ADR-041 |
| Custom fields gated by jobdomain | Implemente | ADR-042 |
| Dynamic field system (EAV 3 couches) | Implemente | ADR-039 |
| Platform RBAC (roles + permissions) | Implemente | ADR-035 |
| Platform backoffice (companies, users, roles, modules, jobdomains, fields) | Implemente | ADR-030, ADR-034 |
| Module metier : logistics_shipments | Implemente | ADR-026 |
| Password lifecycle (invitation-first, reset, policy) | Implemente | ADR-037, ADR-038 |
| Runtime SPA v2 (state machine, scheduler, jobs, invariants) | Implemente | ADR-047a-d, ADR-048 |
| Session hardening + CSRF + redirect policy | Implemente | ADR-037 |
| Version discipline (build version, chunk resilience, handshake) | Implemente | ADR-045a-e |

### Ce qui est decide mais non implemente

| Composant | Etat | ADR |
|---|---|---|
| Public Serving (couche distincte de la SPA) | Documente, non tranche (rendering) | ADR-012 a ADR-016 |
| Enterprise runtime hardening (F1-F4) | Backlog | ADR-046 |

### Ce qui est explicitement reporte (ADR-011)

| Sujet | Raison du report |
|---|---|
| Marketplace de modules | Necessite d'abord un module qui fonctionne |
| API publique / webhooks | Pas de consommateurs externes |
| Billing / abonnements | Depend du produit en fonctionnement |
| Onboarding self-service des companies | Valider le parcours manuellement d'abord |
| Migration de donnees entre jobdomains | Edge case |
| Multi-langue i18n utilisateur | Complexite orthogonale |

### Ce qui est visionne mais non decide (pas d'ADR)

| Sujet | Description | Impact |
|---|---|---|
| Roles organisationnels par jobdomain | Roles internes types (RH, Manager, Livreur) definis par le jobdomain | Modifie le modele d'autorisation company |
| Permissions par module | Acces granulaire par module et par capacite metier | Remplace le modele admin/user actuel |
| Visibilite par role organisationnel | L'interface s'adapte au role interne, pas seulement au role technique | Impacte la navigation et le filtrage UI |
| Second vertical | Necessaire pour valider l'architecture multi-vertical | Test existentiel du modele |
| Billing / SubscriptionContract | Aucun modele de monetisation n'existe dans le code | Strategique mais non urgent |

---

## 7. Principes structurants

### Principes actifs (enforces dans le code)

| # | Principe | Enforcement |
|---|---|---|
| P1 | Le core ne connait aucun metier | Aucune logique verticale dans `app/Core/` |
| P2 | Toute logique metier vit dans un module | Modules declares dans `ModuleRegistry`, routes protegees par `module.active` |
| P3 | Le jobdomain configure, il ne calcule pas | Aucun `if (jobdomain === 'xxx')` hors `JobdomainGate`/`JobdomainRegistry` |
| P4 | Platform et Company sont hermetiques | Tables, guards, sessions, controllers separes (ADR-020, ADR-031) |
| P5 | L'UI est un stock fini | Tout vient de `resources/ui/presets/` (Vuexy). Interdit d'inventer (ADR-002) |
| P6 | Isolation UX par pages distinctes | Pas de logique conditionnelle par jobdomain dans les composants (ADR-010) |
| P7 | DB partagee avec isolation logique | `company_id` + middleware `SetCompanyContext`. Jamais de DB separee (ADR-008) |
| P8 | 1 company = 1 jobdomain | Pivot `company_jobdomain` avec `company_id UNIQUE` (ADR-025) |
| P9 | Les presets ne sont pas retroactifs | Modifier un preset apres assignation n'affecte pas les companies existantes (ADR-041) |
| P10 | Les registries sont la seed, la DB est l'autorite runtime | `ModuleRegistry`, `JobdomainRegistry`, `FieldDefinitionCatalog` seed via `sync()`. La DB prime (ADR-041) |

### Principes directeurs (guides les decisions futures)

| # | Principe | Source |
|---|---|---|
| D1 | Extraire les patterns du concret, pas de l'abstrait | 00-context vision |
| D2 | Un module ne depend d'aucun autre module (sauf via le core) | ADR-026, contrat module |
| D3 | Le backend est l'autorite finale (le frontend filtre pour l'UX, le backend enforce) | ADR-023, ADR-027 |
| D4 | Toute decision est tracee dans un ADR | ADR-004 (BMAD) |
| D5 | Pas de genericite prematuree | ADR-011 (reports explicites) |

---

## 8. Frontieres strictes (ce que Leezr n'est pas)

| Leezr n'est PAS | Explication |
|---|---|
| Un ERP configurable | Leezr ne propose pas un outil generique parametrable. Chaque metier a ses propres modules, pas une vue generique filtree. |
| Une marketplace de modules | Les modules sont definis par la plateforme, pas par des tiers. ADR-011 reporte explicitement ce sujet. |
| Un CMS | La presence en ligne est un module parmi d'autres, pas le coeur du produit. |
| Un no-code / low-code | La company configure son espace dans les limites prevues par le jobdomain et les modules. Elle ne cree pas d'applications. |
| Un framework | Leezr est un produit. Le code n'est pas concu pour etre forke ou etendu par des tiers. |
| Un produit fini | Leezr est en construction. Le core est fonctionnel. Le premier vertical (logistique) a un module operationnel. Le modele organisationnel, le billing, la presence en ligne, et le second vertical n'existent pas encore. |

---

## Stack technique

| Couche | Technologie |
|---|---|
| Backend | Laravel 12 (PHP 8.3) |
| Frontend | Vue 3.5 + Vuetify 3.10 |
| Build | Vite 7 |
| Template UI | Vuexy v9.5.0 |
| State | Pinia 3 |
| Auth | Laravel Sanctum (SPA cookie-based, ADR-019) |
| Icons | Tabler via Iconify |
| Fonts | Public Sans (Google Fonts) |
| Package manager | pnpm |
| DB | MySQL |
| Dev local | Laravel Valet HTTPS (`leezr.test`) |
| Deploiement | VPS OVH, webhook GitHub (ADR-018) |

---

## Registres actifs

### Modules (ModuleRegistry — 3 enregistres)

| Key | Type | Description |
|---|---|---|
| `core.members` | Core | Gestion des membres et roles de la company |
| `core.settings` | Core | Nom et configuration de la company |
| `logistics_shipments` | Business | CRUD d'expeditions avec workflow de statuts |

### Jobdomains (JobdomainRegistry — 1 enregistre)

| Key | Description | Default modules |
|---|---|---|
| `logistique` | Transport, fleet management, dispatch | `core.members`, `core.settings`, `logistics_shipments` |

### Permissions platform (PermissionCatalog — 8 enregistrees)

| Key | Description |
|---|---|
| `manage_companies` | Gestion et suspension des companies |
| `view_company_users` | Supervision lecture seule des utilisateurs company |
| `manage_platform_users` | CRUD des employes platform |
| `manage_platform_user_credentials` | Reset / set password des employes platform |
| `manage_roles` | CRUD des roles platform |
| `manage_modules` | Toggle global des modules |
| `manage_field_definitions` | CRUD des champs dynamiques |
| `manage_jobdomains` | CRUD des jobdomains et presets |

### Champs systeme (FieldDefinitionCatalog — 6 enregistres)

| Code | Scope | Type |
|---|---|---|
| `siret` | company | string |
| `vat_number` | company | string |
| `legal_form` | company | string |
| `phone` | company_user | string |
| `job_title` | company_user | string |
| `internal_note` | platform_user | string |

---

## References

- `docs/bmad/01-business.md` — Besoins metier
- `docs/bmad/02-domain.md` — Modele du domaine
- `docs/bmad/03-architecture.md` — Architecture technique
- `docs/bmad/04-decisions.md` — ADR (48 decisions)
- `docs/bmad/05-audits.md` — Audits de l'existant
- `docs/bmad/06-ui-policy.md` — Politique UI (non negotiable)
- `docs/bmad/07-dev-rules.md` — Regles de developpement
