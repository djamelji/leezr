# 07 — Règles de développement

> Règles de code et workflow applicables à tout développeur ou agent.

---

## Workflow BMAD obligatoire

Avant toute implémentation :
1. Vérifier que le besoin est documenté dans `01-business.md`
2. Vérifier que le domaine est modélisé dans `02-domain.md`
3. Vérifier que l'architecture couvre le cas dans `03-architecture.md`
4. Documenter toute décision nouvelle dans `04-decisions.md`
5. Vérifier l'UI dans `docs/ui/inventory.md` et `resources/ui/presets/`
6. Seulement alors : coder

## Conventions frontend

### Pages
- Auto-routées depuis `resources/js/pages/`
- Layout spécifié via `<route>` meta block
- Composées de presets et views, jamais de logique UI brute

### Composants
- Form elements : toujours les wrappers App* (AppTextField, AppSelect...)
- Listes CRUD : VDataTableServer + TablePagination
- Création : drawer (VNavigationDrawer + VForm)
- Confirmation : ConfirmDialog
- Props typées et explicites

### State management
- Pinia stores dans `resources/js/stores/`
- Un store par domaine métier
- Pas de state dans les composants pour les données métier

### API
- Appels via `useApi` composable ou `$api` (ofetch)
- Base URL : `VITE_API_BASE_URL` ou `/api`
- Auth : cookies de session Sanctum (pas de Bearer token)
- `credentials: 'include'` obligatoire sur toute instance fetch/ofetch
- Header `Accept: application/json` sur toutes les requêtes
- Header `X-XSRF-TOKEN` injecté automatiquement (lu depuis le cookie `XSRF-TOKEN`)
- Header `X-Company-Id` injecté automatiquement pour le contexte tenant
- Avant login/register : appeler `/sanctum/csrf-cookie` pour obtenir le token CSRF

## Conventions backend

### Controllers
- Un controller par ressource
- Actions : index, show, store, update, destroy
- Validation via Form Requests

### Models
- Relations explicites
- Casts typés
- Scopes pour les filtres courants

### API
- RESTful JSON
- Pagination via `?page=&itemsPerPage=`
- Filtres via query params
- Réponses cohérentes : `{ data, total }` pour les listes

## Dev tooling

### Commandes npm scripts

| Commande | Description |
|---|---|
| `pnpm dev` | Vite seul (HMR) |
| `pnpm dev:all` | Alias de `pnpm dev` (Vite seul, Valet gère PHP) |
| `pnpm dev:leezr` | Vite + Mailpit en parallèle (`concurrently -k`) |
| `pnpm build` | Build production Vite |
| `pnpm mailpit` | Ouvre l'UI Mailpit (`http://localhost:8025`) |

### Mailpit (test email local)

- **SMTP** : `127.0.0.1:1025`
- **UI** : `http://localhost:8025`
- Installé via `brew install mailpit` (pas dockerisé)
- Démarré automatiquement par `pnpm dev:leezr`, pas par `pnpm dev:all`
- `pnpm mailpit` ouvre uniquement l'UI dans le navigateur

## Git

- Commits conventionnels : `feat:`, `fix:`, `docs:`, `refactor:`
- Pas de push sur main sans review
- Branches feature : `feature/nom-feature`

## Interdictions

- Pas de `any` TypeScript
- Pas de logique métier dans les composants UI
- Pas de requêtes SQL directes (utiliser Eloquent)
- Pas de secrets dans le code (utiliser .env)
- Pas de `console.log` en production
- Pas de CSS inline pour du layout (utiliser Vuetify grid)
- Pas de service worker interceptant les requêtes API (incompatible avec Sanctum CSRF)
- Pas de `credentials: 'same-origin'` pour les requêtes API — toujours `'include'`
- Pas de mock API (MSW/fake-api supprimés) — l'API Laravel est la seule source de données
