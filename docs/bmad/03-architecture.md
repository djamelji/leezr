# 03 — Architecture technique

> Décisions structurantes sur l'architecture du projet.
> Voir aussi : ADR-006 à ADR-020 dans `04-decisions.md`.

## Architecture générale

- **Plateforme SaaS multi-tenant** : chaque company est un tenant isolé
- **SPA** : Vue.js frontend avec Laravel API backend
- **Routing** : Catch-all SPA côté Laravel, auto-routes côté Vue (unplugin-vue-router)
- **API** : Laravel REST API — 3 fichiers de routes : `api.php` (core/auth), `company.php` (scope Company), `platform.php` (scope Platform)
- **Auth** : Laravel Sanctum SPA cookie-based (ADR-019) — pas de JWT, pas de Passport
- **Tenancy** : DB partagée avec `company_id` (ADR-008) — isolation par query scoping
- **Scopes** : Platform (SaaS global) et Company (tenant) physiquement séparés (ADR-020)

## Architecture plateforme

### Couches

```
┌─────────────────────────────────────────────┐
│  Bulle UX (pages/presets par jobdomain)     │  ← Stock Vuexy, assemblage différent par jobdomain
├─────────────────────────────────────────────┤
│  Public Serving (pages publiques companies) │  ← Pages visiteurs, hors SPA, cacheable
├─────────────────────────────────────────────┤
│  Modules métier (fleet, dispatch, billing…) │  ← Logique métier autonome
├─────────────────────────────────────────────┤
│  Core (auth, company, user, module registry)│  ← Invariant, ne connaît aucun métier
├─────────────────────────────────────────────┤
│  Infrastructure (Laravel, Vue, Vuexy, DNS)  │  ← Framework + infra domaines
└─────────────────────────────────────────────┘
```

### Core (invariant)
- Auth / Users / Rôles
- Companies (tenants)
- Module registry (quels modules existent, lesquels sont actifs par company)
- Jobdomain registry (profils déclaratifs)
- Configuration système

### Scopes applicatifs (ADR-020)

La plateforme est structurée en **deux scopes physiquement séparés** partageant un Core commun :

```
APP
├── Core       ← Models, Auth (partagé)
│   ├── Models/     (User, Company, Membership)
│   ├── Auth/       (AuthController, Form Requests)
│   └── Tenancy/    (scoping, helpers d'isolation)
│
├── Platform   ← SaaS Leezr (global) — Control Plane (LOT 6/6B)
│   ├── Auth/              (PlatformAuthController — login/me/logout via auth:platform)
│   ├── Http/Controllers/  (PlatformModuleController, PlatformCompanyController, PlatformUserController, PlatformCompanyUserController, PlatformRoleController)
│   ├── Http/Middleware/   (EnsurePlatformPermission — uses auth:platform guard)
│   ├── Models/            (PlatformUser, PlatformRole, PlatformPermission)
│   ├── RBAC/              (PermissionCatalog — source unique de vérité permissions)
│   └── Routes/platform.php
│
└── Company    ← Tenant (multi-company)
    ├── Http/Controllers/  (CompanyController, MembershipController, UserProfileController)
    ├── Http/Middleware/    (SetCompanyContext, EnsureRole)
    ├── Http/Requests/
    └── Routes/company.php
```

**Règles strictes** :
- Aucun controller Platform dans Company, aucun controller Company dans Platform
- Aucun `if (is_platform_admin)` dans les controllers Company
- Le Core fournit les models et l'auth, les deux scopes le consomment

### Authentification — Sanctum SPA (ADR-019)

- `statefulApi()` activé dans `bootstrap/app.php`
- Cookie-based SPA auth (pas de JWT, pas de token dans localStorage)
- Le frontend appelle `/sanctum/csrf-cookie` avant login/register
- Guard : `auth:sanctum` sur les routes company, `auth:platform` sur les routes platform (ADR-032)

#### Configuration requise (backend)

| Fichier | Clé | Valeur | Raison |
|---|---|---|---|
| `config/cors.php` | `supports_credentials` | `true` | Obligatoire pour que le navigateur envoie les cookies avec les requêtes API |
| `config/cors.php` | `paths` | `['api/*', 'sanctum/csrf-cookie']` | Expose les headers CORS sur les routes API et CSRF |
| `.env` | `SANCTUM_STATEFUL_DOMAINS` | `leezr.test` (local), domaine prod | Sanctum détecte les requêtes stateful via Origin/Referer |
| `.env` | `SESSION_DOMAIN` | `.leezr.test` (local) | Le point initial couvre les sous-domaines |

#### Flow CSRF (frontend)

1. `fetch('/sanctum/csrf-cookie', { credentials: 'include' })` → pose les cookies `XSRF-TOKEN` + `laravel-session`
2. Lecture du cookie `XSRF-TOKEN` via `document.cookie`, URL-décodé via `decodeURIComponent()`
3. Envoi comme header `X-XSRF-TOKEN` sur les requêtes POST/PUT/DELETE via `$api` (ofetch)
4. `credentials: 'include'` garantit l'envoi des cookies de session avec chaque requête

#### Contrainte : pas de service worker

Les service workers (ex: MSW) interfèrent avec le flow `Set-Cookie` de Sanctum. Le fichier `mockServiceWorker.js` a été désactivé et un nettoyage automatique est en place dans `main.js`. **Aucun service worker ne doit intercepter les requêtes API.**

### Contexte company (résolution)

- Header `X-Company-Id` sur chaque requête API Company
- Middleware `SetCompanyContext` : vérifie header, vérifie membership, injecte company dans le request
- Si header absent → 400, si non membre → 403

### Module System (LOT 2 — ADR-021, ADR-022, ADR-023)

Système universaliste de modules : **platform-defined, company-activated**.

#### Principes

- La **plateforme** définit le catalogue de modules (`platform_modules`)
- La **company** active/désactive les modules qui lui sont disponibles (`company_modules`)
- Chaque module déclare ses **capabilities** (nav, routes, guards) dans le `ModuleRegistry`
- Le `ModuleGate` centralise la logique d'activation
- Le middleware `EnsureModuleActive` protège les routes par module

#### Architecture backend

```
app/Core/Modules/
├── PlatformModule.php      # Model — catalogue global
├── CompanyModule.php        # Model — activation par company
├── ModuleRegistry.php       # Registre déclaratif (définitions + capabilities)
├── ModuleGate.php           # Service — isActive(company, module_key)
├── Capabilities.php         # Structure des capabilities d'un module
└── ModuleCatalogReadModel.php  # Liste modules + active flags pour une company
```

#### Capabilities (déclaratives)

Chaque module expose :
```php
[
    'nav_items' => [
        ['key' => 'fleet', 'title' => 'Fleet', 'to' => ['name' => 'company-fleet'], 'icon' => 'tabler-truck'],
    ],
    'route_names' => ['company-fleet', 'company-fleet-id'],
    'middleware_key' => 'logistics.fleet',
]
```

- `nav_items` : injectés dans la navigation si le module est actif
- `route_names` : noms de routes filtrés côté router frontend
- `middleware_key` : utilisé par `EnsureModuleActive` pour protéger les routes backend

#### Endpoints

| Scope | Méthode | Route | Description | Rôle requis |
|---|---|---|---|---|
| Platform | GET | `/api/platform/modules` | Liste du catalogue global | platform_admin |
| Platform | PUT | `/api/platform/modules/{key}/toggle` | Activer/désactiver globalement | platform_admin |
| Company | GET | `/api/modules` | Modules avec statut actif + capabilities | tout membre |
| Company | PUT | `/api/modules/{key}/enable` | Activer pour la company | admin+ |
| Company | PUT | `/api/modules/{key}/disable` | Désactiver pour la company | admin+ |

#### Règle d'activation

Module actif = `is_enabled_globally AND row exists AND is_enabled_for_company`.

### Module métier (contrat)

Structure d'un module métier :
- Ses propres routes API (dans `company.php`, protégées par `module.active:{key}`)
- Ses propres migrations
- Ses propres pages Vue (auto-routées depuis `resources/js/pages/`)
- Ses propres stores Pinia
- Enregistrement déclaratif dans le `ModuleRegistry` avec capabilities
- Aucune dépendance vers un autre module (sauf via le core)

### Logistics Shipments (LOT 4 — ADR-026)

Premier module métier. CRUD d'expéditions avec workflow de statuts.

#### Architecture

- **Model** : `app/Core/Models/Shipment.php` (scopé par `company_id`)
- **Controller** : `app/Company/Http/Controllers/ShipmentController.php`
- **Form Requests** : `app/Company/Http/Requests/StoreShipmentRequest.php`, `ChangeShipmentStatusRequest.php`
- **Migration** : `create_shipments_table`
- **Module key** : `logistics_shipments`

#### Endpoints

| Méthode | Route | Description | Rôle requis | Middleware |
|---|---|---|---|---|
| GET | `/api/shipments` | Liste paginée | tout membre | `module.active:logistics_shipments` |
| POST | `/api/shipments` | Créer expédition | admin+ | `module.active:logistics_shipments` |
| GET | `/api/shipments/{id}` | Détail | tout membre | `module.active:logistics_shipments` |
| PUT | `/api/shipments/{id}/status` | Changer statut | admin+ | `module.active:logistics_shipments` |

#### Workflow de statuts

```
draft → planned → in_transit → delivered
  ↓        ↓          ↓
canceled  canceled   canceled
```

### Jobdomain System (LOT 3 — ADR-024, ADR-025)

Profil déclaratif qui personnalise l'UX par company. **Sélection, pas calcul.**

#### Principes

- Le `JobdomainRegistry` définit les profils statiquement (hardcodé, pas en DB)
- La table `jobdomains` stocke les métadonnées (key, label, description)
- La table `company_jobdomain` assigne 1 jobdomain à 1 company (1:1, nullable)
- Le `JobdomainGate` résout le profil pour une company : landing route, nav profile, default modules
- **Aucun `if (jobdomain === 'xxx')` hors JobdomainGate/Registry**

#### Architecture backend

```
app/Core/Jobdomains/
├── Jobdomain.php                  # Model — métadonnées
├── JobdomainRegistry.php          # Définitions statiques (profils déclaratifs)
├── JobdomainGate.php              # Service — resolveForCompany, landingRouteFor, navProfileFor, defaultModulesFor
└── JobdomainCatalogReadModel.php  # Read model — liste + current company jobdomain
```

#### Profil déclaratif (exemple : logistique)

```php
'logistique' => [
    'label' => 'Logistique',
    'description' => 'Transport, fleet management, dispatch',
    'landing_route' => '/',                      // fallback dashboard pour LOT 3
    'nav_profile' => 'logistique',               // clé pour filtrer la nav
    'default_modules' => ['core.members', 'core.settings'],
]
```

#### Endpoints

| Scope | Méthode | Route | Description | Rôle requis |
|---|---|---|---|---|
| Company | GET | `/api/company/jobdomain` | Jobdomain courant + profil résolu | tout membre |
| Company | PUT | `/api/company/jobdomain` | Assigner jobdomain + activer modules par défaut | admin+ |

#### Impact UX

- **Landing post-login** : le frontend utilise `landingRoute` du jobdomain (sinon fallback `/`)
- **Navigation** : filtrée par `navProfile` du jobdomain ET modules actifs (LOT 2)
- **Modules par défaut** : activés automatiquement à l'assignation du jobdomain

## Frontend

### Structure
```
resources/js/
├── @core/           # Vuexy core (NE PAS MODIFIER)
├── @layouts/        # Vuexy layouts (NE PAS MODIFIER)
├── core/
│   ├── stores/      # Pinia stores partagés (auth, company)
│   ├── composables/ # Composables partagés (useApi)
│   └── auth/        # Guards, helpers auth
├── company/
│   ├── views/       # View components scope Company
│   └── (pages via resources/js/pages/company/)
├── platform/
│   └── pages/       # Vide en LOT 1
├── pages/           # Routes auto-générées (login, register, index, company/*, account-settings/*)
├── views/           # View components (assemblages)
├── components/      # Composants partagés
├── composables/     # Composables app
├── plugins/         # Plugins Vue
├── navigation/      # Config menus
└── utils/           # Utilitaires
```

### Presets UI
```
resources/ui/presets/  # Composants Vuexy extraits et documentés
```

### Conventions
- App* wrappers pour les form elements
- VDataTableServer + TablePagination pour les listes CRUD
- Drawers (VNavigationDrawer) pour les formulaires de création
- Dialogs pour les confirmations et actions ponctuelles

## Backend

### Structure Laravel
```
app/
├── Core/
│   ├── Models/          # User, Company, Membership
│   ├── Auth/            # AuthController, Form Requests
│   └── Tenancy/         # Scoping, helpers d'isolation
├── Company/
│   ├── Http/
│   │   ├── Controllers/ # CompanyController, MembershipController, UserProfileController
│   │   ├── Middleware/   # SetCompanyContext, EnsureRole
│   │   └── Requests/    # Form Requests Company
│   └── Routes/
│       └── company.php
├── Platform/
│   ├── Http/
│   │   ├── Controllers/ # Vide en LOT 1
│   │   ├── Middleware/   # EnsurePlatformAdmin
│   │   └── Requests/    # Vide en LOT 1
│   └── Routes/
│       └── platform.php
├── Models/              # (legacy, à migrer vers Core/Models)
├── Services/            # Business logic
└── Policies/            # Authorization policies
```

### Routes et Middleware (Laravel 12)

Enregistrement dans `bootstrap/app.php` :

| Fichier | Prefix | Middleware | Scope |
|---|---|---|---|
| `routes/api.php` | `/api` | `api` | Auth (register, login, logout, me) |
| `routes/company.php` | `/api` | `api`, `auth:sanctum`, `company.context` | Scope Company |
| `routes/platform.php` | `/api/platform` | `api` (login public), `auth:platform` + `platform.permission:{key}` (backoffice) | Scope Platform (ADR-032, ADR-035) |

Middleware supplémentaire LOT 2 :
- `module.active:{key}` — vérifie qu'un module est actif pour la company courante (scope Company uniquement)

### API Design
- RESTful, JSON
- Pagination serveur pour les listes
- Validation via Form Requests
- Auth via Laravel Sanctum cookie-based SPA (ADR-019)

## Public Serving (pages publiques companies)

> Couche architecturale dédiée au serving des pages publiques des companies.
> Fondamentalement distincte de la SPA back-office. Voir ADR-012 à ADR-015.

### Pourquoi une couche distincte

Les pages publiques d'une company (`company.leezr.com` ou `mondomaine.fr`) sont servies à des **visiteurs anonymes**, sans authentification, potentiellement indexées par Google. Ce mode de serving a des contraintes que la SPA back-office n'a pas :
- SEO (contenu indexable)
- Performance (temps de chargement initial)
- Cache (CDN / HTTP cache)
- Scalabilité (milliers de companies simultanément)
- Isolation (seules les données publiées sont exposées)

### Responsabilités du Public Serving

1. **Résolution domaine → company** : middleware dédié (URL → `company_id`)
2. **Rendu des pages publiques** : potentiellement Laravel Blade, pas SPA (ADR-015 — à trancher)
3. **Consommation du thème** : le thème sélectionné par la company (parmi ceux proposés par son jobdomain) détermine le rendu
4. **Cache** : pages publiques cacheables (CDN / HTTP cache)
5. **Isolation données** : accès uniquement aux données explicitement publiées (draft → published)
6. **API publique dédiée** : endpoints distincts des endpoints back-office

### Thèmes et jobdomain (ADR-013)

- Chaque jobdomain propose **2 à 3 thèmes maximum**, cohérents avec l'activité
- Un **thème = un assemblage de templates/presets existants** (pas une UI sur mesure)
- La company **choisit un thème** parmi ceux de son jobdomain
- La **configuration finale** du site appartient à la company, dans les limites du thème choisi
- Le jobdomain fournit un **catalogue restreint de defaults**, il ne dicte pas la structure
- **Aucune logique conditionnelle** par jobdomain dans le rendering

### Séparation des responsabilités

| Responsabilité | Lieu | Description |
|---|---|---|
| Gestion du site (back-office) | Module "présence en ligne" | CRUD des pages, sections, configuration, preview — dans la SPA, pour l'admin |
| Serving public | Couche Public Serving | Rendu des pages, résolution domaine, cache — hors SPA, pour visiteurs anonymes |

Le module gère le contenu. La couche Public Serving le rend. Pas de mélange.

### Gestion des domaines (infrastructure core)

La gestion des domaines est une **responsabilité d'infrastructure du core**, pas du module "présence en ligne" :
- Chaque company a un sous-domaine (`company.leezr.com`), même sans le module "présence en ligne"
- Le sous-domaine est créé automatiquement à la création de la company
- La résolution domaine → tenant est un concern de routing, pas de métier
- Le module "présence en ligne" **consomme** le domaine pour y servir des pages, il ne le gère pas

**Décisions à trancher (ADR-015)** :
- Wildcard DNS `*.leezr.com` — oui/non ?
- Domaines personnalisés — vérification CNAME ? API Cloudflare ?
- SSL — Let's Encrypt automatisé ? Cloudflare proxy ?
- Table `domains` — dans le core
- Rate limiting / abuse protection sur les pages publiques

## Base de données

- MySQL `leezr`
- Migrations Laravel
- Seeders pour données de test

## Environnements et déploiement

> Voir ADR-017 (local), ADR-018 (déploiement distant).

### Environnements

| Environnement | URL | Branche | Rôle |
|---|---|---|---|
| Local | `https://leezr.test` | `dev` (working copy) | Développement, Valet HTTPS |
| Staging | `https://dev.leezr.com` | `dev` | Tests, validation avant production |
| Production | `https://leezr.com` | `main` | Production live |

### Infrastructure distante

- **Hébergeur** : VPS OVH (Debian)
- **Serveur web** : Apache
- **PHP** : 8.3 (cohérent avec le local)
- **BDD** : MySQL sur le même VPS
- **SSL** : Let's Encrypt (ou à préciser)
- **Serveur unique** : `dev.leezr.com` et `leezr.com` sur le même VPS (deux vhosts Apache)

### Déploiement

- **Méthode** : GitHub webhook → script serveur
- **Webhook** :
  - URL : `https://leezr.com/webhook.php`
  - Content-type : `application/json`
  - Secret : configuré côté GitHub et côté serveur
  - Événement : `push` uniquement
- **Flux** :
  - Push sur `dev` ou `main` → GitHub envoie un webhook au serveur
  - `webhook.php` vérifie la signature, identifie la branche, et exécute le déploiement
  - Pas de CI/CD externe (pas de GitHub Actions)

### Branches et flux Git

```
main ← production (leezr.com)
 └── dev ← développement actif (dev.leezr.com)
      └── feature/* ← branches de feature (merge vers dev)
```

- `dev` = branche de travail par défaut
- `main` = production, uniquement alimentée par merge depuis `dev`
- `feature/*` = branches de feature, mergées vers `dev`
- Pas de push direct sur `main`

---

> **Rappel** : Cette architecture est un cadre initial. Chaque décision significative doit être tracée dans `04-decisions.md`.
