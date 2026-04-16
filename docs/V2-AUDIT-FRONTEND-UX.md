# AUDIT FRONTEND / UX / PRODUIT — VISION V2

> Date : 2026-04-10 | Scope : Frontend exclusif — Pages réelles, Stores Pinia, Realtime UX, AI UX, Design System
> Méthode : Lecture exhaustive du code source de 15+ pages Vue, 40+ stores, système realtime complet

---

# 1. NOUVEAUX PROBLÈMES (FRONTEND / UX / PRODUIT UNIQUEMENT)

## P0 — Non vendable

| # | Problème | Page/Fichier | Cause racine | Impact utilisateur |
|---|----------|-------------|--------------|-------------------|
| **UX-P0-1** | AI : zéro feedback pendant 30 secondes | `_DocumentsRequests.vue` L467 | Toast disparaît après 4s, aucun progress bar, polling silencieux 5s | Admin upload un doc → écran figé 30s → pense que c'est cassé |
| **UX-P0-2** | Toast single-instance : 2e écrase le 1er | `useAppToast.js` | Un seul `state.message` global | Upload: toast "success" immédiatement écrasé par toast "AI processing" → user ne voit que le 2e |
| **UX-P0-3** | Aucune error boundary globale | Toutes les pages | `onMounted` sans try/catch | API timeout au mount → white screen ou freeze, aucun message d'erreur |
| **UX-P0-4** | Race condition SSE + API polling en parallèle | `_DocumentsRequests.vue` L520-528 | Polling 5s actif PENDANT que SSE fonctionne | Double requêtes, données incohérentes, état blink si timing malheureux |

## P1 — UX dégradée

| # | Problème | Page/Fichier | Cause racine | Impact utilisateur |
|---|----------|-------------|--------------|-------------------|
| **UX-P1-1** | 37/40 stores : overwrite brut au lieu de smart merge | `companyMembers.js`, `platformUsers.js`, 35 autres | `this._items = data` direct | Polling/refetch → reactivity trigger → re-render table → perte scroll, perte sélection |
| **UX-P1-2** | Drawers fetchent APRÈS ouverture | `members/index.vue` L166 | `isDrawerOpen = true` puis `await fetch()` | Drawer s'ouvre vide (skeleton 500ms-2s) puis contenu apparaît |
| **UX-P1-3** | Optimistic updates absents partout | Toutes les CRUD (documents, members, roles) | Pattern "await API → then update UI" | Approve/reject/delete : 1-3s d'attente visible avant feedback |
| **UX-P1-4** | Empty states inconsistants | `members/index.vue` (absent), `documents` (présent) | Pas de standard, chaque page réinvente | 0 members → tableau vide sans message ni CTA |
| **UX-P1-5** | Loading states : 4 patterns différents | Skeleton, VCard:loading, spinner, progress | Pas de composable centralisé | Même app, 4 styles de chargement différents → impression d'incohérence |
| **UX-P1-6** | Aucun indicateur SSE connected/disconnected | `RealtimeClient.js` L107 | `_connected` existe mais pas réactif/exposé | User ne sait jamais s'il voit des données live ou stale |
| **UX-P1-7** | Seulement 3 pages consomment le realtime | Documents, MemberWorkflow, Automations | SSE infrastructure existante mais non adoptée | Billing, members, support, audit : pas de live updates |
| **UX-P1-8** | DomainEventBus sans dedup globale | `DomainEventBus.js` L33 | Chaque handler doit implémenter sa propre dedup | Event arrive 2x en 50ms → double mutation possible |
| **UX-P1-9** | AI cockpit platform trop minimaliste | `_AiUsageTab.vue` | 4 KPI basiques seulement | Admin ne peut pas diagnostiquer : queue depth, error types, cost, confidence distribution absents |
| **UX-P1-10** | Drawers sans dirty-check | Tous les drawers (roles, members, documents) | Pas de comparaison form data vs initial | User modifie un formulaire, click outside → perd tout sans warning |

## P2 — Amélioration nécessaire

| # | Problème | Page/Fichier | Cause racine |
|---|----------|-------------|--------------|
| **UX-P2-1** | Pas de breadcrumbs | Navigation globale | Non implémenté |
| **UX-P2-2** | Pas de keyboard shortcuts (Enter=save, Esc=cancel) | Drawers et dialogs | Non implémenté |
| **UX-P2-3** | Tables : pas de sticky header, pas d'inline edit | VDataTable usage | Features non activées |
| **UX-P2-4** | Forms : validation uniquement au submit, pas au blur | AppTextField usage | Pas de validation realtime |
| **UX-P2-5** | Confirmation dialogs sans styling destructif (bouton rouge) | `useConfirm()` | Template générique |
| **UX-P2-6** | i18n arabe : 266/4400 keys | `ar.json` | Feature non terminée ou abandonnée |
| **UX-P2-7** | Pas de persist UI state (filtres, tri, scroll) | Toutes les listes | localStorage non utilisé |
| **UX-P2-8** | AI suggestions sans explication du score | `DocumentViewerDialog.vue` | Confidence affichée mais pas contextualisée |
| **UX-P2-9** | Pas d'animation/transition sur mutations | CRUD partout | Pas de CSS transitions |
| **UX-P2-10** | Platform audit + security : realtime préparé mais non activé | `AuditHandler.js`, `SecurityHandler.js` | Handlers exist, aucune page ne consomme |

---

# 2. UX FAILURES CRITIQUES — Ce qui rend le produit non vendable

## 2.1 Le Silence AI (30 secondes de néant)

**Scénario réel** : Admin upload un document pour analyse AI.

```
T+0s    : Drop file → toast "AI processing in background" (4s)
T+4s    : Toast disparaît. RIEN.
T+5s    : Rien.
T+10s   : Rien.
T+15s   : Rien. Admin se demande si ça marche.
T+20s   : Rien.
T+25s   : Rien. Admin rafraîchit la page ? Clique ailleurs ?
T+30s   : DocumentAiChip passe de "pending" à "Analyse plus longue que prévu..."
T+45s   : Chip affiche "70% confidence"
```

**Comparaison Stripe/Notion** :
```
T+0s    : Drop file → progress bar inline 0%
T+500ms : "Initializing analysis..." 15%
T+2s    : "Extracting text..." 40%
T+5s    : "Analyzing document..." 75%
T+10s   : "95% — Almost done..."
T+15s   : "Complete — 70% confidence" + "Apply suggestions" button
```

**Verdict** : 30 secondes sans feedback = UX de prototype, pas de SaaS.

---

## 2.2 Le Blink Store (re-render invisible)

**Scénario réel** : Admin consulte la liste des members pendant que le polling tourne.

```
T+0s     : Table affichée, admin scrolle, sélectionne une row
T+15s    : Polling silencieux → store._members = newData (overwrite)
           Vue reactivity trigger → table re-render
           Scroll position perdue, sélection perdue
           Blink visible (~100ms de flash)
T+30s    : Re-blink
T+45s    : Re-blink
```

**Pourquoi** : `this._members = data.data` (overwrite brut) au lieu de smart merge.

**Stores affectés** (37/40) :
- `companyBilling.js` — direct assign
- `companyMembers.js` — Array.push sans dedup
- `platformUsers.js` — partial smart update, pas JSON.stringify
- `platformBillingSubscriptions.js` — full refetch on every action
- `platformBillingInvoices.js` — direct assign
- `platformAiProviders.js` — refetch boucle
- `shipment.js`, `delivery.js` — direct assign

**Stores exemplaires** (3/40) :
- `notification.js` — event_uuid dedup, ring buffer, JSON.stringify
- `companyDocuments.js` — `_mergeRequests()` avec JSON.stringify compare
- `automations.js` — `_mergeTasks()` avec JSON.stringify

---

## 2.3 L'Error Silence (white screen)

**Scénario réel** : API timeout au chargement d'une page.

```
onMounted(async () => {
  isLoading.value = true
  await store.fetchMembers()  // ← timeout, exception thrown
  isLoading.value = false     // ← jamais atteint
})
```

**Résultat** : `isLoading` reste `true` → skeleton infinie. Ou exception non catchée → white screen.

**Pages sans try/catch au mount** :
- `members/index.vue` — fetchMembers, fetchMemberProfile
- `support/index.vue` — fetchTickets
- `billing/_BillingOverview.vue` — fetchOverview
- `platform/access/_UsersTab.vue` — fetchPlatformUsers
- La majorité des pages

**Exception** : `platform/index.vue` utilise `Promise.allSettled()` → gère les erreurs partielles. C'est le **seul champion**.

---

## 2.4 Le Double Toast (information perdue)

```javascript
// _DocumentsRequests.vue L466-467
toast(t('documents.uploadForMemberSuccess'), 'success')  // "Upload réussi"
toast(t('documents.aiProcessingToast'), 'info')           // Écrase le précédent !
```

`useAppToast()` = singleton global, un seul message à la fois. Le 2e `toast()` remplace le 1er avant que l'utilisateur n'ait le temps de le lire.

**Impact** : L'utilisateur ne voit jamais la confirmation "Upload réussi".

---

## 2.5 Le Drawer Vide (skeleton avant contenu)

```javascript
// members/index.vue L166
const openFieldsDrawer = async () => {
  isFieldsDrawerOpen.value = true    // 1. Ouvre le drawer
  fieldsLoading.value = true
  await Promise.all([...])           // 2. PUIS fetch les données
  fieldsLoading.value = false
}
```

L'utilisateur voit : drawer s'ouvre → skeleton/spinner 500ms-2s → contenu apparaît.

**SaaS grade** : fetch d'abord, ouvre ensuite (ou prefetch lazy).

---

## 2.6 La Table Qui Attend (pas d'optimistic)

```javascript
// _DocumentsRequests.vue
const handleApprove = async (request) => {
  await $api('.../review', { method: 'PUT', body: { status: 'approved' } })
  // ↑ 1-3 secondes d'attente visible
  toast('Approved', 'success')
  await store.fetchRequests()  // Re-fetch entire list
}
```

**Stripe/Linear** : mise à jour immédiate de la row + revert si erreur.

---

# 3. VISION V2 FRONTEND

## 3.1 UX RULES (NON NÉGOCIABLES)

### Règle 1 : Zero Blink

> **Aucune opération ne doit causer de re-render visible non intentionnel.**

| Interdit | Obligatoire |
|----------|-------------|
| `this._items = newData` (overwrite) | `this._mergeItems(newData)` (smart merge) |
| Skeleton à chaque polling cycle | `{ silent: true }` flag, merge sans re-render |
| Full table reload après mutation | Row-level patch (`Object.assign(existing, delta)`) |
| Drawer qui s'ouvre vide | Prefetch data AVANT open |

### Règle 2 : Zero Full Refresh

> **Aucune action utilisateur ne doit déclencher un rechargement complet de la page ou de la liste.**

| Interdit | Obligatoire |
|----------|-------------|
| `await store.fetchAll()` après un create | Ajouter l'item localement + toast |
| `router.go(0)` ou `location.reload()` | Mutation ciblée du store |
| Re-mount du composant pour rafraîchir | Composable `refresh()` avec merge |

### Règle 3 : Update Granulaire Uniquement

> **Les mises à jour (API, SSE, polling) ne touchent que les lignes/champs concernés.**

```javascript
// ❌ INTERDIT
this._requests = await fetchAll()

// ✅ OBLIGATOIRE
const updated = await fetchAll()
this._mergeRequests(updated)  // Compare, patch changed only
```

### Règle 4 : Jamais de Skeleton Après Premier Load

> **Le skeleton n'apparaît qu'au chargement initial. Tout refresh ultérieur est invisible ou subtil.**

| Contexte | Pattern |
|----------|---------|
| Premier chargement page | `VSkeletonLoader` acceptable |
| Retour sur page déjà visitée | Hold-old-UI + refresh silencieux |
| Polling / SSE update | Merge invisible, aucun skeleton |
| Erreur puis retry | Overlay léger sur données existantes |

### Règle 5 : Feedback Systématique

> **L'utilisateur sait TOUJOURS ce qui se passe.**

| Action | Feedback immédiat | Feedback résultat |
|--------|-------------------|-------------------|
| Click bouton | Bouton `:loading` + disabled | Toast success/error |
| Upload fichier | Progress bar 0→100% | Toast + badge statut |
| AI processing | Progress bar estimé + étapes textuelles | Confiance + suggestions |
| Erreur réseau | Toast error avec "Retry" button | — |
| Drawer save | Bouton loading | Toast + drawer ferme |
| Delete | Confirm dialog | Toast "Undo" (5s) |

---

## 3.2 STATE RULES

### Règle S1 : Smart Merge Obligatoire Partout

Chaque store qui gère une collection DOIT implémenter :

```javascript
_merge(incoming) {
  if (this._items.length === 0) {
    this._items = incoming
    return
  }
  const existingById = new Map(this._items.map(r => [r.id, r]))
  const merged = incoming.map(item => {
    const existing = existingById.get(item.id)
    if (existing && JSON.stringify(existing) !== JSON.stringify(item)) {
      Object.assign(existing, item)
    }
    return existing ?? item
  })
  if (merged.length !== this._items.length ||
      merged.some((r, i) => r.id !== this._items[i]?.id)) {
    this._items = merged
  }
}
```

**Conformité actuelle** : 3/40 stores (7.5%)
**Cible V2** : 35/40 stores (87.5%)

### Règle S2 : Aucune Mutation Brutale

| Interdit | Obligatoire |
|----------|-------------|
| `this._items = []` puis `this._items = newData` | `this._merge(newData)` |
| `this._items.splice(0)` | `this._items = this._items.filter(...)` |
| `delete this._items[idx]` | `this._removeById(id)` |

### Règle S3 : Stores Idempotents

Appeler `store.fetch()` N fois doit produire le même résultat sans side-effect :

```javascript
async fetch({ silent = false } = {}) {
  if (this._fetching) return this._fetchPromise  // Dedup concurrent calls
  this._fetching = true
  if (!silent) this._loading = true

  try {
    this._fetchPromise = $api('/items')
    const data = await this._fetchPromise
    this._merge(data.items)
    this._error = null
  } catch (e) {
    this._error = e.message
  } finally {
    this._fetching = false
    if (!silent) this._loading = false
  }
}
```

### Règle S4 : Silent Mode Obligatoire pour Polling

```javascript
// Initial load : montre skeleton
await store.fetch()  // silent=false (default)

// Polling : invisible
setInterval(() => store.fetch({ silent: true }), 15000)
```

### Règle S5 : SSE Deduplication par event_uuid

```javascript
_push(envelope) {
  const uuid = envelope?.payload?.event_uuid
  if (uuid && this._uuids.has(uuid)) return
  if (uuid) this._uuids.add(uuid)
  // ... rest
}
```

### Règle S6 : Error State Toujours Exposé

```javascript
// Chaque store expose :
get error()   { return this._error }     // String ou null
get loading() { return this._loading }   // Boolean
get loaded()  { return this._loaded }    // Boolean (au moins 1 fetch réussi)
```

---

## 3.3 REALTIME UX

### Architecture Cible

```
SSE Connection
  │
  ├─ Connected → badge vert (navbar)
  ├─ Reconnecting → badge orange (navbar)
  └─ Offline/Polling → badge rouge (navbar) + tooltip "Last update: Xs ago"
```

### Règle R1 : SSE Prioritaire, Polling Fallback Mutually Exclusive

```javascript
// ❌ INTERDIT : SSE + polling en parallèle
if (hasProcessingAi) startPolling(5s)  // même si SSE actif

// ✅ OBLIGATOIRE : exclusion mutuelle
if (hasProcessingAi && !realtimeConnected) startPolling(5s)
if (realtimeConnected) stopPolling()
```

### Règle R2 : Update Par Ligne (Row-Level)

```javascript
// ❌ INTERDIT
handleSSEEvent() { await store.fetchAll() }

// ✅ OBLIGATOIRE
handleSSEEvent(payload) {
  if (payload.type === 'updated') {
    store.patchItem(payload.id, payload.changes)
  }
  else if (payload.type === 'created') {
    store.addItem(payload.item)
  }
  else if (payload.type === 'deleted') {
    store.removeItem(payload.id)
  }
}
```

### Règle R3 : Version-Aware Updates

```javascript
// Backend envoie: { id, version, changed_at, changes }
// Frontend compare avant patch :
patchItem(id, incoming) {
  const existing = this._items.find(i => i.id === id)
  if (!existing) return
  if (incoming.version <= existing.version) return  // Stale, ignore
  Object.assign(existing, incoming.changes)
  existing.version = incoming.version
}
```

### Règle R4 : Indicateur Connexion Visible

Composable `useRealtimeStatus()` :

```javascript
export function useRealtimeStatus() {
  const status = ref('connecting')  // 'live' | 'reconnecting' | 'offline'
  const lastEventAt = ref(null)

  // Écoute les changements du journal runtime
  // → 'realtime:connected' → status = 'live'
  // → 'realtime:fallback' → status = 'offline'
  // → 'realtime:reconnecting' → status = 'reconnecting'

  return { status, lastEventAt }
}
```

Badge navbar :
```vue
<VIcon
  :icon="status === 'live' ? 'tabler-broadcast' : 'tabler-wifi-off'"
  :color="status === 'live' ? 'success' : status === 'reconnecting' ? 'warning' : 'error'"
  size="18"
/>
```

### Pages à SSE-ifier (actuellement sans realtime)

| Page | Topic SSE à ajouter | Type update |
|------|---------------------|-------------|
| company/billing | `billing.payment_received`, `subscription.changed` | Row patch |
| company/members | `member.invited`, `member.role_changed` | Row patch |
| company/support | `ticket.replied`, `ticket.status_changed` | Row patch + toast |
| platform/billing | `billing.*` (toutes mutations) | Row patch |
| platform/companies | `company.created`, `company.suspended` | Row patch |
| platform/audit | `audit.event` | Push (déjà handler prêt) |

---

## 3.4 AI UX

### Objectif : Feedback en < 500ms, progress visible, fallback propre

### Pattern `useAiProcessing` :

```javascript
export function useAiProcessing() {
  const elapsed = ref(0)
  const isProcessing = ref(false)

  const progress = computed(() => {
    if (elapsed.value < 5000)  return (elapsed.value / 5000) * 80
    if (elapsed.value < 15000) return 80 + ((elapsed.value - 5000) / 10000) * 10
    if (elapsed.value < 30000) return 90 + ((elapsed.value - 15000) / 15000) * 5
    return 99  // Cap at 99% until real completion
  })

  const phase = computed(() => {
    if (elapsed.value < 3000)  return 'initializing'
    if (elapsed.value < 10000) return 'extracting'
    if (elapsed.value < 30000) return 'analyzing'
    if (elapsed.value < 60000) return 'slow'
    return 'timeout'
  })

  return { elapsed, isProcessing, progress, phase }
}
```

### Timeline UX Cible :

```
T+0ms     : Upload accepté → progress bar inline apparaît
            Phase: "Initializing..." (0-80%)
T+3s      : Phase: "Extracting text..." (80%)
T+10s     : Phase: "Analyzing document..." (80-90%)
T+30s     : Phase: "Taking longer than expected..." (warning color, 90-95%)
            → Bouton "Cancel" visible
T+60s     : Phase: "Timeout — analysis may have failed"
            → Bouton "Retry" visible
            → Message: "You can continue manually"
T+complete: SSE event → progress 100% → résultats affichés
            → Badge confiance (couleur)
            → Bouton "Apply suggestions" (1-click)
```

### AI Error UX :

```
Erreur générique :
  "Analysis failed — Document may be unclear or damaged"
  [Retry] [Upload different file] [Continue manually]

Erreur spécifique (si backend le fournit) :
  "Image too blurry for text extraction (confidence: 12%)"
  [Retry with better scan] [Continue manually]

Timeout :
  "Analysis is taking too long. This usually means heavy load."
  [Retry] [Continue without AI]
```

### AI Suggestions UX :

| Actuel | Cible V2 |
|--------|----------|
| Confiance % seul | Confiance + source ("Extracted from MRZ" / "AI Vision" / "OCR") |
| Accept/Reject seul | Accept / Accept with edit / Reject + reason |
| Pas d'historique | Audit trail : "AI suggested X, admin accepted/rejected" |
| 1-click apply all | Apply all + selective apply (checkboxes) |

---

## 3.5 DESIGN SYSTEM V2 — Composables & Composants Standards

### Composables Fondation (à créer)

| Composable | Rôle | Remplace |
|------------|------|----------|
| `usePageData(fetchFn)` | Load + error + retry + skeleton control | `ref('isLoading')` ad hoc |
| `useSmartCollection(key)` | Smart merge + patch + remove | `this._items = data` |
| `useOptimistic(action)` | Execute + rollback on error | `await api(); toast()` |
| `useServerTable(endpoint)` | Pagination + tri + filtres server-side | VDataTableServer ad hoc |
| `useToastQueue()` | Multi-toast, actions, durées adaptées | `useAppToast()` singleton |
| `useAiProcessing()` | Progress estimé + phases + timeout | `DocumentAiChip` hardcodé |
| `useRealtimeStatus()` | Indicateur connexion SSE | Rien (absent) |
| `useDirtyForm(initial)` | Dirty check + confirm before close | Rien (absent) |

### Composants Standards (à créer)

| Composant | Rôle |
|-----------|------|
| `LoadingState` | Wrapper : skeleton si loading, error+retry si error, slot si loaded |
| `EmptyState` | Icon + titre + subtitle + CTA — standard pour toute liste vide |
| `ToastStack` | Multi-snackbar empilé avec actions et close |
| `AsyncDrawer` | VNavigationDrawer + dirty check + prefetch + footer actions |
| `AiProgressCard` | Progress bar + phases + timeout + retry |
| `RealtimeIndicator` | Badge navbar SSE status |

### Hiérarchie Loading (stricte)

| Niveau | Composant | Quand |
|--------|-----------|-------|
| Route transition | `AppLoadingIndicator` (top bar) | Changement de page |
| Page mount | `VSkeletonLoader` type=card/table | Premier load uniquement |
| Section refresh | `LoadingState` (opacity 50%) | Re-fetch silencieux |
| Action bouton | `VBtn :loading` | Click save/delete/approve |
| Long process (AI) | `VProgressLinear` + text étapes | > 2 secondes |
| Polling silencieux | **Rien du tout** | Merge invisible |

### Toasts — Nouvelle Hiérarchie

| Type | Couleur | Durée | Action |
|------|---------|-------|--------|
| Success | `success` | 4s | — |
| Info | `info` | 4s | — |
| Warning | `warning` | 5s | Optional |
| Error | `error` | 6s + no auto-close si critical | "Retry" button |
| Loading | `info` | Pas d'auto-close | Close quand terminé |

### Empty States — Template

```vue
<EmptyState
  icon="tabler-{contextual-icon}"
  :title="t('module.noDataTitle')"
  :subtitle="t('module.noDataSubtitle')"
>
  <template #action>
    <VBtn color="primary" prepend-icon="tabler-plus" @click="create">
      {{ t('module.createFirst') }}
    </VBtn>
  </template>
</EmptyState>
```

---

# 4. PLAN V2 FRONTEND (PHASES)

## Phase UX-1 : Stabilisation Anti-Blink (Semaine 1-2)

> **Objectif** : Éliminer 100% des blinks, re-renders et silences.

| # | Action | Fichiers | Effort | Impact |
|---|--------|----------|--------|--------|
| 1.1 | Créer composable `useSmartCollection()` | Nouveau | 4h | Fondation |
| 1.2 | Migrer `companyMembers.js` → smart merge | 1 store | 2h | Members table stable |
| 1.3 | Migrer `companyBilling.js` → smart merge | 1 store | 2h | Billing table stable |
| 1.4 | Migrer `platformUsers.js` → smart merge | 1 store | 2h | Users table stable |
| 1.5 | Migrer `platformBillingSubscriptions.js` → smart merge | 1 store | 2h | Subs table stable |
| 1.6 | Migrer `platformBillingInvoices.js` → smart merge | 1 store | 2h | Invoices table stable |
| 1.7 | Migrer `platformAiProviders.js` → targeted update | 1 store | 2h | Kill refetch loop |
| 1.8 | Migrer `shipment.js`, `delivery.js` → smart merge | 2 stores | 3h | Logistics stable |
| 1.9 | Ajouter silent mode à tous les stores avec polling | 10+ stores | 4h | Zero skeleton sur polling |
| 1.10 | Ajouter try/catch + error state à tous les `onMounted` | 15+ pages | 8h | Zero white screen |
| 1.11 | Fix drawers : prefetch AVANT open | members, documents | 3h | Zero drawer skeleton |
| 1.12 | Fix polling+SSE : exclusion mutuelle | `_DocumentsRequests.vue` | 2h | Zero double request |

**Livrables** : Toutes les tables stables, aucun blink, aucun white screen.
**Tests** : Navigation rapide entre pages, polling actif, vérifier 0 flash visible.

---

## Phase UX-2 : Realtime Propre (Semaine 3-4)

> **Objectif** : SSE consommé partout, feedback de connexion visible.

| # | Action | Fichiers | Effort | Impact |
|---|--------|----------|--------|--------|
| 2.1 | Créer `useRealtimeStatus()` composable | Nouveau | 2h | Fondation |
| 2.2 | Ajouter `RealtimeIndicator` badge navbar | Component navbar | 2h | User sait si live |
| 2.3 | Ajouter version/timestamp sur domain events backend | `EventEnvelope.php` | 4h | Race condition fix |
| 2.4 | Frontend : version-aware merge dans stores | Stores documents, billing | 4h | Stale data rejectée |
| 2.5 | SSE-ifier billing (company + platform) | 2 pages + 2 stores | 8h | Billing live |
| 2.6 | SSE-ifier members | 1 page + 1 store | 4h | Members live |
| 2.7 | SSE-ifier support | 1 page + 1 store | 4h | Tickets live |
| 2.8 | Activer platform audit live | 1 page (handler existe déjà) | 4h | Audit realtime |
| 2.9 | DomainEventBus : dedup globale par event_id | `DomainEventBus.js` | 2h | Zero duplicate events |
| 2.10 | Error boundary dans ChannelRouter dispatch | `runtime.js` | 1h | SSE ne crash pas sur erreur handler |

**Livrables** : SSE consommé sur 8+ pages, indicateur connexion visible, dedup globale.
**Tests** : Couper réseau → vérifier badge orange → reconnecter → badge vert. Tester race conditions.

---

## Phase UX-3 : Design System (Semaine 5-7)

> **Objectif** : Patterns UX unifiés, composants standards, feedback cohérent.

| # | Action | Fichiers | Effort | Impact |
|---|--------|----------|--------|--------|
| 3.1 | Créer `useToastQueue()` + `ToastStack` component | 2 fichiers | 8h | Multi-toast, actions |
| 3.2 | Migrer toutes les pages vers `useToastQueue()` | 15+ pages | 4h | Zero toast écrasé |
| 3.3 | Créer `LoadingState` component | 1 composant | 4h | Loading unifié |
| 3.4 | Créer `EmptyState` component | 1 composant | 2h | Empty unifié |
| 3.5 | Appliquer `EmptyState` sur toutes les listes | 12+ pages | 6h | Zero tableau vide silencieux |
| 3.6 | Créer `AsyncDrawer` avec dirty-check | 1 composant | 8h | Drawers safe |
| 3.7 | Migrer drawers existants vers `AsyncDrawer` | 8+ drawers | 8h | Cohérence drawers |
| 3.8 | Créer `useOptimistic()` composable | 1 composable | 4h | Fondation optimistic |
| 3.9 | Implémenter optimistic updates : documents approve/reject | 1 page | 4h | CRUD instantané |
| 3.10 | Implémenter optimistic updates : members add/remove | 1 page | 4h | CRUD instantané |
| 3.11 | Créer `useServerTable()` composable | 1 composable | 8h | Tables unifiées |
| 3.12 | Migrer tables client-side vers server pagination | 6+ tables | 12h | Scalable > 1000 rows |
| 3.13 | Documenter Design System dans `docs/bmad/08-design-system-v2.md` | 1 doc | 4h | Référence BMAD |

**Livrables** : 7 composables + 4 composants standards. Toutes les pages conformes.
**Tests** : Checklist UX par page (loading, error, empty, feedback, drawer).

---

## Phase UX-4 : AI UX Premium (Semaine 8-9)

> **Objectif** : Expérience AI niveau Stripe Radar / Notion AI.

| # | Action | Fichiers | Effort | Impact |
|---|--------|----------|--------|--------|
| 4.1 | Créer `useAiProcessing()` composable | Nouveau | 4h | Progress estimé |
| 4.2 | Redesign `DocumentAiChip` avec progress bar + phases | 1 composant | 8h | Feedback immédiat |
| 4.3 | Backend : SSE events granulaires AI (started/progress/completed/failed) | 2 fichiers PHP | 8h | Events précis |
| 4.4 | Frontend : consommer events AI granulaires | 1 page + 1 store | 4h | Progress realtime |
| 4.5 | AI error messages contextuels | `DocumentViewerDialog.vue` | 4h | "Image floue" vs "Erreur" |
| 4.6 | AI suggestions : source affichée (MRZ/Vision/OCR) | `DocumentViewerDialog.vue` | 4h | Transparence |
| 4.7 | AI suggestions : "Apply with edit" option | 1 composant | 4h | Flexibilité |
| 4.8 | Platform AI cockpit : queue depth, error types, cost, confidence chart | 4 widgets | 16h | Monitoring premium |
| 4.9 | AI timeout UX : retry + "continue manually" | `_DocumentsRequests.vue` | 4h | Fallback propre |

**Livrables** : AI avec feedback < 500ms, progress visible, erreurs contextuelles, cockpit complet.
**Tests** : Upload doc → vérifier progress bar visible en < 500ms. Simuler timeout → vérifier retry visible.

---

## Récapitulatif Effort

| Phase | Semaines | Effort | Résultat |
|-------|----------|--------|----------|
| UX-1 : Anti-Blink | 1-2 | ~38h | Tables stables, zero white screen |
| UX-2 : Realtime | 3-4 | ~35h | SSE partout, indicateur visible |
| UX-3 : Design System | 5-7 | ~76h | Composants standards, UX cohérente |
| UX-4 : AI Premium | 8-9 | ~56h | AI vendable, feedback instantané |
| **Total** | **9 semaines** | **~205h** | **SaaS grade (9/10)** |

---

## Tableau de Score — Avant/Après

| Critère | Avant | Après V2 | Référence |
|---------|-------|----------|-----------|
| Anti-blink | 3/40 stores (7%) | 35/40 (87%) | Notion/Linear |
| Error handling | 5/40 stores (12%) | 38/40 (95%) | Stripe |
| Optimistic updates | 0 pages | 8+ pages | Linear |
| SSE consommation | 3 pages | 10+ pages | Slack |
| Loading cohérence | 4 patterns différents | 1 hiérarchie stricte | Stripe |
| Toast multi-instance | Non | Oui (queue) | Notion |
| Empty states | Inconsistant | Standard partout | Linear |
| AI feedback | 30s de silence | < 500ms progress | Notion AI |
| Drawers dirty-check | Non | Oui partout | Google Docs |
| Indicateur SSE | Non | Badge navbar | Slack |
| Server pagination | Partiel | 100% des tables | Stripe |

**Score UX global : 5.5/10 → 9/10**
