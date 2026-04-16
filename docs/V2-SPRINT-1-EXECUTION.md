# V2 SPRINT 1 — PLAN D'EXÉCUTION

> Sprint fondation : zéro page blanche, zéro état silencieux.
> Durée cible : 2 semaines (10 jours ouvrés).

---

# OBJECTIFS DU SPRINT

1. **Tous les stores company-scope** respectent le standard `{ _loading, _error, _loaded, smartMerge, retry() }`
2. **Toutes les pages liste** ont skeleton → error → empty → data (les 4 états visibles)
3. **Composants transverses** créés : `PageSkeleton`, `ErrorBanner`, `RetryButton`
4. **Composants existants** déployés partout : `EmptyState`, `ErrorState`, `StatusChip`
5. **Composable `useListPage`** créé et appliqué sur au moins 2 pages

---

# INVENTAIRE DES FICHIERS

## A. Stores à standardiser (12 fichiers)

| # | Store | Path | Lignes | _loading | _error | _loaded | Smart merge | SSE |
|---|-------|------|--------|----------|--------|---------|-------------|-----|
| 1 | membersStore | `modules/company/members/members.store.js` | 187 | ❌ | ❌ | ❌ | ❌ | ❌ |
| 2 | billingStore | `modules/company/billing/billing.store.js` | 234 | ❌ | ❌ | ❌ | ❌ | ❌ |
| 3 | settingsStore | `modules/company/settings/settings.store.js` | 189 | ❌ | ❌ | ❌ | ❌ | ❌ |
| 4 | auditStore | `modules/company/audit/audit.store.js` | 27 | ❌ | ❌ | ❌ | ❌ | ❌ |
| 5 | shipmentStore | `modules/logistics-shipments/stores/shipment.store.js` | ~80 | ❌ | ❌ | ✅ | ❌ | ❌ |
| 6 | deliveryStore | `modules/logistics-shipments/stores/delivery.store.js` | 77 | ❌ | ❌ | ✅ | ❌ | ❌ |
| 7 | supportStore | `modules/company/support/support.store.js` | 78 | ✅ | ❌ | ❌ | ❌ | ❌ |
| 8 | complianceStore | `modules/company/dashboard/compliance.store.js` | 55 | ✅ | ❌ | ✅ | ❌ | ❌ |
| 9 | jobdomainStore | `modules/company/jobdomain/jobdomain.store.js` | 86 | ❌ | ❌ | ✅ | ❌ | ❌ |
| 10 | documentsStore | `modules/company/documents/documents.store.js` | 371 | ✅(obj) | ❌ | ❌ | ✅ | ✅ |
| 11 | homeStore | `modules/company/home/home.store.js` | 61 | ❌ | ❌ | ❌ | ❌ | ❌ |
| 12 | dashboardStore | `modules/company/dashboard/dashboard.store.js` | 130 | ❌ | ❌ | ❌ | ❌ | ❌ |

> Note : `documentsStore` est le modèle de référence (SSE + smart merge). Sprint V2-1 ajoute `_error` + `_loaded`.
> Les stores core (auth, nav, world, module) ne sont PAS dans ce sprint — ils sont gérés par le boot machine.

## B. Pages liste à mettre aux normes (8 pages)

| # | Page | Path | Store | Skeleton | Error | Empty |
|---|------|------|-------|----------|-------|-------|
| 1 | Members list | `pages/company/members/index.vue` | membersStore | ❌ | ❌ | ❌ |
| 2 | Shipments list | `pages/company/shipments/index.vue` | shipmentStore | ❌ | ❌ | ❌ |
| 3 | Deliveries list | `pages/company/my-deliveries/index.vue` | deliveryStore | ❌ | ❌ | ❌ |
| 4 | Support list | `pages/company/support/index.vue` | supportStore | ❌ | ❌ | ✅(custom) |
| 5 | Audit list | `pages/company/audit/index.vue` | auditStore | ❌ | ❌ | ❌ |
| 6 | Documents requests | `pages/company/documents/_DocumentsRequests.vue` | documentsStore | ❌ | ❌ | ❌ |
| 7 | Documents vault | `pages/company/documents/_DocumentsVault.vue` | documentsStore | ✅ | ❌ | ❌ |
| 8 | Billing invoices | `pages/company/billing/_BillingInvoices.vue` | billingStore | ❌ | ❌ | ❌ |

## C. Composants à créer (4 fichiers)

| # | Composant | Path cible | Description |
|---|-----------|-----------|-------------|
| 1 | PageSkeleton | `core/components/PageSkeleton.vue` | Skeleton réutilisable (types: list, detail, form, dashboard, cards) |
| 2 | ErrorBanner | `core/components/ErrorBanner.vue` | Banner inline erreur + retry (pas toast) |
| 3 | RetryButton | `core/components/RetryButton.vue` | Bouton "Réessayer" standardisé |
| 4 | useListPage | `composables/useListPage.js` | Composable pattern LIST complet |

## D. Composants existants à enrichir (2 fichiers)

| # | Composant | Path | Action |
|---|-----------|------|--------|
| 1 | StatusChip | `core/components/StatusChip.vue` | Ajouter domains: members, shipments, support, documents |
| 2 | EmptyState | `core/components/EmptyState.vue` | Vérifier les props, ajouter slot pour action custom |

---

# ORDRE D'IMPLÉMENTATION

## Phase 1 — Composants transverses (Jour 1-2)

### Étape 1.1 : Créer `PageSkeleton.vue`
```
Fichier : resources/js/core/components/PageSkeleton.vue
Props : type ('list' | 'detail' | 'form' | 'dashboard' | 'cards')
Rendu :
  - list → VSkeletonLoader type="table-heading, table-row@5"
  - detail → VSkeletonLoader type="card, list-item@3, paragraph@2"
  - form → VSkeletonLoader type="text@3, button"
  - dashboard → VSkeletonLoader type="card@4, table-heading, table-row@3"
  - cards → VSkeletonLoader type="card@6"
Critère : Le composant rend un skeleton adapté selon le type
```

### Étape 1.2 : Créer `ErrorBanner.vue`
```
Fichier : resources/js/core/components/ErrorBanner.vue
Props : message (string), retryLabel (string, default: t('common.retry'))
Emits : @retry
Rendu : VAlert type="error" prominent avec bouton Retry
Critère : Banner visible inline (pas toast, pas dialog)
```

### Étape 1.3 : Créer `RetryButton.vue`
```
Fichier : resources/js/core/components/RetryButton.vue
Props : label (string), loading (boolean)
Emits : @click
Rendu : VBtn variant="tonal" color="primary" avec icône refresh
Critère : Bouton utilisable standalone ou dans ErrorBanner
```

### Étape 1.4 : Enrichir `StatusChip.vue`
```
Fichier : resources/js/core/components/StatusChip.vue
Action : Ajouter 4 nouveaux domains au statusMap :
  - members: active, invited, suspended → success, info, error
  - shipments: draft, confirmed, picked_up, in_transit, delivered, cancelled → couleurs
  - support: open, in_progress, waiting_customer, resolved, closed → couleurs
  - documents: requested, submitted, approved, rejected, expired → couleurs
Critère : StatusChip fonctionne avec les 8 domains (4 existants + 4 nouveaux)
```

**Dépendances :** Aucune — peut commencer jour 1.

---

## Phase 2 — Stores standardisés (Jour 2-5)

### Ordre de traitement (du plus simple au plus complexe)

#### Étape 2.1 : auditStore (le plus simple — 27 lignes)
```
Fichier : modules/company/audit/audit.store.js
Ajouter : _loading: false, _error: null, _loaded: false
Wrapper : try/catch sur fetchEntries(), silent mode, retry()
Critère : store.fetchEntries() → _loading true → data ou _error
```

#### Étape 2.2 : homeStore (61 lignes)
```
Fichier : modules/company/home/home.store.js
Ajouter : _loading: false, _error: null, _loaded: false
Wrapper : try/catch, silent mode, retry()
```

#### Étape 2.3 : supportStore (78 lignes, déjà _loading)
```
Fichier : modules/company/support/support.store.js
Ajouter : _error: null, _loaded: false
Enrichir : try/catch sur les fetch existants, retry()
```

#### Étape 2.4 : complianceStore (55 lignes, déjà _loading + _loaded)
```
Fichier : modules/company/dashboard/compliance.store.js
Ajouter : _error: null
Enrichir : try/catch, retry()
```

#### Étape 2.5 : deliveryStore (77 lignes, déjà _loaded)
```
Fichier : modules/logistics-shipments/stores/delivery.store.js
Ajouter : _loading: false, _error: null
Enrichir : try/catch, silent mode, retry()
```

#### Étape 2.6 : shipmentStore (~80 lignes, déjà _loaded)
```
Fichier : modules/logistics-shipments/stores/shipment.store.js
Ajouter : _loading: false, _error: null
Enrichir : try/catch, silent mode, retry()
```

#### Étape 2.7 : jobdomainStore (86 lignes, déjà _loaded)
```
Fichier : modules/company/jobdomain/jobdomain.store.js
Ajouter : _loading: false, _error: null
Enrichir : try/catch, retry()
```

#### Étape 2.8 : membersStore (187 lignes)
```
Fichier : modules/company/members/members.store.js
Ajouter : _loading: false, _error: null, _loaded: false
Enrichir : try/catch sur tous les fetch, silent mode, retry(), smart merge
```

#### Étape 2.9 : settingsStore (189 lignes)
```
Fichier : modules/company/settings/settings.store.js
Ajouter : _loading: false, _error: null, _loaded: false
Enrichir : try/catch, retry()
```

#### Étape 2.10 : billingStore (234 lignes)
```
Fichier : modules/company/billing/billing.store.js
Ajouter : _loading: false (ou granulaire), _error: null, _loaded: false
Enrichir : try/catch, retry()
Attention : store complexe avec subscription, invoices, payments — loading granulaire possible
```

#### Étape 2.11 : dashboardStore (130 lignes)
```
Fichier : modules/company/dashboard/dashboard.store.js
Ajouter : _loading: false, _error: null, _loaded: false
Enrichir : try/catch, retry()
```

#### Étape 2.12 : documentsStore (371 lignes — enrichir seulement)
```
Fichier : modules/company/documents/documents.store.js
Ajouter : _error: null (ou granulaire par section), _loaded: false
Ne PAS toucher : SSE, smart merge (déjà OK)
Enrichir : try/catch avec _error sur chaque section, retry()
```

**Dépendances :** Aucune — indépendant des composants Phase 1.

---

## Phase 3 — Composable useListPage (Jour 4-5)

### Étape 3.1 : Créer `useListPage.js`
```
Fichier : resources/js/composables/useListPage.js

Signature :
  useListPage({
    store,                    // instance du store Pinia
    fetchAction: string,      // nom de l'action (ex: 'fetchMembers')
    defaultFilters: {},       // filtres par défaut
    searchDebounce: 400,      // délai debounce search
    defaultItemsPerPage: 10,  // items par page
  })

Retourne :
  items          — computed → store items
  isLoading      — computed → store._loading
  error          — computed → store._error
  isLoaded       — computed → store._loaded
  search         — ref
  filters        — reactive
  pagination     — reactive { page, itemsPerPage, sortBy, orderBy }
  selectedRows   — ref
  totalItems     — computed → store total count
  fetchList      — function (appelle store[fetchAction] avec state courant)
  retry          — function (store.retry())
  updateOptions  — function (handler VDataTableServer @update:options)
  isEmpty        — computed → isLoaded && items.length === 0

Comportement :
  - onMounted : fetchList()
  - watch search (debounced) : page=1 + fetchList()
  - watch filters : page=1 + fetchList()
  - watch pagination : fetchList()

Critère : Le composable encapsule 100% du boilerplate d'une page liste.
```

**Dépendances :** Phase 2 (stores doivent exposer _loading/_error/_loaded).

---

## Phase 4 — Pages liste aux normes (Jour 5-9)

### Pattern d'application (identique pour chaque page)

```
POUR CHAQUE PAGE LISTE :

1. Importer les composants V2 :
   import PageSkeleton from '@/core/components/PageSkeleton.vue'
   import ErrorBanner from '@/core/components/ErrorBanner.vue'
   import EmptyState from '@/core/components/EmptyState.vue'

2. Utiliser useListPage (si applicable) ou accéder au store directement

3. Structurer le template :
   <PageSkeleton v-if="!store._loaded && !store._error" type="list" />
   <ErrorBanner v-else-if="store._error" :message="store._error" @retry="store.retry()" />
   <VCard v-else>
     <!-- contenu existant -->
     <template #no-data>
       <EmptyState :title="..." :description="..." icon="..." @action="..." />
     </template>
   </VCard>

4. Remplacer isLoading local par store._loading
5. Remplacer les couleurs de statut hardcodées par StatusChip
6. Tester les 4 états : skeleton → error → empty → data
```

### Étape 4.1 : Audit list (la plus simple)
```
Fichier : pages/company/audit/index.vue
Store : auditStore (standardisé en 2.1)
Actions :
  - Ajouter PageSkeleton
  - Ajouter ErrorBanner
  - Ajouter EmptyState dans #no-data
  - Supprimer isLoading local → utiliser store._loading
Effort : 0.5 jour
```

### Étape 4.2 : Support list
```
Fichier : pages/company/support/index.vue
Store : supportStore (standardisé en 2.3)
Actions :
  - Ajouter PageSkeleton
  - Ajouter ErrorBanner
  - Remplacer EmptyState custom par le composant partagé
  - StatusChip domain="support" sur les statuts
Effort : 0.5 jour
```

### Étape 4.3 : Shipments list
```
Fichier : pages/company/shipments/index.vue
Store : shipmentStore (standardisé en 2.6)
Actions :
  - Ajouter PageSkeleton
  - Ajouter ErrorBanner
  - Ajouter EmptyState
  - StatusChip domain="shipments"
Effort : 0.5 jour
```

### Étape 4.4 : Deliveries list
```
Fichier : pages/company/my-deliveries/index.vue
Store : deliveryStore (standardisé en 2.5)
Actions : Mêmes que Shipments
Effort : 0.5 jour
```

### Étape 4.5 : Members list
```
Fichier : pages/company/members/index.vue
Store : membersStore (standardisé en 2.8)
Actions :
  - Ajouter PageSkeleton
  - Ajouter ErrorBanner
  - Ajouter EmptyState avec CTA "Inviter votre premier membre"
  - StatusChip domain="members"
Effort : 0.5 jour
```

### Étape 4.6 : Documents requests
```
Fichier : pages/company/documents/_DocumentsRequests.vue
Store : documentsStore (enrichi en 2.12)
Actions :
  - Ajouter skeleton conditionnel
  - Ajouter ErrorBanner
  - Ajouter EmptyState
  - StatusChip domain="documents"
Effort : 0.5 jour
```

### Étape 4.7 : Documents vault
```
Fichier : pages/company/documents/_DocumentsVault.vue
Store : documentsStore
Actions :
  - Skeleton déjà présent ✅
  - Ajouter ErrorBanner
  - Ajouter EmptyState si pas de documents
Effort : 0.25 jour
```

### Étape 4.8 : Billing invoices
```
Fichier : pages/company/billing/_BillingInvoices.vue
Store : billingStore (standardisé en 2.10)
Actions :
  - Ajouter PageSkeleton
  - Ajouter ErrorBanner
  - Ajouter EmptyState "Aucune facture"
  - StatusChip domain="invoice" (déjà existant — vérifier usage)
Effort : 0.5 jour
```

**Dépendances :** Phase 1 (composants) + Phase 2 (stores correspondants).

---

## Phase 5 — Validation & Documentation (Jour 9-10)

### Étape 5.1 : Tests visuels
```
Pour chaque page modifiée, tester manuellement :
  ☐ État SKELETON : couper l'API → vérifier le skeleton apparaît
  ☐ État ERROR : simuler erreur 500 → vérifier ErrorBanner + Retry
  ☐ État EMPTY : vider la DB locale → vérifier EmptyState + CTA
  ☐ État DATA : données normales → vérifier le rendu habituel
  ☐ StatusChip : vérifier les couleurs pour chaque statut du domain
  ☐ Retry : cliquer Retry après erreur → vérifier le rechargement
```

### Étape 5.2 : Tests automatisés
```
  ☐ php artisan test → suite complète verte
  ☐ pnpm build → build clean, zéro erreur
```

### Étape 5.3 : ADR
```
  Fichier : docs/bmad/04-decisions.md
  Ajouter ADR-XXX : Store Quality Standard V2
  Contenu :
    - Contexte : 79% des stores sans error state, 64% sans loading state
    - Décision : Standard obligatoire _loading/_error/_loaded + composants V2
    - Conséquences : 12 stores refactorés, 8 pages enrichies
    - Fichiers : liste des 20+ fichiers modifiés
```

---

# CRITÈRES D'ACCEPTATION

## Store standard V2
```
☐ state() contient _loading, _error, _loaded
☐ Tout fetch est wrappé try/catch
☐ _loading = true avant fetch, false dans finally
☐ _error = null avant fetch, e.message dans catch
☐ _loaded = true après premier fetch réussi
☐ Mode silent : fetchX({ silent: true }) ne touche pas _loading
☐ retry() est exposé et appelle le dernier fetch
```

## Page liste V2
```
☐ PageSkeleton visible au premier chargement
☐ ErrorBanner visible si store._error (avec bouton Retry)
☐ EmptyState visible si liste vide (avec CTA contextuel)
☐ Données visibles quand chargées normalement
☐ Pas de spinner plein page (skeleton uniquement)
☐ StatusChip au lieu de couleurs hardcodées
☐ VDataTableServer :loading="store._loading" pour les rechargements
```

## Composant V2
```
☐ Props typées et documentées
☐ i18n pour tous les textes
☐ Fonctionne en dark mode
☐ Responsive (pas de largeur cassée mobile)
```

---

# RISQUES

| Risque | Impact | Mitigation |
|--------|--------|-----------|
| Store refactoring casse des pages existantes | Élevé | Tester chaque store + page immédiatement après modification |
| `_loading` interfère avec les loading locaux des pages | Moyen | Supprimer les `isLoading` locaux au fur et à mesure |
| `documents.store` trop complexe pour ajouter `_error` | Moyen | Utiliser `_error` par section (comme `_loading`) |
| `billing.store` a des flux Stripe complexes | Moyen | Loading granulaire `{ overview: false, invoices: false }` |
| EmptyState pas adapté à tous les contextes | Faible | Vérifier les props existantes, ajouter slot si besoin |
| PageSkeleton pas assez varié | Faible | 5 types prédéfinis couvrent tous les layouts V2 |

---

# DEFINITION OF DONE

```
☐ 12 stores company-scope conformes au standard V2
☐ 8 pages liste avec les 4 états visibles (skeleton/error/empty/data)
☐ 4 composants créés (PageSkeleton, ErrorBanner, RetryButton, useListPage)
☐ StatusChip enrichi avec 4 domains supplémentaires
☐ EmptyState utilisé dans toutes les pages liste
☐ php artisan test → green
☐ pnpm build → clean
☐ ADR documenté dans 04-decisions.md
☐ Zéro page blanche dans l'application company
☐ Zéro état silencieux (tout erreur a un rendu visible)
```

---

# PLANNING JOUR PAR JOUR

| Jour | Phase | Tâches |
|------|-------|--------|
| J1 | Phase 1 | Créer PageSkeleton, ErrorBanner, RetryButton |
| J2 | Phase 1+2 | Enrichir StatusChip. Commencer stores (audit, home, support, compliance) |
| J3 | Phase 2 | Stores : delivery, shipment, jobdomain, members |
| J4 | Phase 2+3 | Stores : settings, billing, dashboard, documents. Commencer useListPage |
| J5 | Phase 3 | Finir useListPage. Tester |
| J6 | Phase 4 | Pages : audit, support, shipments, deliveries |
| J7 | Phase 4 | Pages : members, documents requests |
| J8 | Phase 4 | Pages : documents vault, billing invoices |
| J9 | Phase 5 | Tests visuels manuels (8 pages × 4 états). Fix bugs |
| J10 | Phase 5 | Tests auto, build, ADR. Buffer pour retards |
