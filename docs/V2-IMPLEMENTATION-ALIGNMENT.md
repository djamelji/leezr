# V2 — Alignement Implémentation ↔ Documentation

> Date : 2026-04-15
> Méthode : Comparaison factuelle de chaque promesse V2 avec l'état réel du code

---

## 1. Inventaire des documents V2

| # | Document | Portée | Pages |
|---|----------|--------|-------|
| 1 | V2-AUDIT-VISION | Phases techniques, 14 semaines, stabilisation | Vision |
| 2 | V2-VISION-PRODUIT | OS for Business Ops, 8 engines, 24 semaines | Vision |
| 3 | V2-GO-TO-MARKET | Wedge compliance, plans Starter/Pro/Scale | Stratégie |
| 4 | V2-DESIGN-STANDARDS | Spacing, density, states, feedback, realtime UX | Standards |
| 5 | V2-AUDIT-FRONTEND-UX | Score 5.5→9/10, 4 phases, anti-blink | Audit |
| 6 | V2-FRONTEND-PRODUIT | 11 pages auditées, 50 problèmes, 14 composants manquants | Audit |
| 7 | V2-BLUEPRINT-FRONTEND | 5 layouts, 15 pages, 15 composants, 6 composables | Blueprint |
| 8 | V2-AUDIT-PRODUCT-UX | Score 73/100, 60+ pages, 8 problèmes systémiques | Audit |
| 9 | V2-AUDIT-PLATFORM-UX | Verdicts page par page, navigation cible 7 groupes | Audit |
| 10 | V2-AUDIT-PLATFORM-PRODUCT | Audit produit platform post-implémentation | Audit |
| 11 | V2-PLATFORM-PAGES-MANQUANTES | 11 pages platform manquantes identifiées | Gap |
| 12 | V2-PLATFORM-SYSTEMS-MANQUANTS | 9 systèmes transverses manquants | Gap |
| 13 | V2-PLATFORM-ROADMAP | Lot 1 (10-14j) + Lot 2 (12-18j), score 62→88 | Roadmap |
| 14 | V2-SAFETY-GOVERNANCE-PLAN | Billing safety, email, action governance | Plan |
| 15 | V2-AUDIT-TENANCY | BelongsToCompany, CompanyScope, 40+ models | Technique |
| 16 | V2-AUDIT-RBAC | v-can directive, useCan, 403 contextuel | Technique |
| 17 | V2-AUDIT-REALTIME | 22 SSE topics, connection indicator, polling fallback | Technique |
| 18 | V2-AUDIT-MULTI-MARKET | useMarketFormatting, FX rates ECB, applyMarket | Technique |
| 19 | V2-AUDIT-AI-ENGINE | AiModuleContract, quotas, cost tracking | Technique |
| 20 | V2-AUDIT-AUTOMATION | WorkflowRule, TriggerRegistry, ConditionEvaluator | Technique |
| 21 | V2-SPRINT-1-EXECUTION | PageSkeleton, ErrorBanner, 12 stores, 8 pages | Exécution |
| 22 | V2-BACKLOG-EXECUTION | 57 tasks, 38.5 days, ADR-432→437 | Exécution |

---

## 2. État réel d'implémentation

### 2.1 Inventaire pages

| Surface | Pages routables | Sous-composants | Total |
|---------|----------------|-----------------|-------|
| Platform | 34 | 64 | 98 |
| Company | 23 | 23 | 46 |
| **Total** | **57** | **87** | **144** |

### 2.2 Stores Pinia

| Emplacement | Nombre | Noms |
|-------------|--------|------|
| `core/stores/` | 7 | auth, nav, notification, module, world, platformAuth, auditLive |
| Page-level | 0 | (inline dans pages via API directes) |

### 2.3 Notifications email

| Type | Nombre | Queue (ShouldQueue) | Template Blade | i18n |
|------|--------|---------------------|----------------|------|
| Billing | 10 | NON | NON (MailMessage) | NON (anglais hardcodé) |
| Critical | 1 | NON | NON | NON |
| **Total** | **11** | **0/11** | **0/11** | **0/11** |

### 2.4 Patterns UX V2 adoptés

| Pattern | Existe | Utilisé sur company | Utilisé sur platform |
|---------|--------|---------------------|---------------------|
| useAsyncAction | OUI | 5 pages | 0 pages |
| ErrorState | OUI | 5 pages | 0 pages |
| PageBreadcrumbs | OUI | 3 pages (invoice, support, shipment create) | 0 pages |
| useUnsavedChanges | OUI | 1 page (shipments/create) | 0 pages |
| AppTooltipHelp | OUI | 1 page (shipments/create) | 0 pages |
| EmptyState | OUI | ~14 pages | ~5 pages |
| useAppToast | OUI | ~10 pages | ~8 pages |
| VSkeletonLoader | OUI | ~15 pages | ~12 pages |

---

## 3. Écarts Doc ↔ Code — Tableau maître

### 3.1 Systèmes platform (V2-PLATFORM-SYSTEMS-MANQUANTS)

| Système promis | Statut | Ce qui existe | Ce qui manque |
|---------------|--------|---------------|---------------|
| Alert Center | **FAIT** | PlatformAlert model, AlertEvaluatorCommand cron 5min, page `/platform/alerts`, 7 rules, badge sidebar, SSE push | — |
| Company Health Score | **FAIT** | CompanyHealthScoreCalculator (5 dimensions), health badge dans supervision list | Cache daily (calcul on-demand seulement) |
| Revenue Intelligence | **NON FAIT** | MRR widget, cashflow trend widget | Page analytics dédiée, MRR evolution 12 mois, churn rate, ARPU, NRR, revenue by plan/market, trial conversion |
| Activity Feed | **FAIT** | endpoint UNION ALL, ActivityDescriber, page `/platform/activity`, widget dashboard | Agrégation groupée (12 invoices → 1 ligne) |
| Usage Monitoring | **NON FAIT** | AI usage tracking par company | Page `/platform/usage`, membres actifs 7j/30j, documents uploadés, modules usage |
| SLA & Support Ops | **NON FAIT** | Support ticket basic CRUD | SlaPolicy model, temps de première réponse, SLA tracking, CSAT, templates |
| Feature Flags | **NON FAIT** | Module system on/off | FeatureFlag model, ciblage %, rollout progressif |
| Jobs Center | **NON FAIT** | failed_jobs table Laravel | Failed jobs viewer, retry UI, job metrics |
| Import/Export Gov | **NON FAIT** | Markets/translations import existant | Vue globale, logs d'exécution |

**Score** : 3/9 faits, 1 partiel, 5 non faits

### 3.2 Pages platform manquantes (V2-PLATFORM-PAGES-MANQUANTES)

| Page promise | Statut | Réalité |
|-------------|--------|---------|
| Dashboard cockpit | **FAIT** | `platform/index.vue` — Attention Required + System Health + Revenue Snapshot + Recent Activity |
| Activity feed page | **FAIT** | `platform/activity/index.vue` — timeline filtrable |
| Revenue Analytics | **NON FAIT** | Widgets billing existent (MRR, cashflow) mais pas de page analytics dédiée |
| Alert Center page | **FAIT** | `platform/alerts/index.vue` — KPI + table + actions |
| Companies enrichie | **FAIT** | `supervision/_CompaniesTab.vue` — MRR, health badge, segments, filtres |
| System Health page | **NON FAIT** | Health badges dans dashboard, mais pas de page dédiée |
| Global Search | **NON FAIT** | Aucun endpoint `/platform/search` |
| Usage Monitoring page | **NON FAIT** | — |
| Onboarding Funnel | **NON FAIT** | — |
| Incident Center | **NON FAIT** | — |
| AI Operations page | **NON FAIT** | AI usage tab existe dans `/platform/ai/[tab]` mais pas d'opérations |

**Score** : 4/11 faits

### 3.3 Navigation platform (V2-AUDIT-PLATFORM-UX)

| Promesse | Statut | Réalité |
|----------|--------|---------|
| 7 groupes au lieu de liste plate | **FAIT** | 8 groupes via ADMIN_GROUP_ORDER (cockpit, clients, finance, product, ai, international, operations, administration) |
| Dashboard dans Cockpit | **FAIT** | NavItem cockpit → dashboard |
| Activité dans Cockpit | **FAIT** | NavItem cockpit → activity |
| Alertes dans Cockpit | **FAIT** | NavItem cockpit → alerts avec badge |
| Billing tab merge (13→1) | **FAIT** | `billing/[tab].vue` unique avec 13 tabs |
| Supervision renommée Entreprises | **PARTIELLEMENT** | NavItem dit "Entreprises" mais page reste `supervision/[tab]` |
| Suppression pages doublons | **NON FAIT** | `supervision/[tab]` + `companies/[id]` coexistent |
| International tabs éclatés | **FAIT** | `international/[tab].vue` avec Markets, Langues, Traductions, FX |

### 3.4 Design Standards (V2-DESIGN-STANDARDS)

| Standard | Statut | Adoption |
|----------|--------|----------|
| card-grid system (ADR-379) | **FAIT** | `styles.scss` — xs/sm/md/lg sizes |
| VDataTableServer pour toutes les listes | **PARTIEL** | ~60% des listes. Certaines utilisent VDataTable client |
| Empty states partout | **PARTIEL** | EmptyState composant existe, utilisé sur ~19 pages, manque sur ~10 |
| Loading skeleton partout | **PARTIEL** | VSkeletonLoader utilisé sur ~27 pages, manque sur ~8 |
| Error + Retry partout | **PARTIEL** | ErrorState utilisé sur 5 pages company, 0 platform |
| Feedback toast systématique | **PARTIEL** | useAppToast sur ~18 pages, manque sur ~12 |
| Animations/transitions | **NON FAIT** | Aucune route transition, aucun list animation |
| Responsive breakpoints | **PARTIEL** | Vuetify responsive natif, mais pas de custom breakpoint logic |
| Accessibility (WCAG AA) | **NON FAIT** | Pas d'aria-labels, pas de focus management, pas de skip-to-content |
| Command Bar (⌘K) | **NON FAIT** | — |

### 3.5 Frontend UX (V2-AUDIT-FRONTEND-UX)

| Promesse | Statut | Réalité |
|----------|--------|---------|
| Phase 1 anti-blink | **FAIT** | smart merge, polling silencieux, skeleton loaders (ADR-431) |
| Phase 2 realtime | **FAIT** | SSE 22 topics, connection indicator, fallback polling |
| Phase 3 design system | **PARTIEL** | card-grid, StatusChip, mais pas de tokens spacing/color formalisés |
| Phase 4 AI UX | **NON FAIT** | AI document processing existe, mais pas de copilot UI |
| Score cible 9/10 | **NON** | Estimé ~7/10 — patterns existent mais couverture incomplète |

### 3.6 Technique transverse (ADR-432 à 437)

| ADR | Promesse | Statut | Réalité |
|-----|----------|--------|---------|
| ADR-432 Tenancy | BelongsToCompany trait sur tous les models company | **FAIT** | Trait + CompanyScope sur 40+ models |
| ADR-433 RBAC | v-can directive, useCan composable, 403 contextuel | **FAIT** | Directive, composable, page 403.vue |
| ADR-434 Realtime | SSE topics, connection indicator, topic handlers | **FAIT** | 22 topics, SseRealtimePublisher, topicHandlers.js |
| ADR-435 Multi-market | useMarketFormatting, applyMarket, FX rates | **FAIT** | Composable, FxRateFetchJob, market-aware pricing |
| ADR-436 AI Engine | AiModuleContract, quotas, cost tracking | **FAIT** | Interface + DocumentAiModule implémentation |
| ADR-437 Automation | WorkflowRule, triggers, conditions, actions | **FAIT** | Model, TriggerRegistry, ConditionEvaluator, ActionExecutor, ProcessWorkflowJob |

**Score** : 6/6 ADR implémentés

---

## 4. Écarts Backend ↔ Frontend

| Fonctionnalité | Backend | Frontend | Écart |
|---------------|---------|----------|-------|
| Alert system | Model + cron + API CRUD + SSE push | Page alerts + badge sidebar | **ALIGNÉ** |
| Activity feed | Endpoint UNION ALL + ActivityDescriber | Page activity + widget dashboard | **ALIGNÉ** |
| Dashboard cockpit | DashboardCockpitController (attention + health) | index.vue refait | **ALIGNÉ** |
| Company health score | Calculator 5 dimensions | Badge dans supervision list | **ALIGNÉ** |
| Billing 13 tabs | Controllers + routes pour chaque tab | [tab].vue unique unifié | **ALIGNÉ** |
| Email notifications | 11 classes MailMessage, hardcodé anglais, pas de queue | **AUCUN frontend** | **ÉCART CRITIQUE** — pas de page templates, pas de logs email, pas de settings email |
| Billing cancel | Endpoint + SubscriptionCancelPlan | Dialog cancel dans company billing | **PARTIEL** — dialog existe mais pas de preview impact |
| Billing plan change | PlanChangeIntent + PlanChangeExecutor + preview | Dialog preview 269 lignes dans company | **ALIGNÉ** |
| Support SLA | Aucun backend SLA | Aucun frontend SLA | Aligné (rien des 2 côtés) |
| Revenue analytics | FinancialSnapshot daily cron | Widgets MRR/cashflow dans billing dashboard | **PARTIEL** — backend a les données, frontend n'a pas de page analytics |
| Workflows/Automations | WorkflowRule + Trigger + Condition + Action + Job | Pages workflows company (list + detail) | **ALIGNÉ** |

---

## 5. Écarts Company ↔ Platform

| Fonctionnalité | Company | Platform | Écart |
|---------------|---------|----------|-------|
| Pages totales | 23 pages | 34 pages | Normal — platform a plus de scope |
| Patterns UX V2 | 5 pages migrées (useAsyncAction, ErrorState, PageBreadcrumbs) | 0 pages migrées | **ÉCART CRITIQUE** — platform n'utilise aucun pattern V2 |
| Loading states | VSkeletonLoader sur ~15 pages | VSkeletonLoader sur ~12 pages | **PARTIEL** les 2 côtés |
| Error handling | ErrorState + retry sur 5 pages | VAlert basique ou rien | **ÉCART** — platform n'a pas le pattern Error+Retry |
| Empty states | EmptyState sur ~14 pages | EmptyState sur ~5 pages | **ÉCART** — platform sous-équipé |
| Breadcrumbs | PageBreadcrumbs sur 3 pages détail | 0 page | **ÉCART** |
| Toast feedback | useAppToast ~10 pages | useAppToast ~8 pages | Correct les 2 côtés |
| Confirmation dialogs | confirm() sur 5+ actions | confirm() sur 6+ actions | Correct les 2 côtés |
| Form validation | Validators sur ~3 formulaires | Validators sur ~3 formulaires | Faible les 2 côtés |

---

## 6. Écarts Navigation ↔ Produit

### 6.1 Platform — pages qui existent mais ne devraient pas

| Page | Verdict | Raison |
|------|---------|--------|
| `supervision/[tab].vue` (3 tabs) | **MERGER** dans `companies/` | Doublon avec companies — supervision = ancien nom |
| `markets/[key].vue` | **CONVERTIR EN TAB** | Devrait être un tab dans `international/[tab]` |
| `users/[id].vue` | **CONVERTIR EN TAB** | Devrait être un tab dans `access/[tab]` |

### 6.2 Platform — items de navigation sans page

| NavItem | Page existe ? | Problème |
|---------|--------------|----------|
| Tous les NavItems | OUI | Pas de NavItem fantôme détecté |

### 6.3 Company — pages orphelines

| Page | Verdict | Raison |
|------|---------|--------|
| `workflows/index.vue` + `workflows/[id].vue` | **GARDER** | Fonctionnel, lié au backend automation |
| `my-deliveries/` | **GARDER** | Vue restreinte pour conducteurs (excludePermission shipments.view) |

---

## 7. Écarts UX ↔ Vision V2

### 7.1 Vision V2 (V2-VISION-PRODUIT) vs Réalité

| Promesse Vision | Statut | Réalité |
|----------------|--------|---------|
| **8 Core Engines** | | |
| Realtime Engine (SSE) | **FAIT** | 22 topics, polling fallback, connection indicator |
| AI Engine | **FAIT** | AiModuleContract, quotas, cost tracking, document AI |
| Automation Engine | **FAIT** | WorkflowRule, triggers, conditions, actions, jobs |
| Notification Engine | **PARTIEL** | NotificationDispatcher, 30+ topics, in-app OK. Email = 11 classes basiques sans queue/i18n/branding |
| Audit Engine | **FAIT** | PlatformAuditLog + CompanyAuditLog, DiffEngine, 60+ actions |
| Identity Engine | **PARTIEL** | Auth Passport, RBAC v-can, mais pas SSO/2FA/passwordless |
| Storage Engine | **PARTIEL** | Storage files via PHP route (ISPConfig), mais pas de CDN/presigned URLs |
| Search Engine | **NON FAIT** | Aucune recherche globale (ni company, ni platform) |
| **UX Shell** | | |
| Command Bar ⌘K | **NON FAIT** | — |
| Inbox unifié | **NON FAIT** | Notifications page basique (liste), pas d'inbox avec actions |
| Activity Feed | **FAIT** | Page + widget dashboard + timeline |
| Cockpits | **FAIT** | Dashboard platform cockpit + company dashboard |
| **Phases V2** | | |
| Phase 1 : Stabilisation (S1-4) | **FAIT** | Tenancy, RBAC, Realtime, Multi-market implémentés |
| Phase 2 : Intelligence (S5-8) | **PARTIEL** | AI Engine fait, mais pas Analytics/Search/Onboarding |
| Phase 3 : Automatisation (S9-12) | **PARTIEL** | Automation Engine fait, Email Platform non |
| Phase 4 : Scale (S13-18) | **NON FAIT** | Pas de Feature Flags, Usage Monitoring, Advanced Analytics |
| Phase 5 : Polish (S19-24) | **NON FAIT** | Pas d'A11y, animations, ⌘K, AI copilot |

### 7.2 Score d'alignement global

| Domaine | Score |
|---------|-------|
| Backend technique (tenancy, RBAC, realtime, AI, automation) | **90%** |
| Backend billing (subscriptions, invoices, payments, dunning) | **85%** |
| Frontend company UX | **65%** |
| Frontend platform UX | **45%** |
| Email/Notification platform | **20%** |
| Analytics/Intelligence | **15%** |
| Design system cohérence | **55%** |
| Navigation & structure | **75%** |
| **Score global d'alignement** | **56%** |

---

## 8. Priorités d'alignement immédiates

### PRIORITÉ 1 — Écarts critiques (bloquent le produit)

| # | Écart | Impact | Effort | Action |
|---|-------|--------|--------|--------|
| P1-1 | **Email Platform** — 11 notifs hardcodées en anglais, pas de queue, pas de template Blade, pas de logs, pas de page admin | Produit non vendable — emails en anglais pour un SaaS français | Fort (3-4j) | Créer templates Blade, i18n, ShouldQueue, EmailLog model, pages platform (templates + logs + settings) |
| P1-2 | **Patterns UX V2 sur platform** — 0/34 pages utilisent useAsyncAction/ErrorState/PageBreadcrumbs | Platform = expérience admin dégradée vs company | Moyen (2-3j) | Migrer les pages platform critiques (companies/[id], billing/[tab], support, ai) |
| P1-3 | **Revenue Analytics** — backend FinancialSnapshot existe, aucune page frontend | Impossible de piloter le business | Moyen (2-3j) | Créer page/tab analytics dans billing platform avec charts ApexCharts |

### PRIORITÉ 2 — Écarts importants (dégradent l'expérience)

| # | Écart | Impact | Effort | Action |
|---|-------|--------|--------|--------|
| P2-1 | **Supervision → Companies merge** | Confusion navigation, 2 chemins pour la même chose | Faible (1j) | Renommer/merger supervision dans companies |
| P2-2 | **Empty states platform** | Pages vides sans guidage | Faible (1j) | Ajouter EmptyState sur ~10 pages platform |
| P2-3 | **Billing Safety UX** — cancel sans preview impact, pas d'audit trail visible | Risque opérationnel | Moyen (2j) | Preview impact dialog sur cancel/suspend/dunning actions |
| P2-4 | **Form validation** — ~6 formulaires sans `:rules` | Soumissions invalides | Faible (1j) | Ajouter validators sur formulaires critiques |

### PRIORITÉ 3 — Améliorations produit

| # | Écart | Impact | Effort | Action |
|---|-------|--------|--------|--------|
| P3-1 | Global Search | Productivité admin | Moyen (2-3j) | Endpoint multi-table + UI header |
| P3-2 | System Health page dédiée | Visibilité ops | Faible (1j) | Agréger endpoints existants |
| P3-3 | SLA Support | Support professionnel | Moyen (2-3j) | SlaPolicy model + tracking + UI |
| P3-4 | Animations/transitions | Polish UX | Faible (1j) | Route transitions, list animations |

---

## 9. Plan d'exécution immédiat

### Batch 1 : Email Platform (P1-1)
```
Backend :
  - Créer templates Blade pour les 11 notifications (fr + en)
  - Ajouter ShouldQueue sur toutes les notifications
  - Créer EmailLog model + migration
  - Endpoint GET /platform/email/logs
  - Endpoint GET /platform/email/templates (read-only catalog)
  - Endpoint GET/PUT /platform/email/settings

Frontend :
  - Page platform/email/[tab].vue (templates, logs, settings)
  - NavItem dans groupe Operations
```

### Batch 2 : Platform UX V2 Migration (P1-2)
```
Migrer vers useAsyncAction + ErrorState + PageBreadcrumbs :
  - platform/companies/[id].vue (le + critique — page 360°)
  - platform/billing/[tab].vue (13 tabs, gros volume)
  - platform/support/[id].vue
  - platform/ai/[tab].vue
  - platform/jobdomains/[id].vue
```

### Batch 3 : Revenue Analytics (P1-3)
```
Backend :
  - Endpoint GET /platform/billing/analytics (exploiter FinancialSnapshot)
  - MRR evolution 12 mois, churn rate, revenue by plan, trial conversion

Frontend :
  - Nouveau tab "Analytics" dans billing/[tab].vue
  - Charts ApexCharts (line MRR, bar revenue by plan, donut by market)
```

### Batch 4 : Billing Safety + Quick Wins (P2)
```
  - Preview impact dialog sur cancel subscription
  - Supervision → Companies merge
  - Empty states platform (~10 pages)
  - Form validation sur formulaires critiques
```
