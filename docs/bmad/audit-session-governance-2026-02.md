# Audit Session Governance — 2026-02-17

> Audit complet de la gestion des sessions, authentification et cycle de vie des requêtes.
> Périmètre : Backend (Laravel 12) + Frontend (Vue 3 SPA) — scopes Company et Platform.

---

## 1. Cartographie complète — État actuel

### 1.1 Backend — Configuration session

| Paramètre | Valeur | Source |
|------------|--------|--------|
| Driver | `database` | `config/session.php:21` + `.env` |
| Lifetime | 120 min (idle) | `config/session.php:35` |
| Expire on close | `false` | `config/session.php:37` |
| Encryption | `false` | `config/session.php:50` + `.env` |
| Cookie name | `{APP_NAME}-session` | `config/session.php:130-133` |
| Cookie path | `/` | `config/session.php:146` |
| Cookie domain | `.leezr.test` | `.env` |
| HTTP-Only | `true` | `config/session.php:185` |
| Secure cookie | `null` (non défini) | `config/session.php:172` |
| Same-Site | `lax` | `config/session.php:202` |
| Partitioned | `false` | `config/session.php:215` |
| Lottery cleanup | 2/100 | `config/session.php:117` |

**Table `sessions`** (migration `0001_01_01_000000`) :
```
id | user_id (nullable, indexed) | ip_address (45) | user_agent (text) | payload (longText) | last_activity (indexed)
```

### 1.2 Backend — Guards d'authentification

| Guard | Driver | Provider | Model | Usage |
|-------|--------|----------|-------|-------|
| `web` | session | `users` | `App\Core\Models\User` | Scope Company (via `auth:sanctum`) |
| `platform` | session | `platform_users` | `App\Platform\Models\PlatformUser` | Scope Platform (via `auth:platform`) |

**Sanctum** (`config/sanctum.php`) :
- Stateful domains : `leezr.test`
- Guard : `['web']`
- Token expiration : `null`
- Middleware : `AuthenticateSession` + `EncryptCookies` + `ValidateCsrfToken`
- `statefulApi()` activé dans `bootstrap/app.php:39`

### 1.3 Backend — Flux d'authentification

**Login Company** (`AuthController::login`) :
1. `Auth::attempt($credentials)` — pas de `$remember`
2. `$request->session()->regenerate()` — rotation session ID
3. Retourne `user` + `ui_theme`

**Login Platform** (`PlatformAuthController::login`) :
1. `Auth::guard('platform')->attempt($credentials)` — pas de `$remember`
2. `$request->session()->regenerate()`
3. Retourne `user` + `roles` + `permissions` + `platform_modules` + `ui_theme`

**Logout (les deux scopes)** :
1. `Auth::guard('...')->logout()`
2. `$request->session()->invalidate()` — supprime toute la session
3. `$request->session()->regenerateToken()` — nouveau CSRF token

**`/me` (les deux scopes)** :
- Lecture seule, pas de side-effect session
- Utilisé pour valider la session existante

### 1.4 Backend — Middleware d'accès

| Middleware | Alias | Rôle |
|------------|-------|------|
| `SetCompanyContext` | `company.context` | Valide `X-Company-Id`, vérifie membership + suspension |
| `EnsureRole` | `company.role` | Vérifie rôle minimum (hierarchy: user < admin < owner) |
| `EnsureCompanyAccess` | `company.access` | Vérifie ability (`access-surface`, `use-permission:X`, `use-module:X`, `manage-structure`) |
| `EnsureCompanyPermission` | `company.permission` | Vérifie permission granulaire RBAC |
| `EnsureModuleActive` | `module.active` | Vérifie `ModuleGate::isActive(company, key)` |
| `EnsurePlatformPermission` | `platform.permission` | Vérifie `$user->hasPermission($key)` (super_admin bypass) |
| `AddBuildVersion` | (appendé à `api`) | Header `x-build-version` sur toutes les réponses |

### 1.5 Backend — Routes et protection

**API publiques** (throttle 5/min) :
```
POST /api/register         POST /api/login          POST /api/forgot-password
POST /api/reset-password   POST /api/platform/login  POST /api/platform/forgot-password
```

**API protégées** :
```
Company : auth:sanctum + company.context → /api/company/*
Platform : auth:platform → /api/platform/*
Commun : auth:sanctum → /api/me, /api/logout, /api/my-companies
```

### 1.6 Frontend — Intercepteurs HTTP

**Company API** (`utils/api.js`) :
| Status | Comportement |
|--------|-------------|
| 401 | Si `_authCheck` : return (guard gère). Sinon : clear cookies `userData` + `currentCompanyId`, redirect `/login?redirect=...` |
| 419 | Refresh CSRF + retry 1 fois (`_retried` flag) |
| 403 | Toast erreur, pas de redirect |
| 500+ | Toast erreur générique |

**Platform API** (`utils/platformApi.js`) :
| Status | Comportement |
|--------|-------------|
| 401 | Si `_authCheck` : return. Sinon : clear cookies `platformUserData` + `platformRoles` + `platformPermissions`, redirect `/platform/login` |
| 419 | Refresh CSRF + retry 1 fois |
| 403 | Aucun handler explicite (fall-through) |
| 500+ | Aucun handler explicite |

### 1.7 Frontend — Runtime Phase Machine

```
cold → auth → tenant → features → ready
                                   ↓ (any failure on critical resource)
                                 error
```

- `boot(scope)` déclenché par le router guard au premier navigation
- `whenAuthResolved()` attend uniquement la phase `auth` (fetchMe)
- `whenReady(timeout)` attend la phase `ready` (toutes ressources chargées)
- `teardown()` annule tous les jobs, vide le cache, remet `cold`

**Resources** (`core/runtime/resources.js`) :
| Key | Phase | Store | Action | Critical | Cacheable |
|-----|-------|-------|--------|----------|-----------|
| `auth:me` | auth | auth | `fetchMe` | oui | **non** |
| `auth:companies` | tenant | auth | `fetchMyCompanies` | oui | 5 min |
| `tenant:jobdomain` | tenant | jobdomain | `fetchJobdomain` | non | 10 min |
| `features:modules` | features | module | `fetchModules` | non | 5 min |
| `platform:me` | auth | platformAuth | `fetchMe` | oui | **non** |

### 1.8 Frontend — Router Guard (`guards.js`)

1. **Version mismatch** : sessionStorage flag → reload si détecté
2. **Public routes** (`meta.public`) : boot public, pas d'auth
3. **Scope detection** : `meta.platform` → platform, sinon company
4. **Boot/reboot** : si cold ou scope change → `teardown()` + `boot(scope)` + `await whenAuthResolved()`
5. **Platform guard** : `unauthenticatedOnly` redirect, `!isLoggedIn` redirect, `meta.permission` check
6. **Company guard** : `unauthenticatedOnly` redirect, `!isLoggedIn` redirect + safe redirect param
7. **Structure routes** : `whenReady(5000)` + `roleLevel !== 'operational'`
8. **Module routes** : `whenReady(5000)` + `moduleStore.isActive(key)`

### 1.9 Frontend — Multi-tab sync

- `BroadcastChannel('leezr-runtime')` — 3 events : `logout`, `company-switch`, `cache-invalidate`
- Logout broadcast : `teardown()` + clear both auth stores + `window.location.href` redirect
- Company switch broadcast : `switchCompany()` re-hydrate tenant phase

### 1.10 Frontend — Persistance état

| Cookie | Scope | Contenu |
|--------|-------|---------|
| `userData` | Company | Objet user sérialisé |
| `currentCompanyId` | Company | ID de la company active |
| `platformUserData` | Platform | Objet user sérialisé |
| `platformRoles` | Platform | Array de role keys |
| `platformPermissions` | Platform | Array de permission keys |
| `platformModuleNavItems` | Platform | Array de nav items |

---

## 2. Liste des failles exactes

### F1. SESSION_ENCRYPT=false — Payload en clair en DB

- **Fichier** : `config/session.php:50` + `.env`
- **Risque** : Toute personne ayant accès à la DB peut lire les données session (user agent, IP, payload sérialisé)
- **Sévérité** : Moyenne (accès DB requis)
- **Impact** : Fuite de données session si backup DB compromis

### F2. SESSION_SECURE_COOKIE non défini — Cookie transmissible en HTTP

- **Fichier** : `config/session.php:172`, absent du `.env`
- **Risque** : En production HTTPS, le cookie session pourrait être transmis sur HTTP si downgrade
- **Sévérité** : Haute (production)
- **Impact** : Session hijacking via interception réseau

### F3. Aucun keepalive — Session expire silencieusement

- **Fichiers** : `resources/js/core/stores/auth.js`, `resources/js/core/runtime/resources.js`
- **Risque** : Aucun mécanisme de heartbeat. L'utilisateur peut travailler sur un formulaire pendant 2h, soumettre, et recevoir un 401 irrécupérable
- **Sévérité** : Haute (UX)
- **Impact** : Perte de données non sauvegardées, frustration utilisateur

### F4. Aucun avertissement d'expiration imminente

- **Fichiers** : Aucun composant `SessionTimeoutWarning` n'existe
- **Risque** : L'utilisateur n'a aucun signal visuel avant expiration (120 min idle)
- **Sévérité** : Moyenne (UX)
- **Impact** : Redirect surprise vers login, perte du contexte de travail

### F5. Dashboard accessible après expiration — jusqu'au prochain call API

- **Fichiers** : `resources/js/plugins/1.router/guards.js`, `resources/js/core/stores/auth.js`
- **Risque** : Le guard vérifie `auth.isLoggedIn` qui lit le cookie `userData`. Si la session expire côté serveur mais le cookie persiste, l'UI reste affichée. Le 401 ne survient qu'au prochain appel API
- **Sévérité** : Basse (données périmées mais pas de fuite)
- **Impact** : UI fantôme entre expiration serveur et prochain call API

### F6. Platform API — Pas de handler 403

- **Fichier** : `resources/js/utils/platformApi.js`
- **Risque** : Un 403 platform passe silencieusement sans toast ni feedback
- **Sévérité** : Basse (UX)
- **Impact** : Action interdite sans message d'erreur visible

### F7. Remember Me non implémenté — Colonnes DB inutilisées

- **Fichiers** : `AuthController.php:62`, `PlatformAuthController.php:22`
- **Risque** : Les colonnes `remember_token` existent dans `users` et `platform_users` mais `Auth::attempt()` n'utilise pas le paramètre `$remember`
- **Sévérité** : Info (dette technique)
- **Impact** : Aucun — fonctionnalité non activée

### F8. Same-Site=lax au lieu de strict

- **Fichier** : `config/session.php:202`
- **Risque** : Permet le transport du cookie session sur navigation top-level cross-site
- **Sévérité** : Basse (protection CSRF via Sanctum compense)
- **Impact** : Surface d'attaque CSRF marginalement élargie

### F9. 5s timeout sur whenReady pour structure/module routes

- **Fichier** : `resources/js/plugins/1.router/guards.js:98,113`
- **Risque** : Si l'hydratation tenant prend >5s (réseau lent), le guard peut laisser passer un rôle opérationnel sur une route structure
- **Sévérité** : Basse (race condition rare)
- **Impact** : Accès temporaire à une page interdite (le backend protège quand même)

### F10. Cookies auth non chiffrés côté client

- **Fichiers** : `auth.js:9-11`, `platformAuth.js:9-13`
- **Risque** : `useCookie('userData')` stocke l'objet user entier en clair dans un cookie lisible par JS
- **Sévérité** : Basse (cookie de convenance, pas de token d'auth — la session est côté serveur)
- **Impact** : Données user visibles dans le navigateur (nom, email)

---

## 3. Architecture cible propre

### 3.1 Principes

1. **Session = server-side truth** — le client ne fait que refléter
2. **Keepalive actif** — heartbeat périodique pour maintenir la session
3. **Warning avant expiration** — modale UI à T-5 min
4. **Graceful expiry** — modale de reconnexion, pas de redirect brutal
5. **Production hardening** — encryption, secure cookie, strict same-site

### 3.2 Diagramme de flux cible

```
┌─────────────────────────────────────────────────────┐
│                    BACKEND                          │
│                                                     │
│  Session DB (encrypted) ──── 120min idle timeout    │
│       │                                             │
│  GET /api/session/heartbeat → 200 + remaining_ttl   │
│  GET /api/session/status    → 200 + {active, ttl}   │
│       │                                             │
│  Middleware: AddSessionTTL (header X-Session-TTL)    │
│                                                     │
└─────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────┐
│                    FRONTEND                         │
│                                                     │
│  useSessionKeepalive() composable                   │
│    ├── Heartbeat: GET /heartbeat toutes les 10 min  │
│    ├── Parse X-Session-TTL de chaque réponse API    │
│    ├── Quand TTL < 5 min → émet 'session:warning'   │
│    └── Quand 401 reçu → émet 'session:expired'      │
│                                                     │
│  SessionWarningDialog.vue                           │
│    ├── Écoute 'session:warning'                     │
│    ├── Countdown visible avec option "Extend"       │
│    ├── Extend → POST /heartbeat → reset timer       │
│    └── Timeout → redirect login + save redirect     │
│                                                     │
│  SessionExpiredDialog.vue                           │
│    ├── Écoute 'session:expired' (401 interceptor)   │
│    ├── Modale "Session expirée"                     │
│    ├── Bouton "Se reconnecter" → login inline       │
│    └── Pas de redirect brutal — garde le contexte   │
│                                                     │
└─────────────────────────────────────────────────────┘
```

### 3.3 Comportement par phase

| Phase | Keepalive | Warning | Expiry |
|-------|-----------|---------|--------|
| `auth` (boot) | Non | Non | 401 → guard redirect |
| `ready` (normal) | Heartbeat 10min | Modale à TTL<5min | Modale reconnexion |
| `error` (runtime) | Non | Non | Teardown + reboot |
| Onglet inactif | Heartbeat suspendu | BroadcastChannel sync | Logout propagé |

---

## 4. Liste des fichiers à modifier

### Backend

| Fichier | Action | Description |
|---------|--------|-------------|
| `.env` | MODIFY | Ajouter `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true` |
| `.env.example` | MODIFY | Documenter les variables session production |
| `config/session.php` | VERIFY | Valeurs par défaut déjà correctes (read-only) |
| `routes/api.php` | MODIFY | Ajouter `GET /session/heartbeat` (auth:sanctum) |
| `routes/platform.php` | MODIFY | Ajouter `GET /session/heartbeat` (auth:platform) |
| `app/Core/Auth/AuthController.php` | MODIFY | Ajouter `heartbeat()` method |
| `app/Platform/Auth/PlatformAuthController.php` | MODIFY | Ajouter `heartbeat()` method |
| `bootstrap/app.php` | MODIFY | Ajouter middleware `AddSessionTTL` au groupe `api` |
| `app/Core/Http/Middleware/AddSessionTTL.php` | CREATE | Middleware qui ajoute `X-Session-TTL` header |

### Frontend

| Fichier | Action | Description |
|---------|--------|-------------|
| `resources/js/composables/useSessionKeepalive.js` | CREATE | Composable heartbeat + TTL tracking |
| `resources/js/layouts/components/SessionWarningDialog.vue` | CREATE | Modale avertissement expiration |
| `resources/js/layouts/components/SessionExpiredDialog.vue` | CREATE | Modale session expirée + reconnexion inline |
| `resources/js/layouts/components/PlatformLayoutWithVerticalNav.vue` | MODIFY | Intégrer SessionWarningDialog + SessionExpiredDialog |
| `resources/js/layouts/components/PlatformLayoutWithHorizontalNav.vue` | MODIFY | Idem |
| `resources/js/layouts/components/DefaultLayoutWithVerticalNav.vue` | MODIFY | Idem |
| `resources/js/layouts/components/DefaultLayoutWithHorizontalNav.vue` | MODIFY | Idem |
| `resources/js/utils/api.js` | MODIFY | 401 handler → émettre event au lieu de redirect direct |
| `resources/js/utils/platformApi.js` | MODIFY | Idem + ajouter handler 403 |
| `resources/js/core/runtime/runtime.js` | VERIFY | Aucun changement direct nécessaire |

### Documentation

| Fichier | Action | Description |
|---------|--------|-------------|
| `docs/bmad/04-decisions.md` | MODIFY | Ajouter ADR-070 Session Governance |

**Total : 9 backend (1 create, 7 modify, 1 verify) + 10 frontend (3 create, 5 modify, 2 verify) + 1 doc**

---

## 5. Séquence d'exécution sécurisée

### Phase 1 — Production hardening (0 risque, 0 impact UX)

1. `.env` : `SESSION_ENCRYPT=true`
2. `.env` : `SESSION_SECURE_COOKIE=true`
3. `.env.example` : documenter les variables
4. Tester : login/logout, session persiste, cookies HTTPS only
5. Commit + deploy

### Phase 2 — Backend heartbeat (non-breaking, additive)

1. Créer `app/Core/Http/Middleware/AddSessionTTL.php` — calcule `session.lifetime - (now - last_activity)`, ajoute header `X-Session-TTL`
2. Enregistrer dans `bootstrap/app.php` → `appendToGroup('api', AddSessionTTL::class)`
3. Ajouter route `GET /api/session/heartbeat` → retourne `{ active: true, ttl: $remaining }`
4. Ajouter route `GET /api/platform/session/heartbeat` → idem scope platform
5. Tester : chaque réponse API contient `X-Session-TTL`
6. Commit

### Phase 3 — Frontend keepalive composable

1. Créer `useSessionKeepalive.js` :
   - Timer configurable (default 10 min)
   - `$api('/session/heartbeat')` ou `$platformApi('/session/heartbeat')`
   - Parse `X-Session-TTL` de chaque réponse API (response interceptor)
   - EventBus: `session:warning` quand TTL < 300s, `session:expired` quand 401
   - Auto-suspend si `document.hidden` (Page Visibility API)
   - Auto-resume sur `visibilitychange`
2. Tester : heartbeat visible dans Network tab toutes les 10 min
3. Commit

### Phase 4 — Warning dialog

1. Créer `SessionWarningDialog.vue` — preset dialog Vuexy (`resources/ui/presets/dialogs/`)
   - Countdown timer visuel (minutes:secondes)
   - Bouton "Extend session" → POST heartbeat → reset timer
   - Bouton "Logout" → store.logout()
   - Auto-close si session extended par un autre onglet (BroadcastChannel)
2. Intégrer dans les 4 layouts (via composable, pas de duplication)
3. Tester : idle 115 min → modale apparaît → extend → modale disparaît
4. Commit

### Phase 5 — Expired dialog (replace redirect)

1. Créer `SessionExpiredDialog.vue` :
   - Modale non-fermable
   - Message "Votre session a expiré"
   - Bouton "Se reconnecter" → redirect `/login` (ou `/platform/login`)
   - Le redirect inclut le `fullPath` courant
2. Modifier `utils/api.js:56-71` : au lieu de redirect direct, émettre `session:expired`
3. Modifier `utils/platformApi.js:38-54` : idem + ajouter handler 403 avec toast
4. Tester : attendre expiration → modale → clic reconnecter → login avec redirect retour
5. Commit

### Phase 6 — Validation end-to-end

1. Test multi-onglet : logout onglet A → modale expirée onglet B
2. Test offline : heartbeat échoue → ne pas afficher de modale (retry)
3. Test race condition : heartbeat + navigation simultanée
4. Test scope switch : company → platform → session maintenue
5. `pnpm build` clean

---

## 6. Plan de tests

### 6.1 Tests backend (PHPUnit)

| Test | Assertion |
|------|-----------|
| `GET /api/session/heartbeat` auth → 200 + `ttl > 0` | Session prolongée |
| `GET /api/session/heartbeat` sans auth → 401 | Pas de session fantôme |
| Header `X-Session-TTL` présent sur toute réponse API authentifiée | Middleware actif |
| `X-Session-TTL` absent sur routes publiques | Pas de fuite info |
| Login → `SESSION_ENCRYPT=true` → payload DB est chiffré | Encryption active |
| `SESSION_SECURE_COOKIE=true` → cookie Set-Cookie inclut `Secure` | Cookie sécurisé |

### 6.2 Tests frontend (E2E / Manuel)

| Scénario | Résultat attendu |
|----------|-----------------|
| Login → idle 115 min → warning modale | Modale avec countdown 5 min |
| Warning modale → clic "Extend" | Heartbeat envoyé, modale fermée, timer reset |
| Warning modale → countdown 0 | Redirect vers login avec `?redirect=` |
| Idle 120+ min → clic sur bouton | Modale "Session expirée" (pas redirect brutal) |
| Onglet A logout → onglet B | Onglet B affiche modale expirée ou redirect login |
| Network offline → heartbeat fail | Pas de modale (retry silencieux) |
| Platform scope → heartbeat → `/api/platform/session/heartbeat` | Scope correct |
| Company scope → heartbeat → `/api/session/heartbeat` | Scope correct |
| Formulaire en cours → session expire | Modale non-bloquante, formulaire préservé en DOM |
| `document.hidden` (onglet inactif) | Heartbeat suspendu, reprise sur focus |

### 6.3 Tests de non-régression

| Test | Assertion |
|------|-----------|
| Login/logout company fonctionne | Pas de régression auth |
| Login/logout platform fonctionne | Pas de régression auth |
| Multi-tab company switch fonctionne | BroadcastChannel intact |
| Theme settings persistent | Cookie/DB sync intact |
| `pnpm build` → 0 erreurs | Build clean |

---

## 7. ADR proposée — ADR-070 : Session Governance

```markdown
## ADR-070 : Session Governance — Keepalive, Warning & Graceful Expiry

- **Date** : 2026-02-17
- **Contexte** : L'audit session (audit-session-governance-2026-02.md) révèle :
  (1) Aucun keepalive — la session expire silencieusement après 120 min idle.
  (2) Aucun warning — l'utilisateur n'est pas prévenu avant expiration.
  (3) Expiration brutale — redirect vers login sans possibilité de reconnecter in-place.
  (4) Session non chiffrée en DB — `SESSION_ENCRYPT=false`.
  (5) Cookie secure non forcé — `SESSION_SECURE_COOKIE` absent.

- **Décision** : Implémenter un système de session governance en 5 couches :

  **Couche 1 — Production hardening** :
  - `SESSION_ENCRYPT=true` en production
  - `SESSION_SECURE_COOKIE=true` en production
  - Documenté dans `.env.example`

  **Couche 2 — Backend heartbeat** :
  - `GET /api/session/heartbeat` (auth:sanctum) + `GET /api/platform/session/heartbeat` (auth:platform)
  - Middleware `AddSessionTTL` : header `X-Session-TTL` (secondes restantes) sur toute réponse API
  - Touche `last_activity` sans side-effect

  **Couche 3 — Frontend keepalive** :
  - Composable `useSessionKeepalive()` — heartbeat toutes les 10 min
  - Suspend quand `document.hidden` (Page Visibility API), reprend sur focus
  - Parse `X-Session-TTL` pour tracking TTL côté client

  **Couche 4 — Warning dialog** :
  - `SessionWarningDialog.vue` (preset Vuexy dialog)
  - Déclenché à TTL < 5 min — countdown visible
  - "Extend session" → heartbeat → reset timer
  - Sync multi-onglet via BroadcastChannel

  **Couche 5 — Graceful expiry** :
  - `SessionExpiredDialog.vue` — modale non-fermable
  - Remplace le redirect brutal des intercepteurs 401
  - Préserve le contexte DOM (formulaire en cours pas perdu)
  - "Se reconnecter" → redirect avec `?redirect=currentPath`

- **Conséquences** :
  - Aucune perte de données liée à l'expiration session
  - UX prévisible : warning → extend ou logout
  - Multi-onglet synchronisé via BroadcastChannel existant
  - Production : session chiffrée, cookie secure, same-site lax (à évaluer strict plus tard)
  - Aucune modification de `@core/` ou `@layouts/`
  - 3 fichiers frontend créés, 5 modifiés
  - 1 middleware backend créé, 2 routes ajoutées
```

---

## Résumé

| Catégorie | Constat |
|-----------|---------|
| **Forces** | Dual-guard session, CSRF Sanctum, BroadcastChannel multi-tab, DB sessions, HTTP-Only cookies, rate limiting auth, permission RBAC platform |
| **Failles critiques** | F2 (secure cookie prod), F3 (no keepalive), F4 (no warning) |
| **Failles moyennes** | F1 (encryption off), F5 (UI fantôme post-expiry) |
| **Failles basses** | F6 (platform 403), F8 (same-site lax), F9 (5s timeout race), F10 (user data in cookie) |
| **Info** | F7 (remember me unused) |
| **Fichiers à modifier** | 9 backend + 10 frontend + 1 doc = **20 fichiers** |
| **Séquence** | 6 phases, chacune commitée indépendamment, non-breaking |
