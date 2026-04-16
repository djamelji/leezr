# Roadmap Platform V2 — Plan d'exécution priorisé

> Date : 2026-04-13
> Objectif : Transformer /platform/ en cockpit de gouvernance SaaS professionnel

---

## Structure cible : Navigation Platform V2

### Navigation proposée (sidebar)

```
COCKPIT
  ├── Dashboard              ← REFONTE (commandement)
  ├── Activité               ← NOUVEAU
  └── Alertes                ← NOUVEAU

CLIENTS
  ├── Entreprises            ← REFONTE (intelligence list)
  └── Support                ← ENRICHI (SLA)

FINANCE
  ├── Facturation            ← FUSION (billing + advanced)
  ├── Abonnements & Plans    ← EXISTANT (plans)
  ├── Coupons                ← EXISTANT (extrait de billing)
  └── Analytics              ← NOUVEAU

PRODUIT
  ├── Modules                ← EXISTANT
  ├── Métiers                ← EXISTANT (jobdomains)
  ├── Champs                 ← EXISTANT (fields)
  ├── Documents              ← EXISTANT
  └── Documentation          ← EXISTANT

INTELLIGENCE ARTIFICIELLE
  ├── Fournisseurs           ← EXISTANT (AI providers)
  └── Opérations             ← NOUVEAU (monitoring + costs)

INTERNATIONAL
  ├── Marchés                ← EXISTANT
  ├── Langues                ← EXISTANT
  ├── Traductions            ← EXISTANT
  └── Taux de change         ← EXISTANT

OPÉRATIONS
  ├── Automations            ← EXISTANT
  ├── Temps réel             ← EXISTANT
  ├── Sécurité               ← EXISTANT
  └── Santé système          ← NOUVEAU

ADMINISTRATION
  ├── Accès (Users/Roles)    ← EXISTANT
  ├── Paramètres             ← EXISTANT (réorganisé)
  ├── Notifications          ← EXISTANT
  └── Mon compte             ← EXISTANT
```

### Changements navigation vs actuel

| Changement | Détail |
|------------|--------|
| **Regroupement** | 9 groupes au lieu de liste plate de 15+ items |
| **Cockpit** | Nouveau groupe — Dashboard + Activité + Alertes |
| **Finance** | Billing + Advanced fusionnés + Analytics ajouté + Coupons extrait |
| **Clients** | Supervision renommée "Entreprises" — plus métier |
| **IA** | Séparé en 2 : Config (Fournisseurs) + Ops (Opérations) |
| **Opérations** | Nouveau groupe technique (Automations + Realtime + Security + Health) |
| **International** | Tabs éclatés en items pour visibilité directe |

---

## P0 — Critique (sprint immédiat)

### P0-1. Dashboard de commandement (REFONTE)

**Impact produit** : Transforme l'accueil d'un compteur inutile en cockpit décisionnel.
**Impact business** : Réduction du MTTR, détection proactive des problèmes.
**Effort** : Moyen (3-5j) — le widget engine existe, il faut créer les widgets "Attention Required" et "System Health".

**Contenu** :
- Section "Attention Required" : agrège les items qui nécessitent une action
  - Factures overdue > 7j (source : invoices where status=overdue/open, due_at < 7j)
  - Tickets non assignés > 24h (source : tickets where assigned_to=null, created_at < 24h)
  - Subscriptions pending approval (source : subscriptions where status=pending)
  - Payments failed (source : payments where status=failed, created 7j)
  - AI providers unhealthy (source : AI health endpoint)
  - Cron tasks failed (source : automations health)
- Section "System Health" : 4 badges (scheduler, AI, queue, SSE)
- Section "Revenue Snapshot" : MRR, variation, AR outstanding (3 widgets existants)
- Section "Recent Activity" : 10 dernières actions (nouveau endpoint /activity)

**Dépendances** :
- Backend : endpoint `GET /platform/dashboard/attention` (agrège les sources ci-dessus)
- Backend : endpoint `GET /platform/dashboard/health-summary`
- Frontend : refonte index.vue

---

### P0-2. Alert Center

**Impact produit** : L'admin est notifié proactivement au lieu de découvrir les problèmes par hasard.
**Impact business** : Critique — chaque heure de retard sur un incident billing = perte revenue.
**Effort** : Moyen (3-5j)

**Backend** :
- Model `PlatformAlert` : source, type, severity, status, company_id, metadata, created_at, resolved_at
- `AlertEvaluatorCommand` : cron every 5min, évalue des rules prédéfinies
- Rules initiales (hardcoded, pas besoin d'UI de config au début) :
  - Invoice overdue > 7j → warning
  - Invoice overdue > 30j → critical
  - Payment failed 3x → critical
  - AI provider health=down → critical
  - Cron task failed 3x consécutifs → warning
  - Queue depth > 500 → warning
  - Ticket unassigned > 24h → info
- Endpoint `GET /platform/alerts` : list with filters
- Endpoint `PUT /platform/alerts/{id}/acknowledge|resolve|dismiss`

**Frontend** :
- Page `/platform/alerts` : KPI bar (critiques actives, résolues 24h) + table filtrable
- Badge counter dans sidebar nav item "Alertes"
- Notification push (via SSE existant) pour les critiques

---

## P1 — Important (sprint suivant)

### P1-1. Activity Feed

**Impact produit** : Fort — traçabilité opérationnelle immédiate.
**Impact business** : Moyen — améliore la coordination admin.
**Effort** : Faible (1-2j) — le backend existe déjà (AuditLogs), il faut un endpoint de projection et une page.

**Backend** :
- Endpoint `GET /platform/activity` : union PlatformAuditLog + CompanyAuditLog récents, projeté en format lisible
- Agrégation : events du même type dans la même minute = groupés

**Frontend** :
- Page `/platform/activity` : timeline verticale avec filtres (type, actor, date)
- Widget dashboard "Recent Activity" (10 derniers)

---

### P1-2. Company Intelligence (REFONTE supervision)

**Impact produit** : Fort — la supervision devient un outil de pilotage client.
**Impact business** : Fort — identification précoce du churn.
**Effort** : Moyen (3-4j)

**Backend** :
- Enrichir `GET /platform/companies` : ajouter `mrr`, `last_activity_at`, `health_score`, `open_tickets_count`
- `CompanyHealthScoreCalculator` : service qui calcule le score (formule documentée dans V2-PLATFORM-SYSTEMS-MANQUANTS.md)
- Cron daily ou on-demand cache

**Frontend** :
- Refonte `supervision/_CompaniesTab.vue` : colonnes enrichies (MRR, health badge, last activity)
- Filtres avancés : par health (at-risk/healthy/dormant), par plan, par marché
- Segments quick-filter : "At risk", "High value", "Trial ending"

---

### P1-3. Revenue Analytics

**Impact produit** : Fort — pilotage stratégique du business.
**Impact business** : Critique — pricing et stratégie basés sur des données.
**Effort** : Moyen (3-5j)

**Backend** :
- `GET /platform/billing/analytics` : MRR evolution (12 mois), churn rate, ARPU, revenue by plan, revenue by market, trial conversion
- Exploiter `FinancialSnapshot` existants + `Subscription` + `Payment`

**Frontend** :
- Page `/platform/billing/analytics` (ou tab dans billing)
- Charts ApexCharts : line (MRR evolution), bar (revenue by plan), donut (revenue by market), funnel (trial → paid)

---

## P2 — Amélioration forte valeur

### P2-1. System Health Page

**Impact** : Visibilité opérationnelle centralisée.
**Effort** : Faible (1-2j) — agrège des endpoints existants.

**Backend** : endpoint `GET /platform/health` agrège :
- Automations health (existant)
- AI providers health (existant)
- Realtime status (existant)
- Queue stats (existant)
- Recovery status (existant)

**Frontend** : Page avec 6 sections status cards (vert/orange/rouge).

---

### P2-2. AI Operations

**Impact** : Cost tracking IA, monitoring live.
**Effort** : Moyen (2-3j) — enrichir les tabs AI existants.

**Backend** :
- Enrichir AI usage avec breakdown par company
- Cost estimation basée sur le provider pricing

**Frontend** :
- Nouveau tab "Operations" dans AI avec : live queue, cost chart, top consumers, error rate.

---

### P2-3. Billing Navigation Cleanup

**Impact** : UX — 13 onglets → structure claire.
**Effort** : Faible (1j) — réorganisation sans changement de contenu.

**Proposition** :
```
Billing Hub
  ├── Vue d'ensemble (dashboard existant)
  ├── Abonnements (subscriptions tab)
  ├── Factures (invoices tab)
  ├── Paiements (payments tab — déplacé d'advanced)
  ├── Relance (dunning tab)
  ├── Débits programmés (scheduled debits)
  ├── Avoirs (credit notes — déplacé d'advanced)
  ├── Coupons (coupons tab)
  ├── Portefeuilles (wallets — déplacé d'advanced)
  ├── Forensics (forensics tab)
  ├── Gouvernance (governance — déplacé d'advanced)
  ├── Grand livre (ledger — déplacé d'advanced)
  └── Récupération (recovery tab)
```
Fusion billing/index.vue + billing/advanced/[tab].vue → un seul billing/[tab].vue.

---

### P2-4. Support SLA Tracking

**Impact** : Support professionnel avec engagement de temps.
**Effort** : Moyen (2-3j)

**Backend** :
- Model `SlaPolicy` : priority → {first_response_hours, resolution_hours}
- Calcul SLA status per ticket (on_track/warning/breached)
- Endpoint enrichi tickets avec sla_status

**Frontend** :
- KPI cards enrichis (avg response time, SLA compliance %)
- Badge SLA sur chaque ticket (vert/orange/rouge)
- Tri par "SLA breach imminent"

---

### P2-5. Global Search

**Impact** : Productivité admin — trouver n'importe quoi en 2 secondes.
**Effort** : Moyen (2-3j)

**Backend** :
- Endpoint `GET /platform/search?q=xxx` : recherche multi-table (companies, users, invoices by number, tickets by subject)
- Retour groupé par type avec limit par groupe

**Frontend** :
- Intégré dans le header (comme NavSearchBar existant côté company)
- Résultats dropdown groupés

---

## P3 — Différenciation

### P3-1. Onboarding Funnel

**Effort** : Moyen | **Impact** : Fort pour growth

### P3-2. Incident Center

**Effort** : Fort | **Impact** : Moyen (incidents rares mais critiques)

### P3-3. Feature Flags

**Effort** : Fort | **Impact** : Fort pour les déploiements progressifs

### P3-4. Usage Monitoring détaillé

**Effort** : Moyen | **Impact** : Moyen (data pour pricing)

---

## Plan d'exécution — Premier lot immédiat

Le premier lot à implémenter vise le **meilleur ROI** : ce qui rend la platform **immédiatement pilotable** avec le **minimum d'effort**.

### Lot 1 : "La platform qui voit" (P0 + P1 léger)

| # | Item | Type | Effort | Pourquoi d'abord |
|---|------|------|--------|-----------------|
| 1 | Activity Feed page | NOUVEAU | 1-2j | Backend existe, effort minimal, impact max |
| 2 | Dashboard "Attention Required" | REFONTE | 2-3j | Transforme l'accueil en cockpit |
| 3 | Dashboard "System Health" badges | REFONTE | 1j | Agrège des endpoints existants |
| 4 | Company list enrichie (MRR, health, last activity) | REFONTE | 2-3j | La supervision devient utile |
| 5 | Alert Center (backend + page) | NOUVEAU | 3-4j | L'admin est notifié proactivement |
| 6 | Billing nav cleanup (fusion advanced) | REFONTE | 1j | UX immédiate, 0 risque |

**Total estimé** : 10-14j de travail
**Résultat** : Score platform passe de 62/100 à ~78/100

### Lot 2 : "La platform qui comprend" (P1 + P2)

| # | Item | Effort |
|---|------|--------|
| 7 | Revenue Analytics page | 3-5j |
| 8 | Health Score company | 2j |
| 9 | AI Operations tab | 2-3j |
| 10 | Support SLA tracking | 2-3j |
| 11 | Global Search | 2-3j |
| 12 | System Health page | 1-2j |

**Total estimé** : 12-18j
**Résultat** : Score platform ~88/100

---

## Score cible par phase

| Phase | Score | Delta | Principaux gains |
|-------|-------|-------|-----------------|
| Actuel | 62/100 | — | — |
| Après Lot 1 | 78/100 | +16 | Cockpit, alertes, activity, company intelligence |
| Après Lot 2 | 88/100 | +10 | Analytics, health score, SLA, search, AI ops |
| Après P3 | 95/100 | +7 | Onboarding, incidents, feature flags |
