# V2 DESIGN STANDARDS — Constitution Frontend Leezr

> Ce document est la loi commune du frontend V2.
> Chaque page, composant, store et interaction DOIT respecter ces standards.
> Toute déviation est un bug.

---

# TABLE DES MATIÈRES

1. [Spacing](#1-spacing)
2. [Density](#2-density)
3. [Tables](#3-tables)
4. [Drawers](#4-drawers)
5. [Modals / Dialogs](#5-modals--dialogs)
6. [Empty / Loading / Error States](#6-empty--loading--error-states)
7. [Feedback Async](#7-feedback-async)
8. [Animations](#8-animations)
9. [Realtime UX](#9-realtime-ux)
10. [Responsive Mobile](#10-responsive-mobile)
11. [Accessibilité minimale](#11-accessibilité-minimale)
12. [Collaboration multi-utilisateur](#12-collaboration-multi-utilisateur)

---

# 1. SPACING

## 1.1 Système de base

Vuetify utilise un système de spacing en multiples de 4px.

```
Unité de base : 4px
pa-1 = 4px    pa-2 = 8px    pa-3 = 12px   pa-4 = 16px
pa-5 = 20px   pa-6 = 24px   pa-8 = 32px   pa-10 = 40px
```

## 1.2 Règles V2

| Contexte | Spacing | Classe Vuetify |
|----------|---------|----------------|
| Entre sections de page | 24px | `mb-6` |
| Entre cards dans une grille | 24px | `card-grid` (automatique via VRow gap) |
| Padding intérieur d'une VCard | 16-20px | `pa-4` ou `pa-5` |
| Entre champs d'un formulaire | 16px | `mb-4` sur chaque row |
| Entre boutons dans une toolbar | 8px | `gap-2` |
| Entre un titre et son contenu | 12-16px | `mb-3` ou `mb-4` |
| Padding d'un drawer | 20px | `pa-5` |
| Padding d'un dialog | 24px | `pa-6` |

## 1.3 Card Grid (ADR-379 — obligatoire)

```html
<!-- TOUJOURS utiliser card-grid pour une grille de cards -->
<VRow class="card-grid card-grid-{size}">
  <VCol v-for="item in items" :key="item.id" cols="12" sm="6" md="4" lg="3">
    <VCard> ... </VCard>
  </VCol>
</VRow>
```

| Taille | Min-height | Usage |
|--------|-----------|-------|
| `xs` | 120px | KPI, stats compacts |
| `sm` | 180px | Info cards, rôles, topics |
| `md` | 280px | Plans, modules, profils |
| `lg` | 400px | Cards riches, détail |

**INTERDIT :** `h-100` manuel sur VCard — `card-grid` gère la hauteur automatiquement.

## 1.4 Marges de page

```
Page content : padding géré par le layout Vuexy (24px desktop, 16px mobile)
Pas de margin/padding additionnel sur le conteneur de page
Les VCard ajoutent leur propre padding interne
```

---

# 2. DENSITY

## 2.1 Règle générale

```
CONTEXTE ADMIN/GESTION : density="compact"
CONTEXTE LECTURE/CONSULTATION : density="default" ou "comfortable"
```

## 2.2 Application par composant

| Composant | Density | Justification |
|-----------|---------|---------------|
| VDataTableServer (listes principales) | `compact` | Maximiser les données visibles |
| VDataTableServer (sous-tables dans drawers) | `compact` | Espace contraint |
| VList (navigation, menus) | `compact` | Rapidité de scan |
| VList (notifications, activity feed) | `default` | Lisibilité du contenu riche |
| Formulaires (champs) | `default` | Zone de clic confortable |
| VTabs | `default` | Lisibilité |
| VTimeline | `compact` | Maximiser les événements visibles |
| VChip (filtres, tags) | `small` | Économie d'espace |

## 2.3 Tables — items par page

```
DEFAULT : 10 items par page
COMPACT (audit, logs) : 25 items par page
Options : [10, 25, 50, 100]
```

---

# 3. TABLES

## 3.1 Pattern obligatoire

Toute table de données V2 DOIT utiliser `VDataTableServer` (pas `VDataTable` client-side).

```html
<VCard>
  <!-- Toolbar -->
  <VCardText class="d-flex flex-wrap gap-4 align-center">
    <AppTextField
      v-model="search"
      placeholder="Rechercher..."
      style="max-inline-size: 280px;"
      prepend-inner-icon="tabler-search"
      clearable
    />
    <VSpacer />
    <!-- Filtres -->
    <AppSelect v-model="filter1" :items="options1" clearable style="max-inline-size: 200px;" />
    <!-- Actions -->
    <VBtn prepend-icon="tabler-plus" @click="openDrawer">Ajouter</VBtn>
  </VCardText>

  <VDivider />

  <!-- Bulk toolbar (conditionnel) -->
  <BulkToolbar
    v-if="selectedRows.length"
    :count="selectedRows.length"
    :actions="bulkActions"
    @clear="selectedRows = []"
  />

  <!-- Table -->
  <VDataTableServer
    v-model:model-value="selectedRows"
    :headers="headers"
    :items="items"
    :items-length="totalItems"
    :loading="isLoading"
    show-select
    density="compact"
    @update:options="updateOptions"
  >
    <!-- Slots personnalisés -->
  </VDataTableServer>

  <!-- Pagination -->
  <VDivider />
  <TablePagination ... />
</VCard>
```

## 3.2 Headers

```javascript
const headers = computed(() => [
  { title: t('common.name'), key: 'name', sortable: true },
  { title: t('common.status'), key: 'status', align: 'center', width: '120px' },
  { title: t('common.date'), key: 'created_at', width: '140px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '100px', sortable: false },
])
```

**Règles headers :**
- Toujours `computed` (pour i18n réactif)
- `width` explicite sur colonnes fixées (status, actions, dates)
- `sortable: false` sur colonnes d'actions
- `align: 'center'` sur status et actions

## 3.3 Status dans les tables

```html
<!-- TOUJOURS utiliser StatusChip — jamais hardcoder les couleurs -->
<StatusChip :status="item.status" domain="members" />
```

**INTERDIT :**
```javascript
// ❌ JAMAIS ça
const statusColor = status === 'active' ? 'success' : 'error'
```

## 3.4 Actions dans les tables

```html
<template #item.actions="{ item }">
  <IconBtn size="small" @click="viewDetail(item)">
    <VIcon icon="tabler-eye" size="22" />
  </IconBtn>
  <IconBtn size="small" @click="editItem(item)">
    <VIcon icon="tabler-pencil" size="22" />
  </IconBtn>
  <IconBtn size="small" color="error" @click="deleteItem(item)">
    <VIcon icon="tabler-trash" size="22" />
  </IconBtn>
</template>
```

## 3.5 Loading table

```html
<!-- Première charge : skeleton -->
<VSkeletonLoader v-if="!store._loaded" type="table-heading, table-row@5" />

<!-- Rechargement : :loading sur la table -->
<VDataTableServer v-else :loading="store._loading" ... />
```

## 3.6 Table vide

```html
<template #no-data>
  <EmptyState
    :title="t('members.noMembers')"
    :description="t('members.noMembersDescription')"
    icon="tabler-users"
    :action-label="t('members.addFirst')"
    @action="openDrawer"
  />
</template>
```

---

# 4. DRAWERS

## 4.1 Pattern obligatoire

```html
<VNavigationDrawer
  :model-value="isOpen"
  temporary
  location="end"
  :width="400"
  @update:model-value="$emit('update:isOpen', $event)"
>
  <!-- Header -->
  <AppDrawerHeaderSection
    :title="isEditing ? t('common.edit') : t('common.create')"
    @cancel="close"
  />

  <VDivider />

  <!-- Body -->
  <PerfectScrollbar :options="{ wheelPropagation: false }">
    <VCardText>
      <VForm ref="formRef" @submit.prevent="submit">
        <!-- Champs -->
      </VForm>
    </VCardText>
  </PerfectScrollbar>

  <!-- Footer -->
  <VDivider />
  <VCardText class="d-flex gap-4">
    <VBtn :loading="submitting" type="submit" @click="submit">
      {{ t('common.save') }}
    </VBtn>
    <VBtn variant="tonal" color="secondary" @click="close">
      {{ t('common.cancel') }}
    </VBtn>
  </VCardText>
</VNavigationDrawer>
```

## 4.2 Règles drawers

| Règle | Standard |
|-------|----------|
| Largeur desktop | 400px (simple), 600px (complexe), 800px (preview) |
| Largeur mobile | 100% (automatic via Vuetify) |
| Position | `location="end"` (droite) |
| Type | `temporary` (overlay) |
| Fermeture | Click outside OU bouton Cancel |
| Scroll | `PerfectScrollbar` dans le body |
| Header | `AppDrawerHeaderSection` avec titre + croix |
| Footer | Boutons Save + Cancel, collés en bas |
| Validation | VForm + rules, validate avant submit |
| Loading | `:loading` sur le bouton Save |
| Reset | `formRef.reset()` à la fermeture |
| Props | `isOpen` + `@update:isOpen` |

## 4.3 INTERDIT

- Drawer qui s'ouvre avec des données périmées → toujours reset au open
- Drawer sans bouton Cancel → toujours proposer l'annulation
- Drawer sans validation → toujours VForm + rules
- Drawer avec scroll sur toute la hauteur (header/footer doivent être fixés)

---

# 5. MODALS / DIALOGS

## 5.1 Quand utiliser quoi

| Besoin | Composant |
|--------|-----------|
| Création/édition d'une entité | **Drawer** (pas dialog) |
| Confirmation d'action destructive | **Dialog confirmation** (`useConfirm`) |
| Affichage d'information (preview) | **Dialog** ou **Drawer large** |
| Choix entre options | **Dialog** compact |
| Message système (session expired) | **Dialog** bloquant (persistent) |

## 5.2 Dialog confirmation (useConfirm)

```javascript
const { confirm, ConfirmDialogComponent } = useConfirm()

const deleteItem = async (item) => {
  const ok = await confirm({
    question: t('members.confirmDelete', { name: item.name }),
    confirmTitle: t('common.deleted'),
    confirmMsg: t('members.memberDeleted'),
    cancelTitle: t('common.cancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok) return
  // procéder à la suppression
}
```

## 5.3 Règles dialogs

| Règle | Standard |
|-------|----------|
| Props | `isDialogVisible` + `@update:isDialogVisible` |
| Largeur | `max-width="500"` (confirmation), `max-width="800"` (preview) |
| Actions destructives | Couleur `error` sur le bouton confirmer |
| Persistent | Seulement pour dialogs système (session, erreur critique) |
| Fermeture | Click outside OU bouton Annuler (sauf persistent) |

---

# 6. EMPTY / LOADING / ERROR STATES

## 6.1 Principe fondamental

```
CHAQUE ÉTAT D'UNE PAGE DOIT AVOIR UN RENDU VISIBLE.
Une page blanche est un BUG. Un état silencieux est un BUG.
```

## 6.2 État LOADING (premier chargement)

```html
<!-- store pas encore chargé → skeleton -->
<template v-if="!store._loaded">
  <PageSkeleton type="list" />
</template>

<!-- store chargé → contenu -->
<template v-else>
  <!-- contenu normal -->
</template>
```

**Types de skeleton :**

| Type | Vuetify type | Usage |
|------|-------------|-------|
| `list` | `table-heading, table-row@5` | Pages liste |
| `detail` | `card, list-item@3, paragraph@2` | Pages détail |
| `form` | `text@3, button` | Pages formulaire |
| `dashboard` | `card@4, table-heading, table-row@3` | Dashboards |
| `cards` | `card@6` | Grilles de cards |

**INTERDIT :** Spinner plein page (sauf boot initial via AppShellGate).

## 6.3 État LOADING (rechargement)

```
Store déjà chargé → PAS de skeleton.
Afficher les données en cache immédiatement.
Fetch en background avec { silent: true }.
Smart merge quand les nouvelles données arrivent.
```

## 6.4 État ERROR

```html
<!-- Erreur réseau / API -->
<ErrorBanner
  v-if="store._error"
  :message="store._error"
  @retry="store.retry()"
/>

<!-- Contenu normal (quand pas d'erreur) -->
<template v-else>
  ...
</template>
```

**Règles erreurs :**

| Type d'erreur | Rendu | Composant |
|--------------|-------|-----------|
| Fetch list échoue | Banner inline avec retry | `ErrorBanner` |
| Fetch detail échoue (404) | Redirect vers liste + toast | Router + `useAppToast` |
| Action échoue (500) | Toast error | `useAppToast` |
| Validation échoue (422) | Champs en rouge + focus | Vuetify `:error-messages` |
| Permission refusée (403) | Message inline | `VAlert type="warning"` |
| Session expirée (401) | Dialog bloquant | `SessionExpiredDialog` |

**INTERDIT :**
- Toast-only pour une erreur de chargement (trop éphémère)
- Silence total sur une erreur
- Données vidées sans explication
- Page blanche après une erreur

## 6.5 État EMPTY

```html
<!-- Liste sans résultat -->
<EmptyState
  :title="t('module.noItems')"
  :description="t('module.noItemsDescription')"
  icon="tabler-{module-icon}"
  :action-label="t('module.addFirst')"
  @action="openCreate"
/>
```

**Règles empty :**
- Toujours un titre explicite
- Toujours une description aidante
- Toujours une icône du module
- CTA si l'utilisateur peut agir (bouton "Ajouter le premier X")
- Pas de CTA si l'état est normal (ex: "Aucune alerte — tout va bien")

## 6.6 Matrice complète des états

```
                 _loaded=false     _loaded=true
                 ─────────────     ────────────
_error=null  │  SKELETON         │  CONTENU (ou EMPTY si items.length=0)
_error=set   │  ERROR+RETRY      │  CONTENU + ERROR BANNER (données stale affichées)
```

---

# 7. FEEDBACK ASYNC

## 7.1 Boutons d'action

```html
<!-- TOUJOURS :loading sur le bouton pendant l'action -->
<VBtn :loading="isSubmitting" @click="submit">Sauvegarder</VBtn>
```

**Règles :**
- Le bouton passe en `:loading="true"` immédiatement au clic
- Le reste de la page reste interactif
- Le bouton redevient cliquable après la réponse API
- Si erreur : bouton redevient normal + toast error

## 7.2 Toast feedback

```javascript
// Succès
toast(t('members.memberAdded'), 'success')

// Erreur
toast(t('common.operationFailed'), 'error')

// Info
toast(t('common.changesSaved'), 'info')
```

**Règles toast :**

| Situation | Type | Durée | Message |
|-----------|------|-------|---------|
| Action réussie | `success` | 3s | Court, affirmatif ("Membre ajouté") |
| Action échouée | `error` | 5s | Explicite ("Impossible d'ajouter. Réessayez.") |
| Info non critique | `info` | 3s | Neutre ("Changements sauvegardés") |
| Avertissement | `warning` | 5s | Préventif ("Document expire dans 7 jours") |

**INTERDIT :**
- Toast pour une erreur de chargement de page (utiliser ErrorBanner)
- Toast sans message (juste une couleur)
- Toast trop long (>100 caractères)

## 7.3 Optimistic updates

```
QUAND UTILISER :
- Ajout d'un item simple (membre, commentaire, message chat)
- Toggle on/off
- Mark as read

QUAND NE PAS UTILISER :
- Actions avec side effects complexes (changement de plan, paiement)
- Suppressions irréversibles
- Actions qui modifient le statut d'autres entités

PATTERN :
1. Insérer l'item en UI immédiatement (state="pending", opacity réduite)
2. Envoyer l'API
3. Succès → retirer l'état pending
4. Échec → retirer l'item + toast error
```

## 7.4 Long-running operations

```
QUAND : AI analysis, exports, bulk operations

PATTERN :
1. Toast "Opération lancée"
2. InlineProgress dans la liste (stepper ou progress bar)
3. SSE push pour chaque étape
4. Completion → toast + mise à jour UI
5. Si erreur à une étape → InlineProgress montre l'étape en erreur + Retry
```

---

# 8. ANIMATIONS

## 8.1 Animations autorisées

| Animation | Durée | Easing | Usage |
|-----------|-------|--------|-------|
| Fade in | 200ms | `ease-out` | Apparition de contenu après skeleton |
| Slide in (drawer) | 300ms | `cubic-bezier(0.4, 0, 0.2, 1)` | Ouverture drawer (Vuetify natif) |
| Scale dialog | 200ms | `ease-out` | Ouverture dialog (Vuetify natif) |
| List item insert | 200ms | `ease-out` | Nouvel item dans une liste (SSE) |
| List item remove | 150ms | `ease-in` | Suppression d'item |
| Toast slide | 300ms | `cubic-bezier(0.4, 0, 0.2, 1)` | Toast entrant |
| Toast fly | 600ms | `cubic-bezier(0.25, 0.1, 0.25, 1)` | Toast qui vole vers la cloche |
| Progress step | 300ms | `ease-out` | Transition entre étapes InlineProgress |
| Badge pulse | 200ms | `ease-in-out` | Notification badge après absorption toast |

## 8.2 Animations INTERDITES

- Bounce, shake, wiggle (sauf erreur de validation — shake léger autorisé)
- Animations >500ms (sauf toast fly qui est exceptionnel)
- Animations sur scroll (parallax, reveal)
- Animations qui bloquent l'interaction
- Animations de page entière (page transitions) — le router change instantanément

## 8.3 Transition pour listes (SSE)

```html
<TransitionGroup name="list" tag="div">
  <div v-for="item in items" :key="item.id">
    <!-- item content -->
  </div>
</TransitionGroup>
```

```css
.list-enter-active { transition: all 0.2s ease-out; }
.list-leave-active { transition: all 0.15s ease-in; }
.list-enter-from { opacity: 0; transform: translateY(-10px); }
.list-leave-to { opacity: 0; transform: translateX(20px); }
.list-move { transition: transform 0.2s ease; }
```

---

# 9. REALTIME UX

## 9.1 Principe

```
L'utilisateur ne doit JAMAIS douter de la fraîcheur des données.
Soit les données sont temps réel (SSE), soit on affiche quand elles ont été chargées.
```

## 9.2 SSE — Comportement UI

| Événement SSE | Comportement UI |
|--------------|-----------------|
| Item créé | Insert en haut de liste (animation slide-in) |
| Item mis à jour | Mise à jour in-place (pas de flash, smart merge) |
| Item supprimé | Retrait avec animation fade-out |
| Statut changé | StatusChip change de couleur (transition 200ms) |
| Message reçu (chat) | Append au log + scroll auto si en bas |
| Notification | Toast flying → badge bell + 1 |

## 9.3 Connection indicator

```
🟢 Connecté     — dot vert dans la navbar (tooltip: "Temps réel actif")
🟡 Reconnexion  — dot jaune (tooltip: "Reconnexion en cours...")
🔴 Déconnecté   — dot rouge + banner discret sous navbar
                   "Mode hors-ligne — les données peuvent être obsolètes"
                   [Bouton "Reconnecter"]
```

## 9.4 Déconnexion SSE — fallback

```
1. SSE drop → reconnexion auto (backoff 2s → 4s → 8s → 16s → 30s)
2. Pendant reconnexion → polling silencieux toutes les 30s
3. 5 échecs de reconnexion → banner "Mode dégradé" + polling 30s permanent
4. Reconnexion réussie → full sync (fetch fresh) + banner disparaît
```

## 9.5 Dédup SSE vs polling

```
Chaque event SSE a un UUID.
Le store maintient un Set des UUIDs traités (max 1000, FIFO).
Si un event arrive par SSE ET par polling → le deuxième est ignoré.
```

## 9.6 Multi-onglet

```
Si l'utilisateur a 2 onglets ouverts sur la même company :
- Chaque onglet a sa propre connexion SSE
- Les deux reçoivent les mêmes events
- Pas de synchronisation inter-onglets (trop complexe, peu de gain)
- Les stores sont indépendants par onglet
```

---

# 10. RESPONSIVE MOBILE

## 10.1 Breakpoints

```
xs : 0-599px     (mobile portrait)
sm : 600-959px   (mobile paysage / petite tablette)
md : 960-1279px  (tablette)
lg : 1280-1919px (desktop)
xl : 1920px+     (grand écran)
```

## 10.2 Comportement par breakpoint

| Élément | xs-sm | md | lg+ |
|---------|-------|----|----|
| Sidebar | Overlay (hamburger) | Overlay | Permanente |
| Navbar | Hamburger + Logo + Notifications | Full | Full |
| Tables | Scroll horizontal | Colonnes adaptées | Toutes colonnes |
| Drawers | 100% largeur | 400px | 400-800px |
| Dialogs | 100% largeur | max-width adapté | max-width adapté |
| Card grid | 1 colonne | 2 colonnes | 3-4 colonnes |
| KPI cards | Stack vertical | 2x2 | 4 en ligne |
| Filtres table | Collapsible (bouton "Filtres") | Inline | Inline |
| Bulk toolbar | Actions en menu ⋮ | Boutons inline | Boutons inline |
| Split view | Stack (detail en page) | 40/60 | 30/70 |

## 10.3 Tables mobile

```html
<!-- Sur mobile : cacher les colonnes non essentielles -->
<template #item.created_at="{ item }">
  <span class="d-none d-md-inline">{{ formatDate(item.created_at) }}</span>
</template>
```

**Colonnes toujours visibles :** Nom/titre, Statut, Actions
**Colonnes cachées mobile :** Dates, compteurs, colonnes secondaires

## 10.4 Touch targets

```
MINIMUM : 44x44px pour tout élément cliquable sur mobile
IconBtn : déjà 44px par défaut (OK)
VChip : augmenter la zone cliquable avec padding
Links dans les tables : toute la row cliquable (pas juste le texte)
```

---

# 11. ACCESSIBILITÉ MINIMALE

## 11.1 Niveau cible

```
WCAG 2.1 AA — minimum pour un SaaS B2B.
On ne vise pas AAA, mais on DOIT respecter AA.
```

## 11.2 Règles non-négociables

| Règle | Standard |
|-------|----------|
| Contraste texte | Ratio ≥ 4.5:1 (texte normal), ≥ 3:1 (texte large) |
| Focus visible | Outline visible sur tout élément focusable (Vuetify le gère) |
| Alt text images | Toute image informative a un `alt` descriptif |
| Labels formulaire | Tout champ a un `label` (AppTextField le fournit via `label` prop) |
| Navigation clavier | Tab pour naviguer, Enter pour activer, Escape pour fermer |
| Aria-labels | Sur les IconBtn sans texte visible |
| Rôle des régions | `role="main"`, `role="navigation"`, `role="dialog"` |
| Annonces dynamiques | `aria-live="polite"` sur les zones qui changent (toasts, alerts) |
| Skip link | Lien "Aller au contenu" en haut de page (Vuexy le gère) |

## 11.3 Checklist composant

```
☐ IconBtn sans texte → aria-label="Description"
☐ Dialog/Drawer → aria-labelledby="titre du dialog"
☐ Table → caption ou aria-label="Description de la table"
☐ Status avec couleur → aussi du texte (pas couleur seule)
☐ Loading states → aria-busy="true" sur le conteneur
☐ Toast → aria-live="polite" aria-atomic="true"
☐ Formulaire → aria-describedby pour les messages d'erreur
```

## 11.4 Couleurs et statuts

```
RÈGLE : La couleur ne doit JAMAIS être le seul indicateur.
Toujours accompagner d'un texte ou d'une icône.

✅ BON :  <VChip color="success"><VIcon icon="tabler-check" /> Actif</VChip>
❌ MAUVAIS : <VChip color="success" />  (juste un point vert)
```

---

# 12. COLLABORATION MULTI-UTILISATEUR

## 12.1 Principe

```
Deux utilisateurs sur la même page DOIVENT voir les changements de l'autre
sans rafraîchir la page. C'est le rôle du SSE + smart merge.
```

## 12.2 Scénarios couverts par le SSE

| Scénario | Comportement attendu |
|----------|---------------------|
| Admin A ajoute un membre, Admin B est sur la liste | B voit le nouveau membre apparaître (slide-in) |
| Admin A approuve un document, Admin B regarde la liste | B voit le statut changer (StatusChip transition) |
| Admin A modifie un shipment, Chauffeur B est sur le détail | B voit les changements (smart merge) |
| Support répond à un ticket, Company voit le chat | Message apparaît temps réel (append + scroll) |
| Système envoie une notification | Toast flying → bell badge |

## 12.3 Conflits d'édition

```
V2 ne gère PAS le verrouillage optimiste (trop complexe pour le scope).

RÈGLE SIMPLE :
- Le dernier qui sauvegarde gagne (last-write-wins)
- Si Admin A et Admin B éditent le même formulaire simultanément :
  → A sauvegarde → API accepte
  → B sauvegarde → API accepte (écrase A)
  → A reçoit l'update SSE → voit les changements de B

FUTUR (V3) :
- Lock optimiste : "Ce formulaire est en cours d'édition par Admin B"
- Diff/merge des changements conflictuels
```

## 12.4 Indicateurs de présence

```
V2 ne gère PAS les indicateurs de présence ("qui est en ligne").
Le seul indicateur est le dot online sur l'avatar dans le user menu.

FUTUR (V3) :
- Barre "Admin B est sur cette page" sur les pages detail
- Curseurs collaboratifs sur les formulaires
```

---

# ANNEXE — Checklist de conformité V2 par composant

## Page

```
☐ Utilise un des 5 layouts standards (LIST/DASHBOARD/DETAIL/SPLIT/SETTINGS)
☐ Skeleton au premier chargement (pas spinner plein page)
☐ ErrorBanner si store._error
☐ EmptyState si liste vide
☐ i18n complet (pas de strings hardcodées)
☐ Responsive mobile vérifié (xs + md + lg)
☐ Density correcte sur les tables
☐ Breadcrumbs visibles
```

## Store

```
☐ _loading, _error, _loaded présents
☐ try/catch sur tout fetch
☐ Smart merge (pas d'overwrite)
☐ Silent mode pour refetch background
☐ SSE handler si données mutables
☐ retry() exposé
```

## Composant (drawer, dialog, widget)

```
☐ Props typées
☐ Emit pattern correct (update:isOpen, update:modelValue)
☐ Loading state sur les boutons d'action
☐ Validation VForm si formulaire
☐ aria-label sur les IconBtn
☐ Responsive (pas de largeur fixe qui casse mobile)
```

## Table

```
☐ VDataTableServer (pas VDataTable)
☐ Headers computed avec i18n
☐ StatusChip (pas de couleurs hardcodées)
☐ show-select si bulk actions nécessaires
☐ Skeleton au premier load
☐ EmptyState dans #no-data
☐ Pagination avec TablePagination
☐ Colonnes secondaires cachées mobile
```
