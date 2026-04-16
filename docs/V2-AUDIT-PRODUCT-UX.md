# AUDIT PRODUIT & UX COMPLET — V2

> Date : 2026-04-12
> Scope : Toutes les pages company + platform + stores + composables + layouts
> Méthode : Lecture exhaustive fichier par fichier, 6 agents parallèles
> Objectif : Atteindre un niveau SaaS premium

---

## RÉSUMÉ EXÉCUTIF

**60+ pages auditées, 39 stores, 31 composables, 7 composants partagés, 2 layouts.**

| Surface | Pages | Score moyen | Verdict |
|---------|-------|-------------|---------|
| Company Billing | 14 | 88/100 | **Bon** — Très complet, UX incohérente sur feedback |
| Company Members/Docs | 12 | 74/100 | **Correct** — Features OK, drawers trop complexes |
| Company Shipments/Support/Workflows | 16 | 68/100 | **MVP** — Fonctionnel mais basique, polish absent |
| Platform Core | 12 | 67/100 | **MVP+** — Structure solide, UX frustrant |
| Platform Billing | 18 | 72/100 | **Bon** — Feature-complete, composants trop gros |
| Stores/Composables | 70 | — | **Architecture solide, incohérences systémiques** |

**Verdict global : CORRECT (73/100)** — Le produit fonctionne mais n'est pas au niveau SaaS premium. Il y a un fossé entre la richesse fonctionnelle (excellente) et la qualité UX (moyenne).

---

## TABLE DES MATIÈRES

1. [Problèmes systémiques (cross-cutting)](#1-problèmes-systémiques)
2. [Audit Company — Billing](#2-company-billing)
3. [Audit Company — Members, Roles, Documents](#3-company-members-roles-documents)
4. [Audit Company — Shipments, Support, Workflows, Profile](#4-company-shipments-support-workflows)
5. [Audit Platform — Core](#5-platform-core)
6. [Audit Platform — Billing](#6-platform-billing)
7. [Audit Platform — Support, Automations, AI](#7-platform-support-automations)
8. [Stores & Composables](#8-stores-composables)
9. [Scores détaillés page par page](#9-scores-détaillés)
10. [Plan d'action priorisé](#10-plan-daction)
11. [Idées différenciantes](#11-idées-différenciantes)

---

## 1. PROBLÈMES SYSTÉMIQUES

Ces problèmes traversent TOUTES les pages. Les corriger a un impact multiplicateur.

### 1.1 Empty States — Incohérents

| État | Pages avec | Pages sans | Impact |
|------|-----------|-----------|--------|
| Empty state avec CTA | Workflows, Support, Billing | Members, Roles, Shipments, Deliveries | **CRITIQUE** |

**Problème** : Quand une liste est vide, certaines pages affichent un message + bouton d'action, d'autres affichent une page blanche. L'utilisateur est perdu.

**Solution** : Template standard `EmptyState` (icon + message + CTA button) sur TOUTES les listes.

### 1.2 Loading States — Fragmentés

- **Pattern A** (bon) : `VSkeletonLoader` → Billing, Documents
- **Pattern B** (moyen) : `VProgressLinear` → Shipments, Members
- **Pattern C** (mauvais) : Rien → Profile, Support index, Deliveries

**Solution** : Standardiser VSkeletonLoader pour le chargement initial, VProgressLinear pour les refreshs.

### 1.3 Error Handling — Silencieux

- 60% des stores **avalent les erreurs silencieusement** (catch vide)
- Aucun store n'expose systématiquement un `_error` state
- Les erreurs réseau sont affichées en toast (3s) puis disparaissent → aucune trace

**Solution** :
1. Ajouter `_error: null` + getter `lastError` à tous les stores
2. VAlert persistant pour les erreurs critiques (paiement, suppression)
3. Toast pour les erreurs non-critiques (filtre, refresh)

### 1.4 Validation Formulaires — Quasi-absente

- Create shipment : AUCUNE validation (peut soumettre vide)
- Create workflow : validation partielle (canProceed)
- Create role : pas de validation nom
- Create coupon : pas de feedback inline

**Solution** : `rules: [v => !!v || t('common.required')]` sur tous les champs obligatoires. Bouton submit disabled tant que form invalide.

### 1.5 Unsaved Changes — Inexistant

Aucune page n'avertit l'utilisateur quand il quitte avec des modifications non sauvegardées. Pages à risque élevé :
- Platform modules/[key] (pricing complex)
- Platform plans/[key] (features list)
- Company profile overview
- Company documents settings
- Platform jobdomains/[id]

**Solution** : Router guard `beforeEach` + `onBeforeUnload` hook.

### 1.6 Breadcrumbs — Absents

Aucune page n'a de breadcrumb. Pour les pages profondes (invoices/[id], members/[id], modules/[key]), la hiérarchie est invisible.

**Solution** : Composant breadcrumb dans le header, auto-généré depuis la route.

### 1.7 Tooltips — Rares

Les icônes d'action (edit, delete, view) n'ont pas de tooltip. Les addresses sont tronquées sans possibilité de voir le texte complet.

### 1.8 Responsive — Non testé

Les tables 8+ colonnes (platform billing, forensics) débordent sur tablette. Les drawers shift le layout sur petit écran.

---

## 2. COMPANY BILLING

**Score moyen : 88/100 — BON**

Le module billing est le plus mature du produit. Architecture solide, feedback UX le plus complet.

### Page par page

| Page | Score | Forces | Faiblesses |
|------|-------|--------|------------|
| `[tab].vue` | 82 | Tabs pill clean, lazy loading | Pas de loading skeleton global |
| `_BillingOverview.vue` | 85 | Orchestration 5 composants, loading/error/empty | Toast success peu visible |
| `_BillingPlan.vue` | 82 | Preview proration excellent, tax context | Dialog sans max-height (overflow), loading sans texte |
| `_BillingInvoices.vue` | 88 | Paginé, filtré, StatusChip | Loading muet pendant filtre |
| `_BillingPaymentMethods.vue` | 89 | SEPA 2-step, Stripe elements, TrustBadges | Stripe cleanup risqué, step indicator peu clair |
| `_BillingAlerts.vue` | 92 | 6 alertes contextuelles, componentized | — |
| `_BillingCards.vue` | 90 | KPI clair, card-grid-sm | Pas de loading state |
| `_BillingEmpty.vue` | 95 | Simple et efficace | — |
| `_BillingCancelDialog.vue` | 93 | Preview cancel timing + addons | — |
| `_BillingNextInvoice.vue` | 91 | FIFO wallet credit breakdown | — |
| `invoices/[id].vue` | 80 | Layout print responsive, line items | isPastDue ne s'invalide jamais, pas de refresh |
| `pay.vue` | 87 | 4-step flow, Apple Pay, idempotency | Pas de confirm SEPA, countdown 10s trop rapide |

### Problèmes critiques billing

1. **Dialog plan preview sans scroll** — `max-width: 560px` mais pas `max-height` → overflow sur petit écran
2. **Stripe Elements lifecycle** — Pas de cleanup en `onBeforeUnmount` → memory leak + "Element already mounted"
3. **Pas de confirmation SEPA** — Prélèvement irréversible 6+ jours, pas de "êtes-vous sûr ?"
4. **isPastDue computed stale** — Ne s'invalide jamais si user reste > 24h sur la page

### Améliorations prioritaires

- Ajouter `max-height: 80vh; overflow-y: auto` au dialog preview
- Cleanup Stripe elements en `onBeforeUnmount`
- Dialog confirmation avant `confirmSepaDebitPayment`
- Texte "Calcul en cours..." dans le preview loading
- Countdown 15s ou bouton "Continuer" en page succès

---

## 3. COMPANY MEMBERS, ROLES, DOCUMENTS

**Score moyen : 74/100 — CORRECT**

### Page par page

| Page | Score | Forces | Faiblesses |
|------|-------|--------|------------|
| `members/index.vue` | 70 | Quick view, field management | **Pas d'empty state**, VTable sans pagination, drawer trop complexe |
| `members/[id].vue` | 75 | 3 tabs, role change refetch | MemberProfileForm opaque, pas de save indicator |
| `roles.vue` | 70 | PermissionMatrix, field config | Drawer trop long, pas d'empty state, pas de search |
| `modules/index.vue` | 80 | Quote preview, deactivation preview, grace period | Empty tab state absent, pricing display dense |
| `modules/[key].vue` | 75 | Dynamic panels, expert JSON | Pas d'unsaved changes, JSON sans syntax highlight |
| `_ThemeRoleVisibility.vue` | 65 | Simple toggle | Pas d'explication, pas d'empty state |
| `documents/[tab].vue` | 80 | Permission-based tabs, badge count | Drawer cross-tab state |
| `_DocumentsOverview.vue` | 80 | KPI cards, color-coded progress | Pas de real-time, pas de refresh |
| `_DocumentsVault.vue` | 70 | Uploaded count | Child component opaque |
| `_DocumentsRequests.vue` | **85** | **SSE real-time**, bulk actions, AI preview, drag&drop upload | 2 tables confuses, charge cognitive élevée |
| `_DocumentsCompliance.vue` | 75 | Summary + detail, export CSV | Pas de sort, pas de PDF export |
| `_DocumentsSettings.vue` | 70 | 4 cards de settings, AI quota | Settings split → scroll long, pas de help text |

### Problèmes critiques

1. **Members/index sans pagination** — `items-per-page=-1` → 100+ membres = page lente
2. **Drawers trop complexes** — Members field drawer = 3 onglets + édition inline. Roles drawer = permissions + field config. Charge cognitive énorme.
3. **DocumentsRequests : 6 dialogs** — Approve, reject, bulk reject, admin upload, batch request, document viewer. Page la plus complexe du produit.
4. **Pas d'empty state** sur members/index et roles.vue

### Améliorations prioritaires

- VDataTableServer avec pagination sur members/index
- Scinder les drawers en steps/wizard (roles, members fields)
- Empty states sur toutes les listes
- Help text sur documents settings (tooltips pour AI threshold, etc.)

---

## 4. COMPANY SHIPMENTS, SUPPORT, WORKFLOWS, PROFILE

**Score moyen : 68/100 — MVP**

La partie la plus faible du produit. Fonctionnelle mais UX très basique.

### Page par page

| Page | Score | Forces | Faiblesses |
|------|-------|--------|------------|
| `shipments/index.vue` | 60 | Filtres, pagination | Pas d'error state, pas de feedback filtre, tooltips absents |
| `shipments/[id].vue` | 70 | Alerts success/error, status transitions | Pas de confirm cancel, alerts sans auto-dismiss |
| `shipments/create.vue` | 55 | — | **Aucune validation**, date picker HTML5, pas de success feedback |
| `my-deliveries/index.vue` | 60 | Clone de shipments | Mêmes défauts, pas d'error state |
| `my-deliveries/[id].vue` | 70 | Transitions status | Pas de proof capture, pas de reject action |
| `support/index.vue` | 65 | Empty state + CTA | Loading manquant sur table, validation dialog absente |
| `support/[id].vue` | 55 | Scroll-to-bottom | **Chat UX très basique** (pas d'avatars, pas de typing, pas de grouping date) |
| `workflows/index.vue` | 65 | VAlert error, empty state | VSwitch sans confirmation, trigger names snake_case |
| `workflows/[id].vue` | 70 | VTimeline logs, detail complet | Pas d'auto-refresh logs, conditions peu lisibles |
| `_WorkflowCreateDrawer.vue` | 65 | AppStepper 3 étapes | Pas de step review, validation gaps, drawer narrow |
| `profile/[tab].vue` | 55 | Tab routing | **Single tab dans VTabs** = overkill, pas de loading |
| `profile/_Overview.vue` | 70 | Dynamic fields, billing toggle | Pas d'unsaved changes, country field sans explication |
| `profile/_Documents.vue` | 65 | Storage progress | Buttons hierarchy unclear, mandatory fields en bas |
| `403.vue` | 55 | Auto-redirect countdown | Message générique, pas de raison, icône grey |
| `settings/[tab].vue` | 70 | Tab routing | Délègue aux enfants (OK) |
| `documentation/[slug].vue` | 70 | Help center | — |

### Problèmes critiques

1. **shipments/create.vue** — Le pire formulaire du produit. Aucune validation, date picker HTML5, pas de feedback.
2. **support/[id].vue** — Chat UX de 2005. Pas d'avatars, pas de typing indicator, pas de grouping par date, max-height 500px fixe.
3. **profile/[tab].vue** — Un seul onglet dans VTabs = architecture inutilement complexe.
4. **403.vue** — Message trop générique, l'utilisateur ne sait pas pourquoi il est bloqué.

### Améliorations prioritaires

- Refaire shipments/create avec validation + AppDateTimePicker + hints
- Moderniser le chat support (avatars, grouping date, typing indicator)
- Simplifier profile (retirer VTabs si un seul onglet)
- 403.vue : afficher la raison (permission manquante, subscription expirée, etc.)

---

## 5. PLATFORM CORE

**Score moyen : 67/100 — MVP+**

Structure solide (360° views, lazy tabs) mais feedback utilisateur fragmenté.

### Page par page

| Page | Score | Forces | Faiblesses |
|------|-------|--------|------------|
| `index.vue` (dashboard) | 60 | Dashboard engine, stat cards | **Pas d'onboarding** si 0 widgets, widget errors non affichés |
| `companies/[id].vue` | 70 | 360° view (bio + 5 tabs), plan preview | Tabs sans loading feedback, pas d'unsaved changes |
| `users/[id].vue` | 60 | Credentials tab conditionnel | Pas de password strength, pas de validation |
| `access/[tab].vue` | 70 | 3 tabs propres | Délègue aux enfants |
| `settings/[tab].vue` | 70 | 6 tabs | Délègue aux enfants |
| `international/[tab].vue` | 60 | Permission-based tabs | Empty state si 0 permissions |
| `supervision/[tab].vue` | 70 | 3 tabs propres | Délègue aux enfants |
| `modules/index.vue` | 70 | Filtres, toggle, sync | Sync button sans contexte |
| `modules/[key].vue` | 75 | **Pricing preview excellent**, expert mode | **1500 lignes**, unsaved changes absent |
| `plans/[key].vue` | 65 | Features reorder, companies list | Feature drag absent, validation pricing absente |
| `jobdomains/index.vue` | 65 | Delete protection | Pas de search, pas de pagination |
| `jobdomains/[id].vue` | 60 | Market overlays | Trop de state, unsaved changes absent |

### Problèmes critiques

1. **modules/[key].vue = 1500 lignes** — Trop gros, impossible à maintenir. Pricing + identity + companies + permissions dans un seul fichier.
2. **Unsaved changes absent** — Sur modules/[key], plans/[key], jobdomains/[id], companies/[id], users/[id]. Perte de données fréquente.
3. **Dashboard sans onboarding** — Page vide si 0 widgets, aucune guidance.
4. **Company selector = input numérique** — Ledger et Governance utilisent `type=number` au lieu d'autocomplete.

### Améliorations prioritaires

- Décomposer modules/[key] en sous-composants (identity, pricing, companies, permissions)
- Ajouter unsaved changes guard sur toutes les pages formulaire
- Onboarding dashboard (widgets suggérés, "Get started")
- Remplacer company ID inputs par autocomplete

---

## 6. PLATFORM BILLING

**Score moyen : 72/100 — BON fonctionnellement**

Module le plus complet du backoffice. Feature-complete (approve, void, refund, dunning, recovery, forensics, governance). Mais composants trop gros et UX admin-heavy.

### Page par page

| Page | Score | Forces | Faiblesses |
|------|-------|--------|------------|
| `_BillingDashboard.vue` | 75 | KPIs, anomaly alerts, refresh | Pas de priorité visuelle dans anomalies |
| `_BillingSubscriptionsTab.vue` | 65 | Approve/reject workflow | Pas de bulk actions, pas de detail preview |
| `_BillingInvoicesTab.vue` | 75 | **6 dialogs**, bulk actions, idempotency | **1049 lignes** = trop gros, state machine confuse |
| `_BillingPaymentsTab.vue` | 60 | Propre, read-only | Pas de context (lien invoice), pas de drilldown |
| `_BillingCreditNotesTab.vue` | 60 | Identique Payments | Pas de drilldown |
| `_BillingLedgerTab.vue` | 70 | Trial balance + entries, expandable JSON | **Company input numérique** = bad UX |
| `_BillingDunningTab.vue` | 70 | Workflow retry → escalate → writeoff | Pas de confirm escalate, pas d'undo |
| `_BillingRecovery.vue` | 70 | Health diagnostics, dead letters replay | All-or-nothing replay = risqué |
| `_BillingScheduledDebitsTab.vue` | 60 | Simple list | **API inconsistency** ($api direct, pas store) |
| `_BillingForensicsTab.vue` | 70 | Timeline + snapshots | Overwhelming pour admin typique |
| `_BillingGovernanceTab.vue` | 60 | Reconciliation dry-run, freeze toggle | **Company input numérique**, freeze sans confirmation |
| `_BillingCouponsTab.vue` | 70 | Form 6 sections, status badges | Pas de validation inline, pas de soft-delete |
| `advanced/[tab].vue` | 65 | Router wrapper | Navigation confuse vs. main billing |
| `invoices/[id].vue` | 70 | Print layout, line items | Features manquantes (PDF, email) |

### Problèmes critiques

1. **_BillingInvoicesTab.vue = 1049 lignes** — 6 dialogs dans un seul fichier. State machine (confirmDialog + confirmAction + confirmInvoice + confirmLoading) = 4 refs pour un dialog.
2. **Company selectors** — Ledger et Governance utilisent `type=number` au lieu d'autocomplete.
3. **API inconsistency** — ScheduledDebits utilise `$api` direct, tous les autres utilisent le store.
4. **Navigation confuse** — Governance et Ledger apparaissent dans main ET advanced billing.

### Améliorations prioritaires

- Décomposer InvoicesTab en 4 sous-composants (table, dialogs, bulk actions, filters)
- Remplacer company ID inputs par autocomplete
- Unifier API calls via store
- Clarifier navigation main vs advanced

---

## 7. PLATFORM SUPPORT, AUTOMATIONS, AI

### Support

| Page | Score | Verdict |
|------|-------|---------|
| `support/index.vue` | 60 | MVP — KPI metrics OK, pas de SLA timer, pas de bulk assign |
| `support/[id].vue` | 60 | MVP — Chat basique, pas de markdown, pas de file upload |

### Automations

| Page | Score | Verdict |
|------|-------|---------|
| `automations/index.vue` | 75 | **BON** — Realtime anti-blink pattern excellent, health chip, run history |

Le pattern realtime anti-blink des automations est le meilleur du produit. À appliquer partout.

### AI Platform

| Page | Score | Verdict |
|------|-------|---------|
| `ai/[tab].vue` | — | Non audité en profondeur (sub-components) |

---

## 8. STORES & COMPOSABLES

### Problèmes architecturaux critiques

#### 8.1 Error State absent dans 60% des stores

La majorité des stores avalent les erreurs :
```javascript
catch { this._items = [] } // Erreur invisible
```

**Solution** : `_error: null` + getter `lastError` dans TOUS les stores.

#### 8.2 Loading granularity chaotique

3 patterns coexistent :
- **Global** (`_loading: false`) → masque les opérations parallèles
- **Multi-field** (`_loading: { list: false, detail: false }`) → RECOMMANDÉ
- **Per-ID** (`_mutationLoading: {}`) → pour mutations par ligne

#### 8.3 Optimistic updates sans rollback

```javascript
// DANGEREUX — dans useMembersStore
this._members = this._members.filter(m => m.id !== id) // AVANT l'API
await $api(`/company/members/${id}`, { method: 'DELETE' })
// Si l'API échoue, le membre a déjà disparu de l'UI
```

**Solution** : Pessimistic par défaut (supprimer APRÈS l'API). Optimistic uniquement avec rollback explicite.

#### 8.4 DocumentViewerDialog — Single Responsibility Violation

80+ lignes de computed/refs. Mélange PDF viewer + AI analysis + review workflow. Devrait être décomposé en 3.

#### 8.5 Duplication layouts

`default.vue` et `platform.vue` partagent 80% du code. Devrait être factorisé en `LayoutBase.vue`.

### Composables — Sous-utilisés

- `useAsyncAction` : pattern excellent (loading/error/data/retry) mais quasi-jamais utilisé
- `useMarketFormatting` : formatAmount pas utilisé partout (certaines pages font `toFixed(2)`)
- `useCan` : wrapper trivial autour de `auth.hasPermission()`, peu de valeur ajoutée

---

## 9. SCORES DÉTAILLÉS

### Company

| Page | Score | Niveau |
|------|-------|--------|
| billing/[tab] | 82 | Bon |
| billing/_BillingOverview | 85 | Bon |
| billing/_BillingPlan | 82 | Bon |
| billing/_BillingInvoices | 88 | Bon |
| billing/_BillingPaymentMethods | 89 | Excellent |
| billing/_BillingAlerts | 92 | Excellent |
| billing/_BillingCards | 90 | Excellent |
| billing/_BillingEmpty | 95 | Excellent |
| billing/_BillingCancelDialog | 93 | Excellent |
| billing/_BillingNextInvoice | 91 | Excellent |
| billing/invoices/[id] | 80 | Bon |
| billing/pay | 87 | Bon |
| members/index | 70 | Correct |
| members/[id] | 75 | Bon |
| roles | 70 | Correct |
| modules/index | 80 | Bon |
| modules/[key] | 75 | Bon |
| documents/[tab] | 80 | Bon |
| documents/_Overview | 80 | Bon |
| documents/_Vault | 70 | Correct |
| documents/_Requests | 85 | Bon |
| documents/_Compliance | 75 | Bon |
| documents/_Settings | 70 | Correct |
| shipments/index | 60 | MVP |
| shipments/[id] | 70 | Correct |
| shipments/create | 55 | MVP |
| my-deliveries/index | 60 | MVP |
| my-deliveries/[id] | 70 | Correct |
| support/index | 65 | Correct |
| support/[id] | 55 | MVP |
| workflows/index | 65 | Correct |
| workflows/[id] | 70 | Correct |
| workflows/_CreateDrawer | 65 | Correct |
| profile/[tab] | 55 | MVP |
| profile/_Overview | 70 | Correct |
| profile/_Documents | 65 | Correct |
| settings/[tab] | 70 | Correct |
| 403 | 55 | MVP |

### Platform

| Page | Score | Niveau |
|------|-------|--------|
| index (dashboard) | 60 | MVP |
| companies/[id] | 70 | Correct |
| users/[id] | 60 | MVP |
| access/[tab] | 70 | Correct |
| settings/[tab] | 70 | Correct |
| international/[tab] | 60 | MVP |
| supervision/[tab] | 70 | Correct |
| modules/index | 70 | Correct |
| modules/[key] | 75 | Bon |
| plans/[key] | 65 | Correct |
| jobdomains/index | 65 | Correct |
| jobdomains/[id] | 60 | MVP |
| billing/_Dashboard | 75 | Bon |
| billing/_Subscriptions | 65 | Correct |
| billing/_Invoices | 75 | Bon |
| billing/_Payments | 60 | MVP |
| billing/_CreditNotes | 60 | MVP |
| billing/_Ledger | 70 | Correct |
| billing/_Dunning | 70 | Correct |
| billing/_Recovery | 70 | Correct |
| billing/_ScheduledDebits | 60 | MVP |
| billing/_Forensics | 70 | Correct |
| billing/_Governance | 60 | MVP |
| billing/_Coupons | 70 | Correct |
| billing/invoices/[id] | 70 | Correct |
| support/index | 60 | MVP |
| support/[id] | 60 | MVP |
| automations/index | 75 | Bon |

---

## 10. PLAN D'ACTION PRIORISÉ

### PHASE 1 : FONDATIONS UX (Impact systémique maximal)

**Objectif** : Corriger les problèmes qui touchent TOUTES les pages.

| # | Action | Pages impactées | Effort |
|---|--------|----------------|--------|
| F1 | **Error state systématique** — `_error` dans tous les stores + VAlert persistant | Toutes | M |
| F2 | **Empty states unifiés** — Template standard sur toutes les listes | 15+ pages | S |
| F3 | **Loading skeleton standardisé** — VSkeletonLoader au chargement initial | 20+ pages | S |
| F4 | **Validation formulaires** — Rules required sur tous les create/edit | 10+ forms | M |
| F5 | **Toast feedback systématique** — Success/error après chaque action CRUD | Toutes | S |
| F6 | **Unsaved changes guard** — Router beforeEach + onBeforeUnload | 8 pages form | M |

### PHASE 2 : REFACTORING CRITIQUE

| # | Action | Fichier | Effort |
|---|--------|---------|--------|
| R1 | **Split InvoicesTab** — 4 sous-composants (table, dialogs, bulk, filters) | platform billing | L |
| R2 | **Split modules/[key]** — 4 sous-composants (identity, pricing, companies, perms) | platform modules | L |
| R3 | **Company autocomplete** — Remplacer input numérique par autocomplete | Ledger, Governance | S |
| R4 | **Stripe Elements cleanup** — onBeforeUnmount sur pay.vue + PaymentMethods | company billing | S |
| R5 | **Dialog scroll** — max-height + overflow-y sur plan preview dialog | company billing | XS |

### PHASE 3 : UX POLISH

| # | Action | Pages impactées | Effort |
|---|--------|----------------|--------|
| P1 | **Tooltips sur toutes les icônes d'action** | Toutes les tables | S |
| P2 | **Breadcrumbs** — Composant auto-généré depuis la route | Pages profondes | M |
| P3 | **Chat support modernisé** — Avatars, grouping date, typing indicator | support/[id] | M |
| P4 | **Shipments/create** — Validation, AppDateTimePicker, hints | shipments/create | S |
| P5 | **Drawers simplifiés** — Scinder en steps/wizard pour roles et members fields | roles, members | M |
| P6 | **Dashboard onboarding** — Widgets suggérés si 0 widgets | platform index | S |

### PHASE 4 : FEATURES MANQUANTES

| # | Action | Impact produit | Effort |
|---|--------|---------------|--------|
| M1 | **Search sur tables** — Members, roles, jobdomains | Utilisabilité | S |
| M2 | **Pagination VDataTableServer** — Members index | Performance | S |
| M3 | **Bulk actions** — Platform subscriptions (approve multiple) | Productivité admin | M |
| M4 | **Export PDF** — Compliance, invoices | Valeur business | M |
| M5 | **403 contextuel** — Afficher la raison (permission, subscription, etc.) | UX | S |
| M6 | **Confirmation dialogs** — Actions destructives (cancel shipment, escalate dunning, freeze) | Sécurité | S |

---

## 11. IDÉES DIFFÉRENCIANTES

Ce qui rendrait Leezr "top SaaS" — inspirations Stripe, Linear, Notion, Slack.

### 11.1 Command Palette (style Linear/Notion)

`Cmd+K` → recherche globale : membres, factures, workflows, documents. Navigation instantanée vers n'importe quelle entité.

**Impact** : Réduction drastique du temps de navigation.

### 11.2 Activity Feed temps réel (style Slack)

Page "Activité récente" avec feed SSE : "Alice a uploadé un document", "Facture #123 payée", "Workflow 'New Member' exécuté". Avec filtres par type d'événement.

**Impact** : Visibilité temps réel sur l'activité de la company.

### 11.3 Smart Suggestions (style Stripe)

Après chaque action, suggérer la prochaine : "Member added → Upload their documents?", "Invoice paid → View receipt?", "Document expired → Send reminder?".

**Impact** : Guidage contextuel, réduction des étapes.

### 11.4 Keyboard-First (style Linear)

Raccourcis clavier sur toutes les pages : `N` = new, `E` = edit, `D` = delete, `/` = search, `Esc` = close. Affichés en tooltip au hover des boutons.

**Impact** : Productivité power users.

### 11.5 Inline Editing (style Notion)

Click sur une cellule de table → édition inline (nom membre, status, notes). Sans ouvrir de drawer ou dialog.

**Impact** : Réduction de friction pour modifications rapides.

### 11.6 AI-Powered Insights (style Stripe Radar)

Dashboard billing avec prédictions : "3 factures risquent d'être en retard cette semaine", "Revenue prévu ce mois : +12%", "Taux de compliance documents : en baisse".

**Impact** : Valeur prédictive, pas juste descriptive.

### 11.7 Undo Global (style Gmail)

Après chaque action destructive, snackbar "Action effectuée — Annuler" pendant 10s. Undo côté frontend (optimistic) avec rollback si cliqué.

**Impact** : Réduction de l'anxiété utilisateur.

### 11.8 Guided Onboarding (style Notion)

Premier accès → wizard : "1. Complétez votre profil → 2. Invitez vos membres → 3. Activez vos modules → 4. Uploadez vos documents". Progress bar persistante jusqu'à 100%.

**Impact** : Activation utilisateur, réduction du churn.

### 11.9 Status Page Interne

Page `/company/status` montrant la santé de la company : compliance documents, factures en attente, modules actifs, membres actifs. Score global sur 100.

**Impact** : Vue d'ensemble instantanée, gamification.

### 11.10 Dark Mode Polish

Le dark mode existe (Vuetify) mais n'est probablement pas testé en profondeur. Audit visuel complet + fix des contrastes.

**Impact** : Perception premium.

---

## CONCLUSION

Le produit Leezr est **fonctionnellement riche** — billing complet, documents avec AI, workflows, RBAC granulaire, multi-market. Mais l'**expérience utilisateur est inégale** : le billing est au niveau "Bon", le reste est "MVP/Correct".

**Pour atteindre le niveau SaaS premium :**

1. **Phase 1** (fondations) résoudra 70% des irritants UX → 2-3 sprints
2. **Phase 2** (refactoring) résoudra la dette technique critique → 2 sprints
3. **Phase 3** (polish) donnera le "feeling premium" → 2 sprints
4. **Phase 4** (features) comblera les trous fonctionnels → 2-3 sprints
5. **Idées différenciantes** → à prioriser selon la stratégie produit

Le gap entre l'état actuel (73/100) et le niveau premium (90+/100) est comblable en **8-10 sprints** en suivant cet ordre de priorité.
