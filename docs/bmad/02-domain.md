# 02 — Domain (Modèle du domaine)

> Ce fichier décrit les concepts métier, agrégats, entités, règles et relations.
> Il est le résultat de la phase Domain du cycle BMAD.

## Domaines identifiés

### LOT 1 — Core SaaS

Le premier lot couvre le socle universel de la plateforme. Il ne connaît aucun métier, aucun module, aucun jobdomain.

| Domaine | Description |
|---|---|
| **Identity** | Utilisateurs, authentification, profil |
| **Tenancy** | Companies (tenants), isolation des données |
| **Governance** | Membership, rôles par company, gouvernance owner/admin/user |

### LOT 2 — Module System

Le système de modules permet d'activer/désactiver des fonctionnalités par company. Platform-defined, company-activated (ADR-021).

| Domaine | Description |
|---|---|
| **ModuleCatalog** | Catalogue global de modules (propriété plateforme) |
| **ModuleActivation** | Activation par company (tenant scope) |
| **Capabilities** | Déclaration des capacités UI/routes de chaque module (ADR-022) |

### LOT 4 — Logistics Shipments (premier module métier)

Premier module métier validant l'architecture modulaire. CRUD d'expéditions avec workflow de statuts (ADR-026).

| Domaine | Description |
|---|---|
| **Shipment** | Expédition scopée par company, workflow draft→planned→in_transit→delivered |

### LOT 3 — Jobdomain System

Le jobdomain est un profil déclaratif qui personnalise l'UX d'une company (ADR-024, ADR-025).

| Domaine | Description |
|---|---|
| **JobdomainCatalog** | Catalogue de jobdomains (métadonnées en DB, logique dans Registry) |
| **JobdomainAssignment** | Assignation 1:1 company ↔ jobdomain (via pivot) |
| **JobdomainProfile** | Résolution déclarative : landing route, nav profile, default modules |

### LOT 6 — Platform Backoffice & RBAC

Le Control Plane SaaS : RBAC platform par table de rôles (distinct du RBAC company), gestion des companies (suspension), backoffice UI (ADR-029, ADR-030).

| Domaine | Description |
|---|---|
| **PlatformRBAC** | Rôles plateforme distincts des rôles company, many-to-many PlatformUser ↔ PlatformRole (ADR-031, ADR-033) |
| **CompanyLifecycle** | Statut de company (active/suspended), enforcement dans le middleware |
| **PlatformIdentity** | Identité platform séparée dans `platform_users`, guard `auth:platform` (ADR-031, ADR-032) |

## Agrégats

### User (Identity)

L'utilisateur est une entité **globale** — il existe indépendamment des companies. Il peut être membre de plusieurs companies avec des rôles différents.

```
User
├── id (PK)
├── name
├── email (unique)
├── email_verified_at (nullable)
├── password
├── avatar (nullable)
├── is_platform_admin (boolean, default false)
├── remember_token
├── created_at
└── updated_at
```

### Company (Tenancy)

La company est l'unité d'isolation des données (tenant). Toute donnée métier future sera scopée par `company_id`.

```
Company
├── id (PK)
├── name
├── slug (unique)
├── status (string: active, suspended — default active)
├── created_at
└── updated_at
```

### Membership (Governance)

La relation entre un user et une company, avec le rôle dans cette company.

```
Membership
├── id (PK)
├── user_id (FK → users)
├── company_id (FK → companies)
├── role (enum: owner, admin, user)
├── created_at
├── updated_at
└── UNIQUE(user_id, company_id)
```

## Entités

| Entité | Agrégat | Description |
|---|---|---|
| `User` | Identity | Utilisateur global de la plateforme |
| `Company` | Tenancy | Tenant — unité d'isolation |
| `Membership` | Governance | Relation user ↔ company avec rôle |
| `PlatformModule` | ModuleCatalog | Définition globale d'un module (plateforme) |
| `CompanyModule` | ModuleActivation | Activation d'un module pour une company |
| `Jobdomain` | JobdomainCatalog | Profil déclaratif (logistique, coiffure…) |
| `Shipment` | Shipment (LOT 4) | Expédition scopée par company, workflow de statuts |
| `PlatformUser` | PlatformIdentity (LOT 6B) | Employé platform (admin SaaS), table séparée de `users` |
| `PlatformRole` | PlatformRBAC (LOT 6) | Rôle plateforme (super_admin, future: support, ops) |

### PlatformModule (ModuleCatalog)

Définition globale d'un module. Propriété de la plateforme — les companies ne créent pas de modules.

```
PlatformModule
├── id (PK)
├── key (unique, ex: "core.members", "logistics.fleet")
├── name
├── description (nullable)
├── is_enabled_globally (boolean, default true)
├── sort_order (int, default 0)
├── created_at
└── updated_at
```

### CompanyModule (ModuleActivation)

Activation d'un module pour une company spécifique. Scopé par `company_id`.

```
CompanyModule
├── id (PK)
├── company_id (FK → companies)
├── module_key (string, référence logique → platform_modules.key)
├── is_enabled_for_company (boolean, default true)
├── config_json (nullable JSON, réservé futur)
├── created_at
├── updated_at
└── UNIQUE(company_id, module_key)
```

### Règle d'activation (source de vérité)

Un module est **actif** pour une company si et seulement si :
1. `platform_modules.is_enabled_globally = true`
2. `company_modules` row existe pour cette company + module_key
3. `company_modules.is_enabled_for_company = true`

Si l'une des 3 conditions est fausse → module inactif.

### Jobdomain (JobdomainCatalog)

Profil déclaratif. Métadonnées en DB, logique de résolution dans `JobdomainRegistry`.

```
Jobdomain
├── id (PK)
├── key (unique, ex: "logistique")
├── label
├── description (nullable)
├── is_active (boolean, default true)
├── created_at
└── updated_at
```

### CompanyJobdomain (JobdomainAssignment)

Assignation 1:1 d'un jobdomain à une company.

```
company_jobdomain (pivot)
├── company_id (FK → companies, UNIQUE)
├── jobdomain_id (FK → jobdomains)
└── timestamps
```

### Shipment (LOT 4 — Logistics Shipments)

Expédition scopée par company. Workflow de statuts linéaire avec annulation possible.

```
Shipment
├── id (PK)
├── company_id (FK → companies)
├── created_by_user_id (FK → users)
├── reference (unique per company, format SHP-YYYYMMDD-XXXX)
├── status (enum: draft, planned, in_transit, delivered, canceled)
├── origin_address (text, nullable)
├── destination_address (text, nullable)
├── scheduled_at (datetime, nullable)
├── notes (text, nullable)
├── created_at
└── updated_at
```

#### Workflow de statuts

```
draft → planned → in_transit → delivered
  ↓        ↓          ↓
canceled  canceled   canceled
```

- Transitions valides : draft→planned, planned→in_transit, in_transit→delivered
- Toute étape non terminale (draft, planned, in_transit) peut passer à `canceled`
- `delivered` et `canceled` sont des statuts terminaux (aucune transition sortante)

#### Référence auto-générée

Format : `SHP-YYYYMMDD-XXXX` (XXXX = compteur séquentiel par jour et par company).

### PlatformUser (PlatformIdentity — LOT 6B)

Employé platform (admin SaaS). Table séparée de `users` (ADR-031). Authentifié via guard `auth:platform`.

```
PlatformUser
├── id (PK)
├── name
├── email (unique)
├── password
├── remember_token
├── created_at
└── updated_at
```

### PlatformRole (PlatformRBAC — LOT 6)

Rôle plateforme. Distinct des rôles company (`memberships.role`). Many-to-many avec PlatformUser via `platform_role_user` (ADR-033).

```
PlatformRole
├── id (PK)
├── key (unique, ex: "super_admin")
├── name (ex: "Super Admin")
├── created_at
└── updated_at

platform_role_user (pivot)
├── id (PK)
├── platform_user_id (FK → platform_users)
├── platform_role_id (FK → platform_roles)
├── created_at
├── updated_at
└── UNIQUE(platform_user_id, platform_role_id)
```

Rôles prévus :
- `super_admin` — accès total au Control Plane
- `support` (futur) — accès lecture + actions support
- `ops` (futur) — accès monitoring + infrastructure

## Value Objects

| Value Object | Description | Valeurs |
|---|---|---|
| `CompanyRole` | Rôle d'un user dans une company | `owner`, `admin`, `user` |
| `ShipmentStatus` | Statut d'une expédition | `draft`, `planned`, `in_transit`, `delivered`, `canceled` |
| `CompanyStatus` | Statut d'une company | `active`, `suspended` |

## Relations

```
User ──hasMany──→ Membership
User ──belongsToMany──→ Company (via memberships)

Company ──hasMany──→ Membership
Company ──belongsToMany──→ User (via memberships)
Company ──hasMany──→ CompanyModule
Company ──belongsTo──→ Jobdomain (via company_jobdomain pivot, nullable)

Jobdomain ──hasMany──→ Company (via company_jobdomain pivot)

Membership ──belongsTo──→ User
Membership ──belongsTo──→ Company

CompanyModule ──belongsTo──→ Company
CompanyModule ──referencesKey──→ PlatformModule (via module_key)

PlatformModule (standalone, no FK — catalogue global)

Shipment ──belongsTo──→ Company (via company_id)
Shipment ──belongsTo──→ User (via created_by_user_id)
Company ──hasMany──→ Shipment

PlatformUser ──belongsToMany──→ PlatformRole (via platform_role_user)
PlatformRole ──belongsToMany──→ PlatformUser (via platform_role_user)
```

## Règles métier invariantes

### Tenancy (ADR-008)
- DB partagée avec colonne `company_id` sur toute table scopée
- Isolation par query scoping, jamais par DB séparée
- La table `users` n'a PAS de `company_id` — le user est global, lié aux companies via `memberships`
- Toute future table métier aura un `company_id` (FK → companies)

### Gouvernance company
- Chaque company a exactement **1 owner** (le créateur initial)
- L'owner ne peut pas être retiré ni rétrogradé
- `admin` peut gérer les membres (inviter, modifier rôle, retirer)
- `user` n'a aucun droit de gestion
- Un user peut avoir des rôles différents dans des companies différentes

### Rôles — séparation stricte (ADR-020)

| Rôle | Scope | Stockage | Accès |
|---|---|---|---|
| `owner` | Company | `memberships.role` | Scope Company uniquement |
| `admin` | Company | `memberships.role` | Scope Company uniquement |
| `user` | Company | `memberships.role` | Scope Company uniquement |
| `platform (super_admin, etc.)` | Platform | `platform_role_user` (PlatformUser ↔ PlatformRole) | Scope Platform uniquement |

- Les identités sont séparées : `users` (company) vs `platform_users` (platform) — ADR-031
- Un PlatformUser **n'a jamais** de company membership
- Un User **n'a jamais** de platform_roles
- Les rôles company ne polluent pas le scope Platform et vice versa
- **Aucun `if (is_platform_admin)` dans les controllers Company**

### Contexte company (résolution)
- Le frontend envoie `X-Company-Id` dans le header de chaque requête API Company
- Le middleware `SetCompanyContext` vérifie que le user est membre de la company demandée
- Si non membre → 403

---

> **Rappel BMAD** : Le modèle de domaine guide les choix d'architecture et d'UI. Il doit être stabilisé avant l'implémentation.
