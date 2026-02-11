# 03 — Architecture technique

> Décisions structurantes sur l'architecture du projet.
> Voir aussi : ADR-006 à ADR-016 dans `04-decisions.md`.

## Architecture générale

- **Plateforme SaaS multi-tenant** : chaque company est un tenant isolé
- **SPA** : Vue.js frontend avec Laravel API backend
- **Routing** : Catch-all SPA côté Laravel, auto-routes côté Vue (unplugin-vue-router)
- **API** : Laravel REST API (`routes/api.php`) — à créer
- **Auth** : JWT tokens stockés en cookies (Passport/Sanctum à venir)
- **Tenancy** : modèle d'isolation **à trancher** (ADR-008) — DB partagée vs DB par tenant

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

### Module (unité métier)
_Contrat à définir — structure indicative :_
- Ses propres routes API (Laravel)
- Ses propres migrations
- Ses propres pages Vue (auto-routées)
- Ses propres stores Pinia
- Enregistrement déclaratif dans le core
- Aucune dépendance vers un autre module (sauf via le core)

### Jobdomain (profil déclaratif)
_Format à définir — structure indicative :_
```
jobdomain "logistique" = {
  modules_default: [fleet, dispatch, tracking, billing, contacts],
  navigation: [...],
  dashboard: "logistics",
  labels: { booking: "expédition", client: "client", ... },
  public_themes: ["logistics-pro", "logistics-express"]   // 2-3 max
}
```
Pas de code, pas de logique, pas de migration. Configuration pure.
Le champ `public_themes` fournit un catalogue restreint de thèmes pour le module "Présence en ligne" (voir ADR-013).

## Frontend

### Structure
```
resources/js/
├── @core/           # Vuexy core (NE PAS MODIFIER)
├── @layouts/        # Vuexy layouts (NE PAS MODIFIER)
├── pages/           # Routes auto-générées
├── views/           # View components (assemblages)
├── components/      # Composants partagés
├── composables/     # Composables app
├── plugins/         # Plugins Vue
├── navigation/      # Config menus
├── utils/           # Utilitaires
└── stores/          # Pinia stores (à créer)
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
├── Http/
│   ├── Controllers/   # Controllers API
│   ├── Middleware/     # Middleware custom
│   └── Requests/      # Form Requests (validation)
├── Models/            # Eloquent models
├── Services/          # Business logic
└── Policies/          # Authorization policies
```

### API Design
- RESTful, JSON
- Pagination serveur pour les listes
- Validation via Form Requests
- Auth via Sanctum ou JWT (à décider)

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

---

> **Rappel** : Cette architecture est un cadre initial. Chaque décision significative doit être tracée dans `04-decisions.md`.
