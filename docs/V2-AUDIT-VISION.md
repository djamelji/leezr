# AUDIT GLOBAL & VISION V2 — LEEZR SAAS

> Date : 2026-04-10 | Auteur : Claude Opus 4.6 (audit automatisé)
> Scope : Backend, Frontend, Infra, Billing, Documents, AI, Automations, Realtime, UX

---

## TABLE DES MATIÈRES

1. [Audit Global](#1-audit-global)
2. [Problèmes Critiques Priorisés](#2-problèmes-critiques-priorisés)
3. [Vision V2](#3-vision-v2)
4. [Plan d'Exécution par Phases](#4-plan-dexécution-par-phases)

---

# 1. AUDIT GLOBAL

## 1.1 État Actuel — Vue d'Ensemble

| Surface | Fichiers | Score | Verdict |
|---------|----------|-------|---------|
| Backend (Core + Modules) | ~600 PHP | 7/10 | Architecture solide, dettes ciblées |
| Frontend (Vue + Pinia) | ~300 Vue, 39 stores | 6.5/10 | Bon runtime, manque anti-blink & smart merge |
| Infrastructure | CI/CD + VPS | 3/10 | Non commercial-grade, zéro observabilité |
| Billing | 91 fichiers | 8/10 | Complet et robuste, mutations complexes |
| Documents/AI | ~50 fichiers | 7/10 | Pipeline fonctionnel, couplage à réduire |
| Realtime (SSE) | ~20 fichiers | 5/10 | Fonctionnel mais plafonné à ~1000 events/5min |
| UX | 300 pages | 5/10 | Pas de standard anti-blink, feedback inconsistant |

**Score global : 5.5/10 — Fonctionnel mais pas vendable en l'état.**

---

## 1.2 Architecture Backend

### Forces
- Module architecture claire (Platform / Core / Company) avec manifests déclaratifs
- Billing complet : Dunning, Wallet, SEPA, Reconciliation, Webhooks idempotents
- AI Pipeline avec gating 3 niveaux (platform → company → document)
- Multi-tenant via middleware `SetCompanyContext` + `EnsureCompanyAccess`
- 14 tâches schedulées instrumentées (`SchedulerInstrumentation`)

### Faiblesses

**F1 — Pas de query scoping global**
Les controllers appliquent manuellement `where('company_id', ...)`. Oublier = fuite de données inter-tenant.
```php
// RISQUE : un oubli de where() expose toutes les companies
$documents = CompanyDocument::all(); // FUITE
```

**F2 — DunningEngine monolithique (363 lignes)**
Mélange query building, state transitions, notifications, appels provider. Pas de transaction boundaries.
```php
// Risque : payment réussit mais notification échoue → état incohérent
DunningRetryStrategy::attemptPayment($invoice);
DunningNotifier::notifySuccess($invoice); // peut échouer silencieusement
```

**F3 — Jobs sous-utilisés (seulement 4)**
Les Artisan commands font le gros du travail synchrone. Les jobs existants appellent `Artisan::call()` au lieu de services directs.

**F4 — Invoice mutations : 5+ chemins**
Admin mutations, auto-repair, credit notes, annexes, dunning — pas d'audit trail unifié.

**F5 — Realtime : plafond EventFloodDetector**
Kill-switch à 1000 events/5min désactive tout le SSE pendant 1 heure.

**F6 — Owner bypass trop large**
`isOwnerOf()` → `return true` pour toutes les permissions. Compte owner compromis = full breach.

**F7 — Notifications sync (emails bloquants)**
`$recipient->notify($mailNotification)` est synchrone, bloque le thread.

---

## 1.3 Architecture Frontend

### Forces
- Runtime boot machine sophistiqué (5 phases, dédup, journaling)
- Realtime SSE pragmatique (debounce 2s, fallback polling, DomainEventBus découplé)
- 39 stores Pinia avec convention `_prefix` claire
- 29 composables bien nommés et réutilisables
- Cross-tab sync via BroadcastChannel (logout, company-switch)
- i18n cohérent (4400+ keys fr/en)
- Session governance (TTL countdown, multi-tab)

### Faiblesses

**F8 — Aucun mécanisme anti-blink**
Pas de smart merge, pas d'optimistic updates, pas de hold-old-UI. Le seul mécanisme est le debounce d'invalidations SSE (2s).

**F9 — Stores : overwrite au lieu de merge**
```javascript
// Actuel : écrase tout → blink possible
this._items = newData.items

// Nécessaire : merge intelligent
this._mergeItems(newData.items, { version: newData.version })
```

**F10 — VDataTableServer avec filtrage client-side**
Charge TOUT le dataset en mémoire puis filtre en computed. Non scalable > 1000 rows.

**F11 — Pas de timeout sur boot phases**
Si une API ne répond pas, le boot hang indéfiniment.

**F12 — Aucun feedback SSE connected/disconnected**
L'utilisateur ne sait pas s'il voit des données live ou stale.

**F13 — Navigation items recomputed sans memoization**

---

## 1.4 Infrastructure

### Forces
- Déploiement atomique par symlink (artifact-based, zero-build-on-VPS)
- Concurrency control (`cancel-in-progress: false`)
- Build metadata (.build-sha, .build-version, .app-meta)
- Rollback disponible (`deploy/rollback.sh`)

### Faiblesses

**F14 — `migrate:fresh --seed` en production**
Le deploy script exécute `migrate:fresh --seed --force` = DESTRUCTION de toutes les données.

**F15 — Zéro tests en CI**
Le pipeline build + deploy ne lance ni PHPUnit ni ESLint. Migrations cassées découvertes post-deploy.

**F16 — Health check superficiel**
`/up` retourne juste 200 OK, ne vérifie ni DB, ni queue, ni cache, ni disk.

**F17 — Zéro monitoring centralisé**
Pas de Sentry, Datadog, Prometheus, Grafana, ELK, ni alerting Slack/PagerDuty.

**F18 — Logs non rotatés**
`laravel.log` = 155 GB en dev. Aucune rotation configurée.

**F19 — Pas de backup database**
MySQL single instance, pas de réplication, pas de snapshot, pas de backup planifié.

**F20 — Pas de secret management**
`.env` en clair sur le VPS, jamais roté, pas de vault.

**F21 — Queue database (pas Redis)**
`QUEUE_CONNECTION=database` → scan de table `jobs` lent, pas de priorités.

**F22 — Single point of failure partout**
Un VPS, un datacenter, un MySQL, un Redis (quand actif). Aucune redondance.

**F23 — Workers sans monitoring**
systemd restart automatique mais aucune notification si crash loop.

**F24 — Scheduler sans alerting**
`onFailure()` log mais n'alerte personne. Tâches décrochent silencieusement.

---

## 1.5 UX & Realtime

### Forces
- Toast centralisé (`useAppToast`)
- Drawers cohérents (49+ locations)
- VCard loading state standard
- Session governance (TTL warning avant logout)

### Faiblesses

**F25 — Pas de standard UX global**
Chaque page gère loading/error/empty différemment. Pas de composable `useAsyncPage()`.

**F26 — Skeleton après rechargement**
Full skeleton affiché même si les données sont déjà en cache.

**F27 — Pas d'optimistic updates**
CRUD attend la réponse serveur avant update UI → latence perçue.

**F28 — Race condition SSE + API**
SSE event arrive pendant un fetch API → double mutation du store → blink.

---

# 2. PROBLÈMES CRITIQUES PRIORISÉS

## P0 — Bloque le produit / Non vendable

| # | Problème | Surface | Cause racine | Impact |
|---|----------|---------|--------------|--------|
| P0-1 | `migrate:fresh` en prod | Infra | Deploy script destructif | **Data loss totale** à chaque deploy |
| P0-2 | Zéro tests en CI | Infra | Pipeline sans PHPUnit | Migrations cassées en prod |
| P0-3 | Pas de backup DB | Infra | Aucune stratégie | Perte irréversible |
| P0-4 | Pas de monitoring | Infra | Aucun outil | Erreurs invisibles, downtime silencieux |
| P0-5 | Fuite inter-tenant possible | Backend | Pas de global scope | Données company A visibles par company B |

## P1 — UX dégradée / Dangereux

| # | Problème | Surface | Cause racine | Impact |
|---|----------|---------|--------------|--------|
| P1-1 | Blink systémique | Frontend | Pas de smart merge / anti-blink | UX non professionnelle |
| P1-2 | DunningEngine sans transactions | Backend | Pas de DB::transaction() | États incohérents billing |
| P1-3 | Realtime plafond 1000 evt/5min | Backend | EventFloodDetector kill-switch | SSE désactivé 1h en burst |
| P1-4 | Health check superficiel | Infra | `/up` = 200 OK sans vérification | Deploy "réussi" sur app cassée |
| P1-5 | Logs 155 GB non rotatés | Infra | Pas de logrotate | Disk full → crash cascade |
| P1-6 | Notifications sync (email) | Backend | Pas de queue | Thread bloqué pendant envoi |
| P1-7 | Owner bypass total | Backend | `isOwnerOf() → true` | Compromission = full breach |
| P1-8 | Boot sans timeout | Frontend | Pas de deadline | Hang si API down |
| P1-9 | Queue database lente | Infra | Pas Redis pour queue | Latence jobs, backpressure |
| P1-10 | Workers sans alerting | Infra | Pas de notification crash | Jobs perdus silencieusement |

## P2 — Amélioration nécessaire

| # | Problème | Surface | Cause racine | Impact |
|---|----------|---------|--------------|--------|
| P2-1 | VDataTableServer client-side | Frontend | Pas de pagination server | Lent > 1000 rows |
| P2-2 | Jobs appellent Artisan::call() | Backend | Architecture initiale | Hard to test, overhead |
| P2-3 | Invoice 5+ mutation paths | Backend | Complexité organique | Audit trail difficile |
| P2-4 | Pas d'API versioning | Backend | Design initial | Breaking changes risqués |
| P2-5 | i18n arabe incomplet | Frontend | 266 vs 4400 keys | Feature non supportée |
| P2-6 | ProcessDocumentAiJob couplé | Backend | Tout dans un job | Hard to test/extend |
| P2-7 | Pas de feedback SSE | Frontend | Pas d'indicateur | User ne sait pas si live |
| P2-8 | Routes 939 lignes manuelles | Backend | Pas d'auto-discovery | Maintenance lourde |
| P2-9 | Pas de circuit breaker | Backend | Appels Stripe directs | Cascade failure possible |
| P2-10 | Secret management absent | Infra | .env en clair | Risque sécurité |

---

# 3. VISION V2

## 3.1 Realtime Backbone

### Objectif : Zero-blink, update granulaire, SSE fiable

**Architecture cible :**

```
┌─────────────┐     ┌──────────────┐     ┌─────────────────┐
│ Domain Event │ ──→ │ EventEnvelope│ ──→ │ Redis Streams    │
│ (backend)    │     │ + version    │     │ (ordered, durable)│
└─────────────┘     └──────────────┘     └────────┬────────┘
                                                   │
                    ┌──────────────────────────────┘
                    ▼
┌─────────────────────────┐     ┌─────────────────────┐
│ SSE Stream Controller    │ ──→ │ Client EventSource   │
│ (per-company, per-user) │     │ + last-event-id      │
│ cursor-based read       │     │ + reconnect recovery │
└─────────────────────────┘     └──────────┬──────────┘
                                           │
                    ┌──────────────────────┘
                    ▼
┌─────────────────────────────────────────────┐
│ ChannelRouter → DomainEventBus → Store merge │
│ (version-aware, row-level, no full reload)   │
└─────────────────────────────────────────────┘
```

**Principes V2 :**

1. **Redis Streams** au lieu de FIFO/PubSub
   - Messages ordonnés, durables, avec cursor (last-event-id)
   - Consumer groups pour multi-instance
   - Pas de perte de messages à la reconnexion
   - `XREAD BLOCK` au lieu de polling 100ms

2. **Version vector sur chaque entité**
   - Chaque model a un `version` auto-incrémenté
   - SSE envoie `{ entity_id, version, delta }`
   - Store rejette si `incoming.version <= current.version`
   - Élimine les race conditions SSE vs API

3. **Row-level updates**
   - SSE envoie le delta (champs modifiés) pas l'entité complète
   - Store fait `Object.assign(existing, delta)` — pas de remplacement
   - Listes : insert/update/delete par ID, pas de re-fetch global

4. **Séparation platform vs company**
   - Streams séparés : `realtime:company:{id}`, `realtime:platform`
   - ACL par topic (user ne reçoit que ce qu'il peut voir)
   - Platform events : billing metrics, admin alerts
   - Company events : documents, members, dashboard

5. **Indicateur connexion**
   - Badge navbar : vert (live), orange (reconnecting), rouge (offline/polling)
   - Tooltip : "Dernière mise à jour il y a X secondes"

6. **Suppression du flood detector**
   - Redis Streams gère nativement le backpressure
   - Trimming automatique (`MAXLEN ~10000`)
   - Plus de kill-switch arbitraire

---

## 3.2 State Management

### Objectif : Stores idempotents, merge intelligent, zéro rerender global

**Principes V2 :**

1. **Smart Merge Pattern (obligatoire)**
   ```javascript
   // Composable réutilisable
   function useSmartCollection(key) {
     const _items = ref(new Map())  // Map<id, entity>
     const _version = ref(0)

     function merge(incoming, { version }) {
       if (version <= _version.value) return // Stale, ignore
       for (const item of incoming) {
         const existing = _items.value.get(item.id)
         if (!existing || item.version > existing.version) {
           _items.value.set(item.id, item)
         }
       }
       _version.value = version
     }

     function applyDelta(id, delta) {
       const existing = _items.value.get(id)
       if (existing) Object.assign(existing, delta)
     }

     return { items: computed(() => [..._items.value.values()]), merge, applyDelta }
   }
   ```

2. **Optimistic Updates**
   ```javascript
   async function deleteItem(id) {
     const backup = _items.value.get(id)
     _items.value.delete(id) // Optimistic
     try {
       await api.delete(`/items/${id}`)
     } catch (e) {
       _items.value.set(id, backup) // Revert
       toast.error('Échec suppression')
     }
   }
   ```

3. **Hold-Old-UI Pattern**
   ```javascript
   // Pendant un fetch, garder l'ancienne UI
   const isRefreshing = ref(false) // Pas isLoading
   async function refresh() {
     isRefreshing.value = true // Subtle indicator, pas skeleton
     const data = await api.get(...)
     merge(data) // Merge, pas overwrite
     isRefreshing.value = false
   }
   ```

4. **Store Pagination Server-Side (obligatoire)**
   - VDataTableServer DOIT toujours paginer côté serveur
   - Composable `useServerPagination(endpoint, { filters, sort })`
   - Jamais de `.filter()` client sur collection complète

---

## 3.3 Scheduler / Automations

### Objectif : Source de vérité unique, cockpit fiable, exécution observable

**Architecture cible :**

```
┌──────────────────────┐
│ AutomationRule (DB)   │ ← Source de vérité unique
│ key, cron, enabled    │
│ last_run_at, status   │
│ next_run_at (computed)│
└──────────┬───────────┘
           │
┌──────────▼───────────┐
│ SchedulerOrchestrator │ ← Remplace routes/console.php
│ - Lit rules depuis DB │
│ - Dispatch jobs async │
│ - Instrument chaque run│
└──────────┬───────────┘
           │
┌──────────▼───────────┐     ┌─────────────────┐
│ AutomationRunLog (DB) │ ──→ │ Cockpit Dashboard │
│ status, duration      │     │ (realtime via SSE)│
│ output, error         │     └─────────────────┘
│ actions_count         │
└───────────────────────┘
```

**Principes V2 :**

1. **DB-driven scheduling**
   - `routes/console.php` ne contient plus de hardcode
   - Chaque task = `AutomationRule` en DB, activable/désactivable via UI
   - Admin peut modifier cron expression sans deploy

2. **Dispatch async obligatoire**
   - Chaque task schedulée dispatch un job (pas de commande sync)
   - Timeout par job, pas par scheduler run
   - Overlapping détecté par lock DB (pas flock fichier)

3. **Alerting intégré**
   - `onFailure()` → Notification Slack + email admin
   - Dead-letter monitoring automatique
   - Dashboard "Scheduler Health" avec indicateur par task

4. **SLA enforcement**
   - Chaque task a un `max_duration` configuré
   - Dépassement = kill + alert + log
   - Historique des durées pour détection de dégradation

---

## 3.4 Queue System

### Objectif : Séparation claire, garantie d'exécution, monitoring réel

**Architecture cible :**

```
Queues:
├── critical   — Paiements, webhooks, mutations financières
│   └── SLA: < 5s processing, 0 loss tolerance
├── default    — Notifications, documents, CRUD async
│   └── SLA: < 30s processing, retry 3x
├── ai         — AI analysis, OCR, vision
│   └── SLA: < 120s processing, retry 3x, backoff exponentiel
└── bulk       — Exports, imports, batch operations
    └── SLA: < 5min processing, retry 1x
```

**Principes V2 :**

1. **Redis comme queue backend**
   - `QUEUE_CONNECTION=redis` (pas database)
   - `predis` en attendant `phpredis` (ou migration vers serveur avec extension)
   - Performances 10-50x supérieures au scan de table `jobs`

2. **Workers dédiés par queue**
   - 1 worker `critical` (priority, --tries=5)
   - 2 workers `default` (--tries=3)
   - 1 worker `ai` (--timeout=120, --tries=3)
   - 1 worker `bulk` (--timeout=300, --tries=1)

3. **Dead-letter queue supervision**
   - Table `failed_jobs` surveillée toutes les 5 minutes
   - Alert Slack si > 0 failed jobs
   - Dashboard admin avec retry/purge

4. **Graceful shutdown**
   - Workers captent SIGTERM → finissent le job en cours
   - Deploy : `systemctl reload` (pas restart brutal)

5. **Circuit breaker pour appels externes**
   - Stripe, AI providers, SMTP : circuit breaker pattern
   - Open after 3 failures → cooldown 60s → half-open → retry
   - Évite la boucle infinie crash/restart

---

## 3.5 AI Pipeline

### Objectif : Latence < 5s cible, fallback UX, zéro pending infini

**Architecture cible :**

```
Upload → Dispatch Job → Gate Check → Analysis Pipeline → Decision → Mutations → SSE

Analysis Pipeline:
  ├── Step 1: MRZ (instant, 100% fiable, gratuit)
  │   └── Si succès → return immédiat
  ├── Step 2: AI Vision (Anthropic/Ollama)
  │   └── Timeout: 30s Anthropic, 60s Ollama
  │   └── Si timeout → Step 3
  └── Step 3: OCR fallback (confidence 0.2)
      └── Toujours disponible
```

**Principes V2 :**

1. **Timeout strict + UX feedback**
   - Job timeout: 120s max
   - Frontend: après 5s, afficher "Analyse en cours..."
   - Après 30s: "L'analyse prend plus longtemps que prévu"
   - Après 120s: "Analyse terminée avec résultats partiels" (OCR fallback)

2. **SSE granulaire par document**
   - `document.ai_started` → UI: spinner sur le document
   - `document.ai_progress` → UI: étape en cours (MRZ/Vision/OCR)
   - `document.ai_completed` → UI: résultats + suggestions
   - `document.ai_failed` → UI: message + action manuelle

3. **Découplage job ↔ service**
   - `ProcessDocumentAiJob` ne contient que l'orchestration
   - `DocumentAiAnalysisService` : analyse pure (testable)
   - `DocumentAiDecisionService` : décisions (testable)
   - `DocumentAiMutationService` : mutations (transactionnelles)

4. **Cache de résultats**
   - Même document re-uploadé → check hash → skip si déjà analysé
   - Économise tokens AI + temps

---

## 3.6 UX Standards

### Objectif : Jamais de skeleton après load initial, jamais de blink, feedback progressif

**Règles UX V2 (non négociables) :**

| Règle | Interdit | Obligatoire |
|-------|----------|-------------|
| Premier chargement | - | Skeleton/loader acceptable |
| Refresh/navigation retour | Skeleton, loader plein écran | Hold-old-UI + indicateur subtil (spinner toolbar) |
| Mutation CRUD | Attente réponse avant update | Optimistic update + revert si erreur |
| SSE update | Remplacement global | Merge row-level, animation transition |
| Erreur réseau | Rien, ou crash | Toast error + retry automatique |
| Chargement long (AI) | Spinner indéfini | Étapes progressives + timeout UX |
| Table rechargement | Full skeleton | Overlay léger sur données existantes |
| Drawer fermeture | Disparition brutale | Animation + confirmation si dirty |

**Composables standardisés :**

```
useAsyncPage(fetchFn)          → { data, isFirstLoad, isRefreshing, error, refresh }
useSmartCollection(key)         → { items, merge, applyDelta, remove }
useOptimisticAction(action)     → { execute, isPending, revert }
useServerPagination(endpoint)   → { items, page, total, sort, filters, load }
useRealtimeIndicator()          → { status: 'live'|'reconnecting'|'offline' }
```

---

## 3.7 Multi-Tenant Security

### Objectif : Isolation garantie par construction, pas par convention

**Principes V2 :**

1. **Global Scopes obligatoires**
   ```php
   // Trait appliqué à tous les models company-scoped
   trait BelongsToCompany {
       protected static function booted() {
           static::addGlobalScope('company', new CompanyScope);
       }
   }

   class CompanyScope implements Scope {
       public function apply(Builder $builder, Model $model) {
           if ($companyId = app('company.context')?->id) {
               $builder->where($model->getTable().'.company_id', $companyId);
           }
       }
   }
   ```

2. **Test automatisé d'isolation**
   - PHPUnit test qui crée 2 companies, insère data dans chacune
   - Vérifie qu'une query dans le contexte de company A ne voit pas les données de B
   - Couvre tous les models avec `BelongsToCompany`

3. **Owner permissions scoped**
   - Owner n'a plus bypass total
   - Owner a un role spécial `owner` avec toutes les permissions listées
   - Permet de retirer des permissions même au owner si nécessaire

---

## 3.8 Observabilité

### Objectif : Savoir en temps réel ce qui se passe, alerter avant que l'utilisateur ne signale

**Stack cible :**

```
Application → Sentry (errors + performance)
Infra       → Prometheus + Grafana (metrics)
Logs        → Structured JSON → rotation + agrégation
Alerting    → Slack (critical) + Email (daily digest)
Uptime      → Healthchecks.io ou UptimeRobot
```

**Métriques clés à exporter :**

| Catégorie | Métrique | Seuil alerte |
|-----------|----------|-------------|
| App | Error rate (5xx) | > 1% sur 5min |
| App | Response time P95 | > 2s |
| Billing | Failed payments / day | > 5 |
| Billing | Dunning success rate | < 80% |
| Queue | Failed jobs count | > 0 |
| Queue | Job processing time P95 | > 60s (default), > 120s (ai) |
| SSE | Active connections | Info |
| SSE | Reconnection rate | > 10/min |
| DB | Slow queries (> 1s) | > 5/min |
| DB | Connection count | > 80% max |
| Disk | Usage % | > 80% |
| Scheduler | Missed runs | > 0 |

**Health endpoint V2 :**

```json
GET /api/health
{
  "status": "ok|degraded|critical",
  "checks": {
    "database": { "status": "ok", "latency_ms": 2 },
    "redis": { "status": "ok", "latency_ms": 1 },
    "queue": { "status": "ok", "pending": 3, "failed": 0 },
    "cache": { "status": "ok" },
    "disk": { "status": "ok", "usage_pct": 45 },
    "scheduler": { "status": "ok", "last_run": "2026-04-10T06:00:00Z" },
    "sse": { "status": "ok", "connections": 12 }
  },
  "version": "a1b2c3d",
  "uptime_seconds": 86400
}
```

---

# 4. PLAN D'EXÉCUTION PAR PHASES

## Phase 1 — Stabilisation Critique (Semaines 1-2)

> **Objectif : Rendre le produit déployable sans risque de data loss**

### Actions

| # | Action | Fichiers impactés | Effort |
|---|--------|-------------------|--------|
| 1.1 | Remplacer `migrate:fresh` par `migrate --force` | `deploy/deploy_release.sh` | 1h |
| 1.2 | Ajouter PHPUnit + pnpm build en CI | `.github/workflows/deploy.yml` | 2h |
| 1.3 | Implémenter health check complet | Nouveau controller + route | 4h |
| 1.4 | Configurer logrotate sur VPS | `/etc/logrotate.d/leezr` | 1h |
| 1.5 | Setup backup DB quotidien | Cron mysqldump + rotation | 2h |
| 1.6 | Ajouter alerting Slack (scheduler + queue) | `config/services.php`, scheduler hooks | 4h |
| 1.7 | Intégrer Sentry (backend + frontend) | `composer require sentry/sentry-laravel`, plugin Vue | 3h |
| 1.8 | Ajouter `BelongsToCompany` global scope | Trait + application sur ~20 models | 8h |
| 1.9 | Ajouter timeout au boot frontend (30s) | `runtime/bootMachine.js` | 2h |
| 1.10 | Queue emails async | `NotificationDispatcher` → `ShouldQueue` | 2h |

### Risques
- 1.8 (global scopes) peut casser des queries admin/platform qui doivent voir cross-company → besoin de `withoutGlobalScope()` explicite
- 1.2 (tests CI) peut révéler des tests cassés → les fixer d'abord en local

### Stratégie
- Déployer 1.1 en premier (urgence absolue)
- 1.2 + 1.7 en parallèle
- 1.8 avec tests exhaustifs avant merge

**Livrable : produit déployable sans risque, erreurs visibles, alertes actives.**

---

## Phase 2 — Realtime Propre (Semaines 3-5)

> **Objectif : SSE fiable, anti-blink, updates granulaires**

### Actions

| # | Action | Fichiers impactés | Effort |
|---|--------|-------------------|--------|
| 2.1 | Migrer transport SSE vers Redis Streams | `Core/Realtime/Transport/`, config | 16h |
| 2.2 | Ajouter `version` sur models critiques | Migration + trait `HasVersion` | 8h |
| 2.3 | Implémenter `useSmartCollection()` composable | Nouveau composable | 8h |
| 2.4 | Implémenter `useOptimisticAction()` composable | Nouveau composable | 4h |
| 2.5 | Refactorer stores billing/documents avec smart merge | 6-8 stores | 16h |
| 2.6 | Implémenter hold-old-UI sur navigation | Router guards + stores | 8h |
| 2.7 | Ajouter indicateur SSE (navbar badge) | Component + composable | 4h |
| 2.8 | Supprimer EventFloodDetector, configurer MAXLEN Streams | Realtime config | 2h |
| 2.9 | SSE reconnection avec last-event-id recovery | RealtimeClient.js + backend | 8h |

### Risques
- 2.1 (Redis Streams) nécessite Redis installé et fiable sur VPS
- 2.5 (refactoring stores) risque de régressions → couvrir par tests E2E

### Stratégie
- 2.1 + 2.2 en backend pendant que 2.3 + 2.4 avancent en frontend
- 2.5 store par store, avec validation manuelle
- 2.9 en dernier (nécessite 2.1 + 2.8)

**Livrable : realtime fiable, zéro blink sur navigation et updates SSE.**

---

## Phase 3 — UX SaaS Premium (Semaines 6-8)

> **Objectif : UX niveau Stripe/Linear/Notion**

### Actions

| # | Action | Fichiers impactés | Effort |
|---|--------|-------------------|--------|
| 3.1 | Créer `useAsyncPage()` composable standard | Nouveau composable | 4h |
| 3.2 | Créer `useServerPagination()` composable | Nouveau composable | 8h |
| 3.3 | Migrer toutes les tables vers server-side pagination | 12+ pages | 24h |
| 3.4 | Standardiser feedback erreur (toast + retry) | Composable + pages | 8h |
| 3.5 | Ajouter transitions CSS sur mutations (fade, slide) | SCSS global | 4h |
| 3.6 | Refactorer drawers avec dirty-check + confirmation | Composable drawer | 8h |
| 3.7 | AI pipeline : feedback progressif (started/progress/completed) | SSE events + frontend | 12h |
| 3.8 | Dashboard : cache widgets + invalidation SSE | Backend cache + frontend | 12h |
| 3.9 | Empty states design (illustrations Vuexy) | Presets + pages | 8h |

### Risques
- 3.3 (pagination server) nécessite des endpoints backend ajustés (filtrage, tri côté serveur)
- 3.7 (AI feedback) nécessite phase 2 terminée (SSE fiable)

### Stratégie
- 3.1 + 3.2 d'abord (composables fondation)
- 3.3 page par page, commencer par les plus utilisées (members, invoices, documents)
- 3.7 + 3.8 en parallèle (backend + frontend)

**Livrable : UX premium, feedback cohérent, tables performantes, AI transparent.**

---

## Phase 4 — Performance & AI (Semaines 9-11)

> **Objectif : Latence acceptable, billing robuste, AI prédictible**

### Actions

| # | Action | Fichiers impactés | Effort |
|---|--------|-------------------|--------|
| 4.1 | Migrer queue vers Redis backend | `config/queue.php`, deploy | 4h |
| 4.2 | Séparer queues (critical/default/ai/bulk) | Config + workers systemd | 4h |
| 4.3 | Ajouter transaction boundaries au DunningEngine | `DunningEngine.php` | 8h |
| 4.4 | Refactorer DunningEngine en commands | 3 commandes séparées | 16h |
| 4.5 | Découpler ProcessDocumentAiJob | 3 services séparés | 12h |
| 4.6 | Implémenter circuit breaker (Stripe, AI) | Nouveau service | 8h |
| 4.7 | Cache de résultats AI (hash document) | Service + migration | 4h |
| 4.8 | Dashboard widget caching (Redis TTL) | Backend services | 8h |
| 4.9 | Optimiser N+1 queries (eager loading audit) | Models + controllers | 8h |
| 4.10 | DB connection pooling review | Config MySQL | 2h |

### Risques
- 4.1 (Redis queue) : `predis` est lent, mais fonctionnel. Idéalement installer `phpredis`
- 4.3-4.4 (dunning refactoring) : zone critique billing, tests exhaustifs obligatoires

### Stratégie
- 4.1 + 4.2 rapidement (gain immédiat de performance queue)
- 4.3 avant 4.4 (sécuriser les transactions d'abord)
- 4.5 + 4.6 en parallèle

**Livrable : billing robuste avec transactions, queues performantes, AI avec cache.**

---

## Phase 5 — Observabilité & Ops (Semaines 12-14)

> **Objectif : Monitoring complet, incident response, confiance opérationnelle**

### Actions

| # | Action | Fichiers impactés | Effort |
|---|--------|-------------------|--------|
| 5.1 | Métriques Prometheus (exports) | Nouveau endpoint `/metrics` | 8h |
| 5.2 | Dashboard Grafana (templates) | Config Grafana | 8h |
| 5.3 | Structured logging (JSON) | `config/logging.php` + formatter | 4h |
| 5.4 | Correlation IDs (request tracing) | Middleware + logger | 4h |
| 5.5 | Dead-letter queue dashboard (admin) | Backend + frontend | 8h |
| 5.6 | Scheduler cockpit V2 (realtime) | Backend SSE + frontend | 12h |
| 5.7 | Uptime monitoring externe | Healthchecks.io / UptimeRobot | 2h |
| 5.8 | Runbook documentation | `docs/runbooks/` | 8h |
| 5.9 | Secret management (vault ou env chiffré) | Config + deploy | 8h |
| 5.10 | Disaster recovery plan + test | Documentation + script | 8h |

### Risques
- 5.1-5.2 (Prometheus/Grafana) nécessite du compute supplémentaire sur VPS ou service externe
- 5.9 (secret management) nécessite choix d'outil (HashiCorp Vault, AWS Secrets Manager, ou Laravel encrypted env)

### Stratégie
- 5.7 immédiatement (5 minutes, valeur immédiate)
- 5.3 + 5.4 ensemble (prerequis pour les autres)
- 5.1 + 5.2 : évaluer Grafana Cloud (gratuit jusqu'à 10k metrics)
- 5.8 + 5.10 en parallèle du reste

**Livrable : monitoring complet, alerting proactif, incident response documenté.**

---

# ANNEXE — Résumé Exécutif

## Avant V2

| Aspect | État |
|--------|------|
| Déployabilité | Destructif (`migrate:fresh`) |
| Tests CI | Aucun |
| Monitoring | Zéro |
| Realtime | Plafonné, fragile |
| UX | Blink systémique |
| Queue | Database, lente |
| Backup | Aucun |
| Multi-tenant | Isolation par convention |

## Après V2

| Aspect | État cible |
|--------|-----------|
| Déployabilité | Safe, rollback, health checks |
| Tests CI | PHPUnit + build validation |
| Monitoring | Sentry + Prometheus + Grafana + Slack |
| Realtime | Redis Streams, durable, cursor-based |
| UX | Zero-blink, optimistic, feedback progressif |
| Queue | Redis, 4 queues séparées, circuit breaker |
| Backup | Quotidien + offsite |
| Multi-tenant | Isolation par construction (global scopes) |

## Timeline

```
Semaines 1-2  : Phase 1 — Stabilisation Critique
Semaines 3-5  : Phase 2 — Realtime Propre
Semaines 6-8  : Phase 3 — UX SaaS Premium
Semaines 9-11 : Phase 4 — Performance & AI
Semaines 12-14: Phase 5 — Observabilité & Ops
```

**Effort total estimé : ~350h de développement sur 14 semaines.**

**Résultat : un SaaS commercial-grade, stable, prédictible, observable, avec une UX premium.**
