# V2 BLUEPRINT FRONTEND — Leezr

> Document exécutable. Chaque section est implémentable indépendamment.
> Source de vérité pour la refonte frontend V2.

---

# TABLE DES MATIÈRES

1. [Shell V2](#1-shell-v2)
2. [Layouts Standards V2](#2-layouts-standards-v2)
3. [Pages V2 — Blueprint par domaine](#3-pages-v2--blueprint-par-domaine)
   - 3.12 [Logique transverse — Systèmes cross-module](#312-logique-transverse--systèmes-cross-module)
4. [Composants V2 obligatoires](#4-composants-v2-obligatoires)
5. [Composables V2 obligatoires](#5-composables-v2-obligatoires)
6. [Règles V2 — State / Realtime / Async / Errors](#6-règles-v2--state--realtime--async--errors)
7. [Roadmap sprintable](#7-roadmap-sprintable)

---

# 1. SHELL V2

## 1.1 État actuel du shell

| Élément | Company | Platform | État |
|---------|---------|----------|------|
| Sidebar verticale | ✅ manifest-driven | ✅ manifest-driven | OK |
| Navbar | Widgets + UserProfile | Search ⌘K + Widgets + PlatformUserProfile | Asymétrique |
| Notifications bell | ✅ avec toasts flying | ✅ | OK |
| Search ⌘K | ❌ ABSENT | ✅ NavSearchBar | GAP CRITIQUE |
| Breadcrumbs | ❌ ABSENT | ❌ ABSENT | GAP |
| Company switcher | ✅ séparé (pas dans navbar) | N/A | À intégrer |
| Connection indicator | ❌ ABSENT | ❌ ABSENT | GAP |
| Session governance | ✅ timeout + expired | ✅ | OK |
| AppShellGate | ✅ boot machine | ✅ | OK |
| Footer | ✅ dynamic links | ✅ | OK |

## 1.2 Shell V2 — Cible

### Navbar Company V2
```
┌──────────────────────────────────────────────────────────────┐
│ [Logo]  [CompanySwitcher▾]     [⌘K Search]  [🔔] [👤 User▾] │
│                                 [🟢 SSE]                     │
└──────────────────────────────────────────────────────────────┘
```

**Changements :**
1. **Search ⌘K company** — Reprendre `NavSearchBar.vue` (platform) et l'adapter au scope company : chercher membres, documents, expéditions, tickets
2. **Company switcher intégré** — Déplacer `CompanySwitcher.vue` dans la navbar (à gauche du logo, comme Slack/Notion)
3. **Connection indicator** — Petit dot SSE (vert=connecté, rouge=déconnecté) à côté des notifications
4. **Breadcrumbs** — Sous la navbar, au-dessus du contenu (pattern `VBreadcrumbs` de Vuetify)

### Breadcrumbs V2
```
Home > Members > Mohamed Alami
Home > Documents > Requests > Permis C #1234
Home > Shipments > EXP-2024-0042
```

**Implémentation :**
- Composable `useBreadcrumbs()` qui lit `route.matched` + enrichit avec les titres dynamiques
- Rendu dans `DefaultLayoutWithVerticalNav.vue` entre la navbar et le `<slot />`
- Chaque page peut override via `definePage({ meta: { breadcrumb: [...] } })`

### Fichiers impactés
```
resources/js/layouts/components/DefaultLayoutWithVerticalNav.vue  — ajouter search + breadcrumbs
resources/js/layouts/components/NavSearchBar.vue                  — dupliquer/adapter pour company
resources/js/layouts/components/NavbarGlobalWidgets.vue           — ajouter ConnectionIndicator
resources/js/layouts/components/CompanyNavbar.vue                 — NOUVEAU (extraction)
resources/js/composables/useBreadcrumbs.js                       — NOUVEAU
resources/js/components/ConnectionIndicator.vue                   — NOUVEAU
```

---

# 2. LAYOUTS STANDARDS V2

Chaque page V2 utilise un des 5 layouts standards ci-dessous. Le layout détermine la structure, pas le contenu.

## 2.1 Layout LIST — List + Filters + BulkToolbar + DetailDrawer

**Preset source :** `presets/pages/templates/apps/user/list/index.vue`

```
┌─────────────────────────────────────────────────┐
│ [Page Title]                    [+ Ajouter] [⋮] │
├─────────────────────────────────────────────────┤
│ [🔍 Search] [Filtre 1 ▾] [Filtre 2 ▾] [Clear]  │
├─────────────────────────────────────────────────┤
│ ☐ BulkToolbar: [Approuver (3)] [Rejeter] [Suppr]│  ← Apparaît quand items sélectionnés
├─────────────────────────────────────────────────┤
│ ☐ │ Nom          │ Statut  │ Date   │ Actions  │
│ ☐ │ Item 1       │ 🟢 OK   │ 10/04  │ [⋮]      │
│ ☐ │ Item 2       │ 🟡 Att  │ 09/04  │ [⋮]      │
│   │ ...          │         │        │          │
├─────────────────────────────────────────────────┤
│ Showing 1-10 of 42    [< 1 2 3 4 5 >]          │
└─────────────────────────────────────────────────┘
```

**Composants requis :**
- `VDataTableServer` avec `show-select`
- `FilterBar` (NOUVEAU) — search + chips filtres dynamiques
- `BulkToolbar` (NOUVEAU) — toolbar contextuelle
- `TablePagination` (existant)
- Drawer latéral pour create/edit

**Pages utilisant ce layout :**
Members list, Documents requests, Shipments list, Deliveries list, Support tickets, Audit trail

## 2.2 Layout DASHBOARD — KPI + Feed + ActionCenter

**Preset source :** `presets/dashboards/ecommerce/EcommerceStatistics.vue` + `presets/cards/card-advance/CardAdvanceActivityTimeline.vue`

```
┌────────────────────────────────────────────────────────────┐
│ [Onboarding Checklist — 3/7 complété]          [▾ Fermer] │  ← Si incomplete
├──────────┬──────────┬──────────┬──────────────────────────┤
│ KPI 1    │ KPI 2    │ KPI 3    │ KPI 4                    │
│ 45 memb  │ 87% comp │ 12 exp   │ 3 alertes                │
├──────────┴──────────┴──────────┴──────────────────────────┤
│                                                            │
│  ┌──────────────────────┐  ┌────────────────────────────┐ │
│  │ Actions urgentes     │  │ Activité récente           │ │
│  │ • 3 docs à valider   │  │ • Mohamed a uploadé...     │ │
│  │ • 2 docs expirés     │  │ • Sarah a rejoint...       │ │
│  │ • 1 ticket ouvert    │  │ • Expédition EXP-42...     │ │
│  └──────────────────────┘  └────────────────────────────┘ │
│                                                            │
│  ┌──────────────────────────────────────────────────────┐ │
│  │ Compliance Score                                      │ │
│  │ ████████████████░░░░ 78%                              │ │
│  │ Permis C: 90% ✅  FIMO: 64% ⚠️  Visite méd: 20% 🔴  │ │
│  └──────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────┘
```

**Composants requis :**
- `OnboardingChecklist` (NOUVEAU)
- KPI cards (existantes via `card-grid card-grid-xs`)
- `ActionCenter` (NOUVEAU) — liste d'actions urgentes cliquables
- `ActivityFeed` (NOUVEAU) — timeline d'activité récente
- `ComplianceBreakdown` (NOUVEAU) — widget compliance

**Pages utilisant ce layout :**
Home/Dashboard company

## 2.3 Layout DETAIL — Split View / Profile + Tabs

**Preset source :** `presets/pages/templates/apps/ecommerce/customer/details/[id].vue`

```
┌────────────────────────────────────────────────────────────┐
│ [← Retour liste]  Member #42 — Mohamed Alami   [Actions ▾]│
├──────────────┬─────────────────────────────────────────────┤
│              │                                             │
│  Bio Panel   │  [Overview] [Documents] [Credentials] [Act]│
│              │ ┌─────────────────────────────────────────┐ │
│  [Avatar]    │ │                                         │ │
│  Mohamed A.  │ │  Contenu de l'onglet actif              │ │
│  Chauffeur   │ │                                         │ │
│              │ │  Timeline / données / formulaires       │ │
│  📧 email    │ │                                         │ │
│  📱 phone    │ │                                         │ │
│  🏢 company  │ │                                         │ │
│              │ └─────────────────────────────────────────┘ │
│  [Modifier]  │                                             │
└──────────────┴─────────────────────────────────────────────┘
```

**Composants requis :**
- `BioPanel` — card latérale avec info clés
- `VTabs` + `VWindow` — onglets de contenu
- `StatusTimeline` (NOUVEAU) — historique/états
- Breadcrumbs V2

**Pages utilisant ce layout :**
Member detail, Document detail, Shipment detail, Support ticket detail

## 2.4 Layout SPLIT — Master List + Detail Panel

**Preset source :** `presets/apps/email/` + `presets/apps/chat/`

```
┌──────────────────────┬─────────────────────────────────────┐
│ [🔍 Search]          │                                     │
├──────────────────────┤  Détail de l'item sélectionné       │
│ ● Item 1 (active)    │                                     │
│   Item 2             │  [Titre]                            │
│   Item 3             │  [Contenu principal]                │
│   Item 4             │  [Actions]                          │
│   ...                │                                     │
│                      │                                     │
│                      │                                     │
│                      │                                     │
└──────────────────────┴─────────────────────────────────────┘
```

**Pages utilisant ce layout :**
Support tickets (chat-like), Notifications full page

## 2.5 Layout SETTINGS — Form Sections + Tabs

**Preset source :** `presets/apps/ecommerce/settings/`

```
┌────────────────────────────────────────────────────────────┐
│ [General] [Security] [Notifications] [Billing] [Company]   │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  Section 1: Informations générales                         │
│  ┌──────────────────────────────────────────────────────┐ │
│  │ [Nom]           [Email]                               │ │
│  │ [Téléphone]     [Langue ▾]                            │ │
│  └──────────────────────────────────────────────────────┘ │
│                                                            │
│  Section 2: Préférences                                    │
│  ┌──────────────────────────────────────────────────────┐ │
│  │ [Toggle 1] Description du toggle                      │ │
│  │ [Toggle 2] Description du toggle                      │ │
│  └──────────────────────────────────────────────────────┘ │
│                                                            │
│  [💾 Sauvegarder]                          [Annuler]       │
│                                                            │
│  ⚠️ "Modifications non sauvegardées"      ← DirtyGuard    │
└────────────────────────────────────────────────────────────┘
```

**Composants requis :**
- `DirtyGuard` (NOUVEAU) — détecte les changements non sauvegardés
- `SectionCard` — VCard par section de formulaire
- `VTabs` pour navigation entre sections

**Pages utilisant ce layout :**
Settings company, Profile utilisateur, Platform settings

---

# 3. PAGES V2 — BLUEPRINT PAR DOMAINE

## 3.1 HOME — Dashboard Company

### État actuel
- KPI génériques, pas d'actions urgentes, pas d'onboarding, pas d'activité

### V2 Blueprint
**Layout :** DASHBOARD (2.2)

| Zone | Contenu V2 | Composant | Preset Vuexy |
|------|-----------|-----------|--------------|
| Onboarding | Checklist progressive (si < 100%) | `OnboardingChecklist` | `CardAdvanceAssignmentProgress.vue` |
| KPI Row | Membres actifs, Score compliance, Docs en attente, Expéditions en cours | KPI cards | `EcommerceStatistics.vue` |
| Actions urgentes | Documents à valider, expirations, tickets ouverts | `ActionCenter` | `CardAdvanceActivityTimeline.vue` |
| Activité | Feed des dernières actions (uploads, validations, membres) | `ActivityFeed` | `CrmActivityTimeline.vue` |
| Compliance | Score global + breakdown par type + par membre | `ComplianceBreakdown` | `CardStatisticsGeneratedLeads.vue` |

**Store V2 :** `useHomeStore` — agrège les données de members, documents, shipments, support

**SSE :** Écoute tous les topics company → met à jour KPIs et feeds en temps réel

---

## 3.2 MEMBERS — Gestion des membres

### 3.2.1 Members List

**Layout :** LIST (2.1)

| Élément | V1 | V2 |
|---------|----|----|
| Search | ❌ | ✅ Debounced, server-side |
| Filtres | ❌ | ✅ Rôle, Poste, Statut compliance |
| Bulk actions | ❌ | ✅ Inviter, Supprimer, Changer rôle |
| Loading | ❌ | ✅ Skeleton table |
| Empty state | ❌ | ✅ EmptyState avec CTA "Inviter votre premier membre" |
| Pagination | ❌ server-side | ✅ VDataTableServer |
| SSE | ❌ | ✅ member.joined, member.updated, member.removed |
| Export | ❌ | ✅ CSV |

**Headers table V2 :**
```
☐ | Avatar+Nom | Email | Rôle | Poste | Compliance | Dernière activité | Actions
```

**Drawer create V2 :**
- Champs : nom, email, rôle, poste
- Poste → preview auto des docs requis : "Ce poste requiert : Permis C, FIMO, Visite médicale"
- Toggle "Envoyer demande de docs automatiquement"
- Bulk import CSV : bouton séparé

### 3.2.2 Member Detail

**Layout :** DETAIL (2.3)

**Preset source :** `presets/pages/templates/apps/ecommerce/customer/details/[id].vue`

| Onglet | Contenu |
|--------|---------|
| Overview | Infos personnelles, rôle, poste, date d'arrivée, compliance score individuel |
| Documents | Liste des docs du membre avec statuts, bouton relancer |
| Credentials | Certifications, permis, formations (existant) |
| Activité | Timeline des actions du membre (uploads, validations, connexions) |

**Bio Panel :** Avatar, nom, rôle, poste, score compliance (jauge), email, téléphone, date d'ajout

---

## 3.3 DOCUMENTS — Gestion documentaire

### 3.3.1 Documents Requests

**Layout :** LIST (2.1)

| Élément | V1 | V2 |
|---------|----|----|
| AI feedback | ❌ silence 30s | ✅ InlineProgress stepper (Upload → Analyse → Extraction → Résultat) |
| Bulk actions | ❌ | ✅ Approuver, Rejeter, Relancer (sélection multi) |
| Filtres | Basique | ✅ Type doc, Statut, Membre, Date range |
| Detail click | ❌ | ✅ → /documents/[id] (page detail) |
| SSE | ✅ (seul module) | ✅ Garder + enrichir avec steps AI |
| Empty state | ❌ | ✅ "Aucun document en attente. Tout est en ordre." |

### 3.3.2 Document Detail (NOUVEAU)

**Layout :** DETAIL (2.3) — variante split

```
┌─────────────────────────┬──────────────────────────────────┐
│                         │                                  │
│  [Image/PDF du doc]     │  Données AI extraites            │
│                         │  ┌──────────────────────────────┐│
│  Zoom + navigation      │  │ Nom: Mohamed Alami    (98%) ││
│                         │  │ N°: 12345678          (95%) ││
│                         │  │ Expiration: 12/2025   (72%) ││
│                         │  └──────────────────────────────┘│
│                         │                                  │
│                         │  Timeline                        │
│                         │  ● Upload — 10/04 14:23          │
│                         │  ● AI analyse — 10/04 14:23      │
│                         │  ● En attente review — maintenant│
│                         │                                  │
│                         │  [✅ Approuver] [❌ Rejeter]     │
│                         │  [📝 Note de review]             │
└─────────────────────────┴──────────────────────────────────┘
```

### 3.3.3 Documents Vault / Compliance

| Élément | V1 | V2 |
|---------|----|----|
| Compliance widget | Score % basique | ✅ ComplianceBreakdown (par type + par membre) |
| Refresh | ❌ pas de retry | ✅ RetryButton si erreur |
| Export | ❌ | ✅ PDF compliance report |

---

## 3.4 SHIPMENTS — Expéditions

### 3.4.1 Shipments List

**Layout :** LIST (2.1)

| Élément | V1 | V2 |
|---------|----|----|
| Loading | ❌ | ✅ Skeleton table |
| Error | ❌ | ✅ ErrorBanner + Retry |
| Filtres | ✅ statut + search | ✅ + date range, assigné, priorité |
| Bulk actions | ❌ | ✅ Changer statut, Assigner, Exporter |
| SSE | ❌ | ✅ shipment.created, shipment.status_changed |
| StatusChip | ❌ hardcodé | ✅ StatusChip domain="shipments" |

### 3.4.2 Shipment Detail

**Layout :** DETAIL (2.3)

| Onglet | Contenu |
|--------|---------|
| Overview | Infos expédition, client, adresses, assigné |
| Timeline | StatusTimeline visuelle : draft → confirmed → picked_up → in_transit → delivered |
| Historique | Qui a changé quoi, quand, notes |

**SSE :** Mise à jour temps réel du statut (chauffeur met à jour sur le terrain)

---

## 3.5 DELIVERIES — Mes livraisons (chauffeur)

**Layout :** LIST (2.1) — vue simplifiée

Mêmes améliorations que Shipments mais scope réduit au chauffeur connecté.

---

## 3.6 SUPPORT — Tickets

### 3.6.1 Tickets List

**Layout :** LIST (2.1)

| Élément | V1 | V2 |
|---------|----|----|
| Search | ❌ | ✅ Recherche dans titre + contenu |
| Filtres | Basique | ✅ Statut, Priorité, Date |
| Réouverture | ❌ | ✅ Action "Réouvrir" sur tickets fermés |
| SSE | ❌ | ✅ ticket.updated, ticket.message.created |

### 3.6.2 Ticket Detail

**Layout :** SPLIT (2.4) — chat-like

| Élément | V1 | V2 |
|---------|----|----|
| Optimistic send | ❌ | ✅ Message apparaît immédiatement (grisé) |
| Typing indicator | ❌ | ✅ "Support est en train d'écrire..." |
| SSE messages | ❌ | ✅ Nouveaux messages push temps réel |
| Attachments | ✅ | ✅ Garder |

---

## 3.7 BILLING — Facturation

**Layout :** SETTINGS (2.5) avec tabs

| Onglet | V1 | V2 |
|--------|----|----|
| Overview | ✅ | ✅ + Loading/Error states systématiques |
| Plans | ✅ | ✅ + Preview avant changement |
| Invoices | ✅ | ✅ + StatusChip au lieu de hardcoded colors |
| Payment | ✅ | ✅ + Idempotency keys, retry gracieux |

**SSE :** ✅ subscription.updated, payment.completed, invoice.created

---

## 3.8 SETTINGS — Configuration company

**Layout :** SETTINGS (2.5)

| Élément | V1 | V2 |
|---------|----|----|
| Dirty check | ❌ | ✅ DirtyGuard sur tous les formulaires |
| 403 silencieux | ❌ error muette | ✅ Message explicite "Permission refusée" |
| Feedback save | ✅ toast | ✅ Toast + bouton disabled pendant save |

---

## 3.9 AUDIT TRAIL

**Layout :** LIST (2.1)

| Élément | V1 | V2 |
|---------|----|----|
| Loading | ❌ | ✅ Skeleton table |
| Error | ❌ | ✅ ErrorBanner + Retry |
| Filtres | ❌ | ✅ Action, Utilisateur, Date range, Module |
| Export | ❌ | ✅ CSV/PDF |
| Détail expandable | ❌ | ✅ Row expand avec diff JSON |
| SSE | ❌ | ✅ audit.entry.created (nouvelle entrée push) |

---

## 3.10 SHELL GLOBAL — Éléments transverses

| Élément | V1 | V2 |
|---------|----|----|
| Search ⌘K company | ❌ | ✅ Chercher membres, docs, expéditions, tickets |
| Breadcrumbs | ❌ | ✅ Route-based + titres dynamiques |
| Connection indicator | ❌ | ✅ Dot SSE dans navbar |
| Company switcher | ✅ séparé | ✅ Intégré navbar |
| Onboarding | ❌ | ✅ Checklist progressive sur Home |

---

## 3.11 PLATFORM — Outils internes (améliorations mineures)

La platform est déjà à 8-9/10 en qualité UX. Améliorations V2 :

| Élément | Action |
|---------|--------|
| Bulk actions | Activer les `selectedRows` déjà câblés dans Users, Companies |
| Breadcrumbs | Ajouter dans les pages detail (companies/[id], plans/[key]) |
| Search company scope | NavSearchBar cherche aussi dans les données company supervisées |
| Accessibility | Ajouter ARIA labels manquants |

---

## 3.12 LOGIQUE TRANSVERSE — Systèmes cross-module

### 3.12.1 Notification Center Company (page dédiée)

Le bell dropdown navbar affiche les 5 dernières notifications. La page `/company/notifications` offre la vue complète.

| Aspect | Spécification |
|--------|---------------|
| **Route** | `pages/company/notifications/index.vue` |
| **Layout** | LIST — filtres par module/type/lu-non-lu, pagination serveur |
| **Store** | `notificationsStore` — paginated, SSE topic `company.{id}.notifications` |
| **Sources** | Chaque module émet des notifications via `NotificationService` backend |
| **Types** | `info`, `warning`, `action_required`, `success` |
| **Groupement** | Par jour (aujourd'hui, hier, cette semaine, plus ancien) |
| **Actions** | Marquer lu, marquer tout lu, supprimer, clic → navigation vers la ressource |
| **Badge navbar** | Compteur non-lus via SSE, reset au clic bell |
| **Preset** | `presets/apps/email/` (layout master-detail) adapté en read-only |

**Règle** : Un module ne push JAMAIS directement dans le dropdown navbar. Il émet un event backend → SSE → `notificationsStore.onNotification()` → le dropdown se met à jour via le store.

### 3.12.2 Action Center Cross-Module

Centre d'actions agrégé — widget dashboard + page dédiée optionnelle.

| Aspect | Spécification |
|--------|---------------|
| **Widget** | `ActionCenterWidget.vue` sur le dashboard Home |
| **Source** | API `/api/company/{id}/actions/pending` — agrège les actions de tous les modules |
| **Données** | Chaque module expose ses actions via `PendingActionsProvider` (interface backend) |
| **Affichage** | Liste triée par urgence (overdue → today → upcoming) |
| **Actions types** | Documents expirés, factures impayées, tickets ouverts, expéditions en attente |
| **Clic** | Navigation directe vers la ressource concernée (router-link) |
| **SSE** | Topic `company.{id}.actions` — refresh count en temps réel |
| **Preset** | `presets/cards/CardActivityTimeline.vue` + `presets/dashboards/` widget list |

**Règle module** : Chaque module qui veut apparaître dans l'Action Center DOIT implémenter `PendingActionsProvider` côté backend. Le frontend ne fait qu'afficher ce que l'API agrège. Pas de logique module-spécifique côté frontend.

```
// Backend — interface à implémenter par chaque module
interface PendingActionsProvider
{
    public function getPendingActions(Company $company): Collection;
    // Retourne: [{ type, title, description, urgency, route, module, created_at }]
}
```

### 3.12.3 Command Bar Globale Company (⌘K)

Adaptation du `NavSearchBar.vue` (platform-only aujourd'hui) pour le scope company.

| Aspect | Spécification |
|--------|---------------|
| **Composant** | `CompanyCommandBar.vue` — dialog modal plein écran |
| **Activation** | `⌘K` / `Ctrl+K` — raccourci global |
| **Sources recherche** | Membres, documents, expéditions, tickets, paramètres |
| **Architecture** | Chaque module enregistre un `SearchProvider` (backend) |
| **Résultats** | Groupés par module, 5 max par groupe, icône module + titre + description |
| **Actions rapides** | Section "Actions" en haut : créer membre, créer ticket, voir dashboard |
| **Navigation récente** | Section "Récents" : 5 dernières pages visitées (localStorage) |
| **Debounce** | 300ms sur la frappe, abort controller sur chaque nouvelle requête |
| **Preset** | `presets/molecules/NavSearchBar.vue` (déjà fonctionnel platform) |
| **Sprint** | V2-3 (dépend des SearchProvider backend) |

```
// Backend — interface par module
interface SearchProvider
{
    public function getSearchableType(): string;  // 'member', 'document', 'shipment'...
    public function search(Company $company, string $query, int $limit = 5): Collection;
    // Retourne: [{ id, type, title, subtitle, icon, route }]
}
```

**Règle** : La command bar ne connaît PAS les modules. Elle interroge une API unifiée `/api/company/{id}/search?q=` qui délègue aux `SearchProvider` enregistrés. Ajouter un module à la recherche = implémenter l'interface, zéro changement frontend.

### 3.12.4 Activity Feed Global Company

Timeline d'activité cross-module — widget dashboard + onglet dédié optionnel.

| Aspect | Spécification |
|--------|---------------|
| **Widget** | `ActivityFeedWidget.vue` sur le dashboard Home |
| **Page** | `pages/company/activity/index.vue` (optionnel V2-4) |
| **Source** | API `/api/company/{id}/activity` — agrège les events de `audit_logs` |
| **Filtres** | Par module, par utilisateur, par type d'action, période |
| **Affichage** | Timeline verticale : avatar + action + ressource + timestamp relatif |
| **Pagination** | Infinite scroll (charger 20, puis 20 de plus) |
| **SSE** | Topic `company.{id}.activity` — prepend nouvelles entrées en haut |
| **Preset** | `presets/pages/CardActivityTimeline.vue` + `presets/molecules/TimelinePrimary.vue` |

**Règle** : L'activity feed lit les `audit_logs` existants. Il n'y a PAS de table dédiée. Chaque module écrit déjà dans audit_logs via le trait `Auditable`. Le feed ne fait que lire et formater.

### 3.12.5 Règles de collaboration temps réel multi-utilisateur

| Règle | Description |
|-------|-------------|
| **Modèle** | Last-write-wins (pas de locking en V2) |
| **Présence** | SSE topic `company.{id}.presence` — liste des utilisateurs connectés |
| **Indicateur** | Avatar avec dot vert dans la navbar (qui est en ligne) |
| **Conflit d'édition** | Toast "X a modifié ce document" si SSE reçoit un update sur la ressource en cours d'édition |
| **Merge** | Pas de merge auto en V2 — le dernier save gagne, l'autre reçoit un toast + refresh |
| **Stale data** | Si un SSE update arrive sur une ressource affichée → smart merge dans le store (pas de reload complet) |
| **Formulaires** | Si un form est dirty et un SSE update arrive → banner "Données modifiées par X. Recharger ?" avec bouton refresh |
| **Sprint** | V2-4 (polish) — la présence est un nice-to-have |

**Séquence de conflit** :
```
1. User A ouvre le formulaire membre #42
2. User B modifie membre #42 → save → SSE broadcast
3. User A reçoit SSE event "member.42.updated"
4. SI form A est clean → smart merge silencieux
5. SI form A est dirty → banner avertissement, user A choisit: recharger ou ignorer
6. User A sauvegarde → son save écrase (last-write-wins)
```

### 3.12.6 Anti-pattern : Documents ≠ modèle implicite

Le module Documents est le plus mature (SSE, smart merge, états, AI). Il ne doit PAS devenir le template copié-collé pour les autres modules.

| Règle | Explication |
|-------|-------------|
| **Pas de copier-coller** | Chaque module copie les PATTERNS (store standard, 4 états, SSE), pas le CODE de Documents |
| **Store template** | Le modèle de store est dans la section 6.1, PAS dans `documents.store.js` |
| **SSE topics** | Chaque module définit ses propres topics dans son manifest, pas en copiant ceux de Documents |
| **AI integration** | L'intégration AI est spécifique à Documents. Les autres modules n'ont PAS besoin d'AI en V2 |
| **Naming** | Pas de `requests`/`vault` dans les autres modules — chaque module a son propre vocabulaire |
| **Complexité** | Documents a des sous-catégories (requests, vault, compliance). Les autres modules sont plus simples — ne pas sur-architecturer |
| **Composables** | Les composables partagés sont dans `core/composables/`, pas extraits de Documents |

**Checklist avant d'implémenter un module** :
1. ✅ Store conforme à la section 6.1 (pas copié de documents.store.js)
2. ✅ SSE topics définis dans le manifest du module
3. ✅ Composables importés depuis `core/composables/`
4. ✅ Vocabulaire propre au domaine (pas de `requests`/`topics` recyclés)
5. ✅ Complexité proportionnelle au besoin réel du module

---

# 4. COMPOSANTS V2 OBLIGATOIRES

## 4.1 Nouveaux composants à créer

| Composant | Description | Preset Vuexy source | Priorité |
|-----------|------------|---------------------|----------|
| `FilterBar` | Search + chips filtres dynamiques réutilisable | `apps/user/list/index.vue` (toolbar section) | P0 |
| `BulkToolbar` | Toolbar contextuelle quand items sélectionnés | Aucun preset direct — pattern custom | P0 |
| `StatusTimeline` | Timeline verticale avec étapes et timestamps | `cards/card-advance/CardAdvanceActivityTimeline.vue` | P0 |
| `InlineProgress` | Stepper horizontal inline dans une row de table | `molecules/progress-linear/` + custom | P0 |
| `ErrorBanner` | Banner d'erreur inline avec retry (pas toast) | Pattern custom avec VAlert | P0 |
| `OnboardingChecklist` | Checklist progressive avec % | `cards/card-advance/CardAdvanceAssignmentProgress.vue` | P1 |
| `ActionCenter` | Liste d'actions urgentes cliquables | `cards/card-advance/CardAdvanceActivityTimeline.vue` | P1 |
| `ActivityFeed` | Timeline d'activité récente centralisée | `dashboards/crm/CrmActivityTimeline.vue` | P1 |
| `ComplianceBreakdown` | Widget compliance avec breakdown par type/membre | `cards/card-statistics/CardStatisticsGeneratedLeads.vue` | P1 |
| `ConnectionIndicator` | Badge SSE connected/disconnected | `molecules/badge/DemoBadgeDynamicNotifications.vue` | P1 |
| `DirtyGuard` | Détection changements non sauvegardés + dialog | `dialogs/ConfirmDialog.vue` (base) | P1 |
| `PageSkeleton` | Skeleton layout réutilisable par type (list, detail, form) | VSkeletonLoader natif Vuetify | P0 |
| `RetryButton` | Bouton "Réessayer" standardisé avec callback | Pattern custom | P0 |
| `DocumentViewer` | Split image/PDF + données AI extraites | `apps/email/EmailView.vue` (split pattern) | P1 |

## 4.2 Composants existants à généraliser

| Composant | Usage V1 | Usage V2 |
|-----------|----------|----------|
| `EmptyState` | 1 page (billing) | TOUTE liste vide — avec illustration + CTA contextuel |
| `ErrorState` | AppShellGate only | TOUTE page en erreur (via store._error) |
| `StatusChip` | Billing (5 domains) | + shipments, support, documents, members (ajouter domains) |
| `SectionHeader` | Rare | Headers de section dans pages detail et settings |

## 4.3 Structure fichiers composants V2

```
resources/js/components/
├── FilterBar.vue              ← NOUVEAU
├── BulkToolbar.vue            ← NOUVEAU
├── StatusTimeline.vue         ← NOUVEAU
├── InlineProgress.vue         ← NOUVEAU
├── ErrorBanner.vue            ← NOUVEAU
├── OnboardingChecklist.vue    ← NOUVEAU
├── ActionCenter.vue           ← NOUVEAU
├── ActivityFeed.vue           ← NOUVEAU
├── ComplianceBreakdown.vue    ← NOUVEAU
├── ConnectionIndicator.vue    ← NOUVEAU
├── DirtyGuard.vue             ← NOUVEAU
├── PageSkeleton.vue           ← NOUVEAU
├── RetryButton.vue            ← NOUVEAU
├── DocumentViewer.vue         ← NOUVEAU
├── EmptyState.vue             ← EXISTANT (à enrichir)
├── ErrorState.vue             ← EXISTANT (à généraliser)
├── StatusChip.vue             ← EXISTANT (ajouter domains)
└── SectionHeader.vue          ← EXISTANT (à utiliser plus)
```

---

# 5. COMPOSABLES V2 OBLIGATOIRES

## 5.1 Nouveaux composables

| Composable | Rôle | Utilisation |
|-----------|------|-------------|
| `useBreadcrumbs()` | Génère breadcrumbs depuis route.matched + override meta | Layouts |
| `useDirtyForm(formRef)` | Track dirty state d'un formulaire, beforeRouteLeave guard | Settings, Profile, tous les forms |
| `useListPage({ store, filters })` | Encapsule le pattern LIST : pagination, sort, search, fetch | Toutes les pages LIST |
| `useStatusColors(domain)` | Map centralisé status → color par domain | Remplace les hardcoded status colors |
| `useBulkActions(selectedRows)` | Gère la logique de sélection + actions groupées | Pages LIST avec sélection |
| `useConnectionStatus()` | Expose l'état SSE (connected/reconnecting/disconnected) | ConnectionIndicator |

## 5.2 Composables existants à enrichir

| Composable | État actuel | Enrichissement V2 |
|-----------|-------------|-------------------|
| `useAsyncAction` | ✅ Excellent mais sous-utilisé | Utiliser PARTOUT (remplace les try/catch manuels) |
| `useAppToast` | 3 niveaux | Ajouter severity icons (info=ℹ️, success=✅, warning=⚠️, error=❌) |
| `useRealtimeSubscription` | ✅ (documents only) | Pattern doc → appliquer à members, shipments, support, billing, audit |

## 5.3 Pattern `useListPage` — standard pour toute page liste

```javascript
// Encapsule 100% du boilerplate d'une page liste
const {
  items,           // computed → store items
  isLoading,       // ref → loading state
  error,           // ref → error message
  search,          // ref → search query
  filters,         // reactive → filter values
  pagination,      // reactive → { page, itemsPerPage, sortBy, orderBy }
  selectedRows,    // ref → selected item IDs
  totalItems,      // computed → total count
  fetchList,       // function → fetch with current state
  retry,           // function → retry last fetch
  updateOptions,   // function → VDataTableServer @update:options handler
} = useListPage({
  store: useMembersStore(),
  fetchAction: 'fetchMembers',
  defaultFilters: { status: 'active' },
  searchDebounce: 400,
})
```

---

# 6. RÈGLES V2 — STATE / REALTIME / ASYNC / ERRORS

## 6.1 Règle STATE — Tout store Pinia DOIT respecter

```
OBLIGATOIRE dans chaque store company-scope :

1. _loading: false          // ou { list: false, detail: false }
2. _error: null             // string message ou null
3. _loaded: false           // true après premier fetch réussi
4. try/catch sur TOUT fetch
5. Smart merge (pas d'overwrite brutal)
6. Silent mode pour refetch background
7. SSE handler si le module mute des données
8. retry() exposé

NON-NÉGOCIABLE : Un store sans _loading et _error est un BUG.
```

**Stores à mettre en conformité :**

| Store | _loading | _error | _loaded | Smart merge | SSE | Status V1 |
|-------|----------|--------|---------|-------------|-----|-----------|
| documentsStore | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ CONFORME |
| membersStore | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ À REFAIRE |
| shipmentStore | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ À REFAIRE |
| supportStore | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ À REFAIRE |
| billingStore | ❌ | ❌ | ✅ | ❌ | ❌ | ⚠️ PARTIEL |
| auditStore | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ À REFAIRE |
| homeStore | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ À REFAIRE |
| settingsStore | ⚠️ | ❌ | ✅ | ❌ | ❌ | ⚠️ PARTIEL |
| profileStore | ⚠️ | ❌ | ❌ | ❌ | ❌ | ⚠️ PARTIEL |
| modulesStore | ✅ | ❌ | ✅ | ❌ | ❌ | ⚠️ PARTIEL |

## 6.2 Règle REALTIME — SSE obligatoire

```
OBLIGATOIRE : Tout module qui affiche des données mutables DOIT écouter les SSE.

Topics V2 par module :
  members   → member.joined, member.updated, member.removed
  documents → (existant) + document.ai_step, document.reviewed
  shipments → shipment.created, shipment.status_changed, shipment.assigned
  support   → ticket.created, ticket.updated, ticket.message.created
  billing   → subscription.updated, payment.completed, invoice.created
  audit     → audit.entry.created
  home      → * (agrège tous les topics)

PATTERN :
  1. Store expose handleRealtimeEvent(payload)
  2. ChannelRouter dispatch vers le bon store
  3. Store fait smart merge (pas overwrite)
  4. UI réagit automatiquement (reactive)

RECONNEXION :
  MAX_RECONNECT = 5, backoff 2s→4s→8s→16s→30s
  Après échec total → polling fallback + banner "Mode dégradé"
  Dedup par event UUID (Set, max 1000 entries)
```

## 6.3 Règle ASYNC — Lifecycle d'un écran

```
PHASE 1 — MOUNT
  Si store._loaded === false :
    → Afficher PageSkeleton (skeleton adapté au layout)
    → store.fetchList()
  Si store._loaded === true :
    → Afficher données immédiatement (cache)
    → store.fetchList({ silent: true }) en background

PHASE 2 — LOADED
  Si store._error :
    → Afficher ErrorBanner avec message + RetryButton
  Si store._items.length === 0 :
    → Afficher EmptyState avec CTA contextuel
  Sinon :
    → Afficher contenu normal

PHASE 3 — INTERACTION
  Bouton action → :loading="true" immédiatement
  Si optimistic possible → update UI (grisé/pending)
  API response → confirmer ou rollback
  Toast feedback

PHASE 4 — BACKGROUND
  SSE ou polling → smart merge silencieux
  Pas de skeleton, pas de flash
  Si nouvelle donnée → insertion douce (transition)
```

## 6.4 Règle ERRORS — Jamais silencieux

```
ERREUR RÉSEAU (fetch échoue) :
  → ErrorBanner INLINE (pas toast — trop éphémère)
  → Message : "Impossible de charger les données"
  → Bouton : "Réessayer"

ERREUR VALIDATION (422) :
  → Champs en rouge + :error-messages
  → Focus sur premier champ en erreur
  → Toast optionnel "Corrigez les champs"

ERREUR ACTION (500) :
  → Toast error "L'opération a échoué"
  → Si optimistic : ROLLBACK visible
  → Bouton reste cliquable

ERREUR PERMISSION (403) :
  → Message inline "Vous n'avez pas la permission"
  → JAMAIS silencieux

ERREUR SESSION (401) :
  → SessionExpiredDialog (existant)
  → Bloque toute interaction

RÈGLE D'OR :
  Si l'utilisateur ne voit rien → c'est un bug.
  Chaque état (loading, error, empty, data) DOIT avoir un rendu visible.
```

---

# 7. ROADMAP SPRINTABLE

## Sprint V2-1 — Fondations (2 semaines)

**Thème :** Mettre tous les stores et pages au standard V2 minimum.

| # | Tâche | Composants | Pages impactées | Effort |
|---|-------|-----------|----------------|--------|
| 1 | Refaire tous les stores avec _loading/_error/_loaded | — | TOUTES | 3j |
| 2 | Créer `PageSkeleton` (list, detail, form variants) | PageSkeleton | TOUTES | 1j |
| 3 | Créer `ErrorBanner` + `RetryButton` | ErrorBanner, RetryButton | TOUTES | 1j |
| 4 | Généraliser `EmptyState` sur toutes les listes | EmptyState | 8 pages | 1j |
| 5 | Généraliser `StatusChip` (ajouter domains shipments, support, documents) | StatusChip | 4 pages | 0.5j |
| 6 | Créer `useListPage` composable | — | — | 1j |
| 7 | Appliquer skeleton + error + empty sur toutes les pages LIST | — | 6 pages | 2j |

**Gain :** Aucune page blanche. Aucun état silencieux. Loading visible partout.

**Dépendances :** Aucune — peut commencer immédiatement.

---

## Sprint V2-2 — Interactivité (2 semaines)

**Thème :** Bulk actions, filtres, search, SSE sur les modules clés.

| # | Tâche | Composants | Pages impactées | Effort |
|---|-------|-----------|----------------|--------|
| 1 | Créer `FilterBar` réutilisable | FilterBar | — | 1.5j |
| 2 | Créer `BulkToolbar` réutilisable | BulkToolbar | — | 1.5j |
| 3 | Appliquer FilterBar + BulkToolbar sur Documents requests | — | documents | 1j |
| 4 | Appliquer FilterBar sur Members, Audit, Support | — | 3 pages | 1.5j |
| 5 | Search ⌘K company (adapter NavSearchBar) | NavSearchBar company | global | 2j |
| 6 | Breadcrumbs (composable + layout) | useBreadcrumbs | global | 1j |
| 7 | SSE sur membersStore + shipmentStore | — | members, shipments | 2j |
| 8 | `InlineProgress` pour AI documents | InlineProgress | documents | 1j |

**Gain :** Listes interactives. Recherche globale. Temps réel sur 3 modules.

**Dépendances :** V2-1 (stores conformes requis pour SSE).

---

## Sprint V2-3 — Pages riches (2 semaines)

**Thème :** Pages detail, dashboard refonte, profils.

| # | Tâche | Composants | Pages impactées | Effort |
|---|-------|-----------|----------------|--------|
| 1 | Créer `StatusTimeline` | StatusTimeline | — | 1.5j |
| 2 | Page Document Detail (/documents/[id]) | DocumentViewer | documents | 3j |
| 3 | Enrichir Member Detail (bio panel + timeline) | BioPanel, ActivityFeed | members/[id] | 2j |
| 4 | Dashboard Home refonte (KPI + actions + feed) | ActionCenter, ActivityFeed | home | 3j |
| 5 | `ComplianceBreakdown` widget | ComplianceBreakdown | home, documents | 1.5j |
| 6 | SSE sur supportStore + billingStore | — | support, billing | 1.5j |
| 7 | `DirtyGuard` sur formulaires Settings + Profile | DirtyGuard | settings, profile | 1j |

**Gain :** Pages detail complètes. Dashboard utile. Compliance visible. Dirty check.

**Dépendances :** V2-2 (FilterBar/BulkToolbar requis pour les listes dans pages detail).

---

## Sprint V2-4 — Polish & Onboarding (2 semaines)

**Thème :** Onboarding, command bar, temps réel complet, export.

| # | Tâche | Composants | Pages impactées | Effort |
|---|-------|-----------|----------------|--------|
| 1 | `OnboardingChecklist` | OnboardingChecklist | home | 2j |
| 2 | `ConnectionIndicator` SSE | ConnectionIndicator | navbar | 0.5j |
| 3 | SSE sur auditStore | — | audit | 0.5j |
| 4 | Export CSV sur Members, Audit | — | members, audit | 1.5j |
| 5 | Export PDF compliance | — | documents | 1j |
| 6 | Optimistic updates (members add, support send) | — | members, support | 1.5j |
| 7 | `useStatusColors` composable centralisé | — | toutes pages avec status | 1j |
| 8 | Améliorer toast avec severity icons | — | global | 0.5j |
| 9 | Bulk import CSV members | — | members | 1.5j |
| 10 | Platform : activer bulk actions (users, companies) | — | platform | 1j |

**Gain :** Onboarding jour 1. Export compliance. Temps réel complet. UX polie.

**Dépendances :** V2-3.

---

## Résumé roadmap

| Sprint | Durée | Composants créés | Pages impactées | Gain principal |
|--------|-------|-----------------|----------------|----------------|
| **V2-1** | 2 sem | 4 nouveaux + 2 enrichis | TOUTES | Zéro page blanche |
| **V2-2** | 2 sem | 4 nouveaux | 6 pages + global | Listes interactives + search |
| **V2-3** | 2 sem | 5 nouveaux | 4 pages | Pages riches + dashboard |
| **V2-4** | 2 sem | 2 nouveaux | 6 pages + global | Onboarding + polish |
| **TOTAL** | **8 sem** | **15 composants** | **~15 pages** | **Frontend SaaS complet** |

---

# ANNEXE A — Mapping Preset Vuexy → Composant V2

| Composant V2 | Preset Vuexy source | Chemin |
|-------------|---------------------|--------|
| FilterBar | User list toolbar | `presets/pages/templates/apps/user/list/index.vue` |
| StatusTimeline | Activity Timeline card | `presets/cards/card-advance/CardAdvanceActivityTimeline.vue` |
| ActivityFeed | CRM Activity Timeline | `presets/dashboards/crm/CrmActivityTimeline.vue` |
| OnboardingChecklist | Assignment Progress | `presets/cards/card-advance/CardAdvanceAssignmentProgress.vue` |
| DocumentViewer | Email split view | `presets/apps/email/EmailView.vue` |
| KPI cards | Ecommerce Statistics | `presets/dashboards/ecommerce/EcommerceStatistics.vue` |
| ComplianceBreakdown | Generated Leads stats | `presets/cards/card-statistics/CardStatisticsGeneratedLeads.vue` |
| Member Detail | Customer Detail | `presets/pages/templates/apps/ecommerce/customer/details/[id].vue` |
| Wizard/Steps | Checkout wizard | `presets/pages/templates/wizard-examples/checkout.vue` |
| ConnectionIndicator | Dynamic Badge | `presets/molecules/badge/DemoBadgeDynamicNotifications.vue` |

---

# ANNEXE B — Mapping Store → SSE Topics V2

| Store | Topic SSE | Events |
|-------|-----------|--------|
| documentsStore | `company.{id}.documents` | document.created, document.updated, document.ai_step, document.reviewed |
| membersStore | `company.{id}.members` | member.joined, member.updated, member.removed |
| shipmentStore | `company.{id}.shipments` | shipment.created, shipment.status_changed, shipment.assigned |
| supportStore | `company.{id}.support` | ticket.created, ticket.updated, ticket.message.created |
| billingStore | `company.{id}.billing` | subscription.updated, payment.completed, invoice.created |
| auditStore | `company.{id}.audit` | audit.entry.created |
| homeStore | `company.{id}.*` | Agrège tous les events pour KPI + feed |

---

# ANNEXE C — Checklist de conformité V2 par page

Pour chaque page company, vérifier :

```
☐ Layout standard identifié (LIST/DASHBOARD/DETAIL/SPLIT/SETTINGS)
☐ Store conforme (_loading, _error, _loaded, smart merge, SSE handler)
☐ PageSkeleton au premier chargement
☐ ErrorBanner si store._error
☐ EmptyState si liste vide
☐ RetryButton sur erreurs
☐ StatusChip (pas de couleurs hardcodées)
☐ FilterBar si page LIST
☐ BulkToolbar si sélection multiple
☐ Breadcrumbs visibles
☐ SSE connecté pour les données mutables
☐ DirtyGuard si formulaire d'édition
☐ i18n complet (pas de strings hardcodées)
☐ Responsive mobile testé
```
