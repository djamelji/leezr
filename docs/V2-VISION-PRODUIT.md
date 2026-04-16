# LEEZR V2 — VISION PRODUIT PLATFORM-FIRST

> Mode : Platform Architect SaaS | Vision horizontale, multi-industrie, extensible
> Principe : Le core est la plateforme. Les modules sont les produits. L'AI est transverse.

---

# 1. REPOSITIONNEMENT

## Ce que Leezr N'EST PAS

- ❌ Un outil de gestion documentaire
- ❌ Un logiciel de logistique
- ❌ Un vertical niche pour un seul métier
- ❌ Un SaaS à features fixes

## Ce que Leezr EST

> **Leezr est un Operating System for Business Operations.**

Un OS métier modulaire où chaque entreprise compose son espace de travail en activant les modules dont elle a besoin — documents, billing, support, CRM, HR, compliance — le tout connecté par un core intelligent (realtime, AI, automations, notifications).

### L'analogie

```
Shopify = OS du e-commerce
  → Core (paiements, commandes, clients)
  → Modules (apps, thèmes, extensions)
  → Chaque marchand compose sa boutique

Leezr = OS des opérations business
  → Core (realtime, AI, automations, notifications, audit)
  → Modules (billing, documents, support, CRM, HR...)
  → Chaque entreprise compose son espace opérationnel
```

### Le positionnement marché

| Attribut | Définition |
|----------|-----------|
| **Pour qui** | PME et ETI (10-500 employés) de toute industrie |
| **Pain point** | Opérations fragmentées entre 5-10 outils non connectés |
| **Promesse** | "Un seul OS pour toutes vos opérations. Modulaire. Intelligent. Temps réel." |
| **Différenciateur** | AI transverse + automations cross-modules + realtime natif |
| **Comparable à** | Monday.com (flexibilité) × Stripe (qualité) × Notion (UX) — mais pour les ops B2B |

---

# 2. ARCHITECTURE PRODUIT V2

## 2.1 Principe : Core ≠ Modules

```
┌──────────────────────────────────────────────────────────────┐
│                       LEEZR PLATFORM                          │
│                                                               │
│  ┌─────────────────── CORE PLATFORM ───────────────────────┐ │
│  │                                                          │ │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐  │ │
│  │  │ Realtime │ │    AI    │ │ Autom.   │ │ Notif.   │  │ │
│  │  │ Engine   │ │  Engine  │ │ Engine   │ │ Engine   │  │ │
│  │  └──────────┘ └──────────┘ └──────────┘ └──────────┘  │ │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐  │ │
│  │  │  Audit   │ │ Identity │ │ Storage  │ │ Search   │  │ │
│  │  │  Trail   │ │ & Access │ │ Engine   │ │ Engine   │  │ │
│  │  └──────────┘ └──────────┘ └──────────┘ └──────────┘  │ │
│  └──────────────────────────────────────────────────────────┘ │
│                              │                                │
│                    ┌─────────┴──────────┐                    │
│                    │   Module Interface  │                    │
│                    │   (events, hooks,   │                    │
│                    │    capabilities)    │                    │
│                    └─────────┬──────────┘                    │
│                              │                                │
│  ┌──────────────────── MODULES ────────────────────────────┐ │
│  │                                                          │ │
│  │  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐      │ │
│  │  │Billing  │ │Documents│ │ Support │ │  CRM    │      │ │
│  │  │         │ │         │ │         │ │(futur)  │      │ │
│  │  └─────────┘ └─────────┘ └─────────┘ └─────────┘      │ │
│  │  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐      │ │
│  │  │   HR    │ │Logistics│ │Inventory│ │ Custom  │      │ │
│  │  │(futur)  │ │         │ │ (futur) │ │(futur)  │      │ │
│  │  └─────────┘ └─────────┘ └─────────┘ └─────────┘      │ │
│  └──────────────────────────────────────────────────────────┘ │
│                                                               │
│  ┌────────────────── UX SHELL ─────────────────────────────┐ │
│  │  Command Bar │ Inbox │ Activity Feed │ Cockpits │ Nav   │ │
│  └──────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

## 2.2 Core Platform — Les 8 engines

Le core est **agnostique du métier**. Il fournit des capacités que n'importe quel module consomme.

### Engine 1 : Realtime Engine

**Rôle** : Tout événement dans la plateforme est propagé en temps réel à tous les consommateurs concernés.

```
Tout module → publie un EventEnvelope → Realtime Engine
  → SSE streams (company-scoped, user-filtered)
  → Activity Feed
  → Inbox updates
  → Dashboard widget refresh
  → Automation triggers
```

**Ce que les modules consomment** :
- `publish(event)` : émettre un événement
- `subscribe(topic, handler)` : écouter un événement
- `invalidate(keys)` : invalider un cache

**Ce que le core garantit** :
- Délivrance ordonnée (Redis Streams)
- Deduplication globale (event_id)
- Fallback polling transparent
- Indicateur connexion visible

---

### Engine 2 : AI Engine

**Rôle** : Capacités d'intelligence artificielle disponibles pour TOUT module. Pas de logique métier dans l'engine — seulement des primitives.

```
AI Engine
  ├── analyze(image/document)    → extraction structurée
  ├── classify(text, categories) → catégorisation
  ├── summarize(content)         → résumé
  ├── detect_anomalies(dataset)  → outliers
  ├── predict(model, features)   → prédiction
  ├── explain(decision)          → explication en langage naturel
  └── suggest(context)           → recommandations
```

**Exemples de consommation par module** :

| Module | Utilisation AI Engine |
|--------|---------------------|
| Documents | `analyze(image)` → extraction champs, confiance, auto-review |
| Billing | `detect_anomalies(invoices)` → factures suspectes, patterns de churn |
| Support | `classify(ticket_text, categories)` → routage auto, priorité |
| CRM (futur) | `predict(churn_model, client_features)` → risque d'attrition |
| HR (futur) | `summarize(cv)` → résumé candidat, matching poste |
| Tout module | `suggest(context)` → "Leezr recommande..." |

**Provider abstraction** :
```
AiGatewayManager
  ├── AnthropicAdapter (cloud, haute qualité)
  ├── OllamaAdapter (local, privacy-first)
  ├── OpenAIAdapter (futur)
  └── NullAdapter (fallback, toujours disponible)
```

---

### Engine 3 : Automation Engine

**Rôle** : Exécuter des actions automatisées, programmées ou déclenchées par événement, indépendamment du module source.

```
Automation Engine
  ├── Scheduled (cron)     : "Tous les jours à 8h, faire X"
  ├── Event-driven         : "Quand Y se produit, faire Z"
  ├── Conditional          : "Si condition A ET condition B, alors Z"
  └── Cross-module         : "Quand billing.payment_failed ET documents.expired → suspend + notify"
```

**Interface module** :
```
Chaque module déclare :
  - triggers[]    : événements qu'il peut émettre
  - actions[]     : actions qu'il peut exécuter
  - conditions[]  : états qu'il peut évaluer
```

**Exemple** :
```yaml
# Module Billing déclare :
triggers:
  - billing.payment_failed
  - billing.subscription_renewed
  - billing.invoice_overdue
actions:
  - billing.suspend_company
  - billing.send_reminder
  - billing.retry_payment
conditions:
  - billing.has_overdue_invoices
  - billing.subscription_is_active
  - billing.payment_method_expiring_soon

# Module Documents déclare :
triggers:
  - documents.expired
  - documents.submitted
  - documents.ai_analyzed
actions:
  - documents.send_renewal_request
  - documents.auto_approve
  - documents.reject_with_reason
conditions:
  - documents.compliance_below_threshold
  - documents.has_expiring_documents
```

---

### Engine 4 : Notification Engine

**Rôle** : Distribuer des messages à travers tous les canaux, pour tout module.

```
Notification Engine
  ├── In-app (realtime via SSE)
  ├── Email (queued, template-driven)
  ├── Browser push (background tab)
  ├── Slack/Webhook (futur)
  └── SMS (futur)
```

**Ce que les modules publient** :
```
notify(topic, recipients, payload, options)
  topic: "billing.payment_failed"
  recipients: [company_admins, billing_managers]
  payload: { invoice_id, amount, reason }
  options: { channels: ['in_app', 'email'], priority: 'high' }
```

**Routing intelligent** :
- L'utilisateur choisit ses préférences par topic
- Haute priorité → tous les canaux
- Basse priorité → in-app seulement
- Digest quotidien pour les notifications groupées

---

### Engine 5 : Audit Trail

**Rôle** : Traçabilité complète de toute action sur la plateforme.

```
Audit Trail
  ├── Qui a fait quoi, quand, sur quelle entité
  ├── Avant/après (snapshot)
  ├── Source : humain, AI, automation, API
  ├── Requêtable, filtrable, exportable
  └── Conformité RGPD (data retention policies)
```

Chaque module **émet** des audit events sans se soucier du stockage.

---

### Engine 6 : Identity & Access

**Rôle** : Authentification, rôles, permissions — transverse à tous les modules.

```
Identity & Access
  ├── Users (platform + company)
  ├── Roles (déclaratifs par module)
  ├── Permissions (granulaires, bundle-able)
  ├── 2FA (TOTP + backup codes)
  ├── Session governance (TTL, multi-tab, cross-device)
  └── API tokens (futur)
```

Chaque module **déclare** ses permissions dans son manifest :
```php
ModuleManifest::permissions([
  'billing.view', 'billing.manage', 'billing.admin',
  'documents.view', 'documents.manage', 'documents.configure',
])
```

---

### Engine 7 : Storage Engine

**Rôle** : Gestion de fichiers unifiée pour tout module.

```
Storage Engine
  ├── Upload (chunk, resume, progress)
  ├── Processing (thumbnail, PDF→image, OCR)
  ├── Access control (company-scoped, permission-gated)
  ├── Versioning (futur)
  └── Providers (local, S3, GCS)
```

---

### Engine 8 : Search Engine

**Rôle** : Recherche full-text transverse à tous les modules.

```
Search Engine
  ├── Index par module (documents, invoices, members, tickets...)
  ├── Recherche unifiée (Command Bar)
  ├── Filtres contextuels
  ├── Ranking intelligent (fréquence, récence, pertinence)
  └── Providers (database LIKE, Meilisearch, Algolia futur)
```

---

## 2.3 Module Interface — Le contrat

Chaque module implémente un **contrat standard** avec le core :

```php
interface ModuleContract
{
    // Identité
    public function manifest(): ModuleManifest;

    // AI Engine
    public function aiCapabilities(): array;       // Ce que l'AI peut faire pour ce module
    public function aiPolicy(): AiPolicy;          // Gating AI pour ce module

    // Automation Engine
    public function triggers(): array;             // Événements émis
    public function actions(): array;              // Actions exécutables
    public function conditions(): array;           // Conditions évaluables

    // Search Engine
    public function searchableEntities(): array;   // Entités indexées

    // Realtime
    public function realtimeTopics(): array;       // Topics SSE publiés

    // Notification
    public function notificationTopics(): array;   // Topics de notification

    // Dashboard
    public function widgetCatalog(): array;        // Widgets disponibles

    // Navigation
    public function navigationItems(): array;      // Items menu
}
```

**Pourquoi c'est puissant** : N'importe quel nouveau module qui implémente ce contrat obtient **gratuitement** : realtime, AI, automations, notifications, search, dashboard, navigation.

---

# 3. AI V2 — TRANSVERSE À TOUTE LA PLATEFORME

## 3.1 Le principe : AI Engine ≠ AI Module

L'AI n'est pas un module. C'est un **engine du core** que chaque module consomme.

```
                    ┌─────────────┐
                    │  AI Engine  │
                    │             │
                    │  analyze()  │
                    │  classify() │
                    │  predict()  │
                    │  suggest()  │
                    │  explain()  │
                    └──────┬──────┘
                           │
          ┌────────────────┼────────────────┐
          │                │                │
    ┌─────▼─────┐  ┌──────▼──────┐  ┌─────▼─────┐
    │ Documents │  │   Billing   │  │  Support  │
    │           │  │             │  │           │
    │ analyze   │  │ anomalies   │  │ classify  │
    │ auto-     │  │ churn       │  │ priority  │
    │ review    │  │ predict     │  │ suggest   │
    │ extract   │  │ forecast    │  │ route     │
    └───────────┘  └─────────────┘  └───────────┘
```

## 3.2 AI Capabilities par module existant

### Documents × AI
- `analyze(image)` → extraction champs structurés
- `classify(document, types)` → identification type de document
- `detect_anomalies(document)` → falsification, incohérences
- `suggest(expiry_context)` → "Ce document expire dans 30j, programmer relance"
- Auto-review avec seuil de confiance configurable

### Billing × AI
- `detect_anomalies(payment_history)` → patterns de paiement inhabituels
- `predict(churn, client_features)` → probabilité de churn par client
- `predict(revenue, historical_data)` → forecast MRR
- `suggest(dunning_context)` → "Ce client paie toujours en retard mais paie. Recommandation : relance douce, pas suspension"
- `classify(failed_payment, reasons)` → catégorisation des échecs (carte expirée, fonds insuffisants, fraude)

### Support × AI
- `classify(ticket_text, categories)` → routage automatique
- `suggest(ticket_context)` → réponse suggérée
- `predict(resolution_time, ticket_features)` → ETA résolution
- `summarize(ticket_thread)` → résumé pour escalation

### Members × AI
- `suggest(onboarding_context)` → "Ce membre n'a pas complété son profil après 7 jours"
- `detect_anomalies(activity_patterns)` → membres inactifs, comportements inhabituels
- `predict(compliance_risk, member_features)` → score de risque compliance

### Platform (Admin) × AI
- `summarize(company_health)` → "Cette company a 3 factures impayées et 5 documents expirés"
- `detect_anomalies(platform_metrics)` → alertes proactives
- `suggest(platform_context)` → recommandations opérationnelles

## 3.3 AI Copilote Global

Le copilote n'est pas attaché à un module. Il opère au niveau de la **company entière** :

```
┌──────────────────────────────────────────────────────────┐
│ 🤖 AI Copilote — Vue globale                             │
│                                                           │
│ 📊 Santé de l'entreprise : 87/100                         │
│                                                           │
│ ┌─ Documents ──────────────────────────────────────────┐ │
│ │ Compliance 92% (+3pts) — 8 docs traités par AI       │ │
│ │ 💡 "5 documents expirent en mai. Relances programmées"│ │
│ └──────────────────────────────────────────────────────┘ │
│                                                           │
│ ┌─ Billing ────────────────────────────────────────────┐ │
│ │ MRR 24,500€ (+12%) — Dunning recovery 78%            │ │
│ │ ⚠️ "2 clients à risque de churn (usage -60%)"        │ │
│ │ 💡 "3 cartes expirent → 900€ à risque"               │ │
│ └──────────────────────────────────────────────────────┘ │
│                                                           │
│ ┌─ Support ────────────────────────────────────────────┐ │
│ │ 4 tickets ouverts — Temps moyen réponse : 2.3h       │ │
│ │ 💡 "Ticket #89 sans réponse depuis 48h"              │ │
│ └──────────────────────────────────────────────────────┘ │
│                                                           │
│ ┌─ Members ────────────────────────────────────────────┐ │
│ │ 45 actifs — 3 onboarding incomplets                  │ │
│ │ 💡 "Membres sans docs après 7j → taux completion     │ │
│ │    chute à 30% après 14j. Relancer maintenant ?"     │ │
│ └──────────────────────────────────────────────────────┘ │
│                                                           │
│ 📈 Tendances (30j)                                        │
│   Productivité admin : +40% (vs pré-AI)                  │
│   Documents auto-traités : 67%                           │
│   Temps moyen résolution : -55%                          │
│                                                           │
│ [Voir tous les insights] [Configurer les suggestions]    │
└──────────────────────────────────────────────────────────┘
```

## 3.4 AI Explain — Transverse

Chaque décision AI dans n'importe quel module est **explicable** :

```
Module Documents :
  "Document APPROUVÉ (94%) — MRZ valide, photo concordante, date OK"

Module Billing :
  "Client classé RISQUE DE CHURN (72%) — Usage -60% sur 30j, 2 tickets
   non résolus, pas de login depuis 14j"

Module Support :
  "Ticket classé URGENT — Mots clés détectés : 'bloqué', 'production',
   'impossible'. Historique : client premium, 3 tickets/mois"
```

---

# 4. UX V2 — PLATFORM SHELL

## 4.1 Le Shell : l'UX qui enveloppe tous les modules

Le **Shell** est la couche UX de la plateforme. Chaque module s'insère dedans sans réinventer l'UI.

```
┌──────────────────────────────────────────────────┐
│ ┌──────┐  LEEZR                    🔍 ⌘K  🔔 👤 │  ← Top Bar (Command Bar, Notifications, Profile)
│ │      │                                         │
│ │ NAV  │  ┌──────────────────────────────────┐  │
│ │      │  │                                  │  │
│ │ 📥   │  │     MODULE CONTENT AREA          │  │
│ │ 📊   │  │                                  │  │
│ │ 📄   │  │  (Chaque module rend ici)        │  │
│ │ 💳   │  │                                  │  │
│ │ 👥   │  │  Cockpit / List / Detail / Form  │  │
│ │ 🔧   │  │                                  │  │
│ │      │  └──────────────────────────────────┘  │
│ │      │                                         │
│ └──────┘  ┌──────────────────────────────────┐  │
│           │ 🤖 AI Copilote   │ 📡 Live Feed  │  │  ← Bottom/Side panels (toggleable)
│           └──────────────────────────────────┘  │
└──────────────────────────────────────────────────┘
```

## 4.2 Command Bar (⌘K) — Universelle

La Command Bar est **le point d'entrée unique** de toute la plateforme :

```
┌──────────────────────────────────────────────────┐
│ 🔍 Rechercher ou exécuter une action...          │
│                                                   │
│ Résultats (recherche multi-module)               │
│   👤 Mohamed Alami — Membre (Documents)          │
│   💳 INV-2026-042 — Facture impayée (Billing)   │
│   📄 Passeport #FR... — Document (Documents)    │
│   🎫 Ticket #89 — "Problème accès" (Support)    │
│                                                   │
│ Actions rapides                                   │
│   → Inviter un membre           (Members)        │
│   → Créer une facture           (Billing)        │
│   → Ouvrir un ticket            (Support)        │
│   → Lancer une automation       (Automations)    │
│                                                   │
│ 🤖 Suggestions AI                                │
│   ⚡ 3 items nécessitent votre attention          │
│   ⚡ 2 automations recommandées                   │
└──────────────────────────────────────────────────┘
```

**Chaque module enregistre** :
- Ses entités searchables (`searchableEntities()`)
- Ses actions rapides (`quickActions()`)
- Ses raccourcis contextuels

La Command Bar ne connaît pas le métier. Elle **agrège** ce que les modules déclarent.

## 4.3 Inbox — Multi-Module

L'Inbox n'est pas "documents inbox". C'est **l'inbox de la plateforme** :

```
┌──────────────────────────────────────────────────┐
│ 📥 Inbox — 9 actions en attente                  │
│                                                   │
│ Filtre : [Tous ▾] [Documents] [Billing]          │
│          [Support] [Members] [AI]                │
│                                                   │
│ ⬛ Urgent (3)                                     │
│   🔴 Billing : Facture #042 — 3e échec paiement │
│   🔴 Documents : Document rejeté AI — conf. 23% │
│   🔴 Support : Ticket #89 — sans réponse 48h    │
│                                                   │
│ ⬜ À traiter (4)                                  │
│   📄 3 documents en attente de review            │
│   👤 1 nouveau membre à onboarder               │
│                                                   │
│ 💡 Suggestions AI (2)                             │
│   "5 membres sans profil complet"                │
│   "Automation recommandée : relance carte"       │
└──────────────────────────────────────────────────┘
```

**Chaque module publie** des inbox items via une interface standard :
```typescript
interface InboxItem {
  module: string           // 'billing', 'documents', 'support'
  priority: 'urgent' | 'action' | 'suggestion'
  title: string
  context: string          // Explication courte
  actions: QuickAction[]   // Actions possibles 1-clic
  entity: { type, id }     // Pour navigation directe
  created_at: DateTime
  resolved_at?: DateTime   // Auto-nettoyage
}
```

## 4.4 Activity Feed — Multi-Module

Timeline business de toute l'entreprise :

```
┌──────────────────────────────────────────────────┐
│ 📡 Activité                                      │
│                                                   │
│ Filtre : [Tous] [Documents] [Billing] [Members]  │
│          [Support] [AI] [Automations]            │
│                                                   │
│ ● 14:32  📄 AI a approuvé le passeport de M.A.  │
│ ● 14:15  💳 Paiement reçu : 299€ — LogiTrans    │
│ ● 13:45  📄 Document expiré — relance auto       │
│ ● 11:20  👤 Jean-Pierre a rejoint l'entreprise   │
│ ● 09:00  🔄 Automation "Relance docs" : 12 envois│
│ ● 08:30  🎫 Ticket #92 résolu par Sarah         │
│                                                   │
│ Chaque événement = contexte + actions possibles  │
└──────────────────────────────────────────────────┘
```

**Chaque module publie** ses événements via le Realtime Engine. L'Activity Feed les affiche de façon unifiée.

## 4.5 Cockpits — Un par module, même structure

Chaque module qui expose un cockpit suit le **même layout** :

```
┌──────────────────────────────────────────────────┐
│ [Module Name] Cockpit                            │
│                                                   │
│ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐                │
│ │ KPI │ │ KPI │ │ KPI │ │ KPI │  ← card-grid-xs│
│ └─────┘ └─────┘ └─────┘ └─────┘                │
│                                                   │
│ 🔥 Nécessite attention                           │
│ ┌──────────────────────────────────────────┐     │
│ │ Item actionnable (actions inline)        │     │
│ └──────────────────────────────────────────┘     │
│                                                   │
│ 🤖 AI Insights (spécifiques au module)           │
│ ┌──────────────────────────────────────────┐     │
│ │ Suggestion contextuelle + action         │     │
│ └──────────────────────────────────────────┘     │
│                                                   │
│ 📈 Tendances (charts module-spécifiques)         │
│                                                   │
│ 📋 Liste principale (filtrable, triable)         │
└──────────────────────────────────────────────────┘
```

Le layout est **identique** pour billing, documents, support, members, etc. Seul le contenu change.

---

# 5. AUTOMATION V2 — CROSS-MODULE

## 5.1 Le principe : Trigger de N'IMPORTE OÙ → Action N'IMPORTE OÙ

```
┌─────────────┐     ┌──────────────┐     ┌──────────────┐
│   TRIGGER   │ ──→ │  CONDITIONS  │ ──→ │   ACTIONS    │
│ (any module)│     │ (any module) │     │ (any module) │
└─────────────┘     └──────────────┘     └──────────────┘
```

**Exemples cross-module** :

```yaml
Automation 1 : "Suspension proactive"
  TRIGGER: billing.payment_failed (3e tentative)
  AND:     documents.compliance_below(50%)
  THEN:    billing.suspend_company
  AND:     notifications.send(company_admin, "Compte suspendu : impayé + non-conformité")
  AND:     support.create_ticket("Suivi suspension automatique")

Automation 2 : "Onboarding membre"
  TRIGGER: members.member_joined
  THEN:    documents.request_all_required_documents(member)
  AND:     notifications.send(member, "Bienvenue, voici les documents nécessaires")
  WAIT:    7 days
  IF:      documents.member_compliance_below(100%)
  THEN:    notifications.send(member, "Rappel : documents manquants")
  AND:     notifications.send(company_admin, "Membre incomplet après 7j")

Automation 3 : "Forecast billing"
  TRIGGER: schedule.monthly_first
  THEN:    ai.predict(revenue, next_3_months)
  AND:     ai.detect_anomalies(billing.all_invoices, last_30_days)
  AND:     notifications.send(billing_admins, "Rapport mensuel AI")

Automation 4 : "Client à risque"
  TRIGGER: schedule.weekly
  CONDITION: billing.subscription_active
  AND:       support.tickets_count(last_30_days) > 3
  AND:       members.active_users_ratio < 0.3
  THEN:      ai.classify(churn_risk, client)
  IF:        churn_risk > 70%
  THEN:      notifications.send(account_manager, "Client à risque de churn")
  AND:       inbox.create_action("Contacter {company}", priority: high)
```

## 5.2 Visual Rule Builder

L'admin construit ses automations visuellement :

```
┌──────────────────────────────────────────────────────────┐
│ 🔧 Nouvelle Automation                                    │
│                                                           │
│ ┌─ Quand... ──────────────────────────────────────────┐  │
│ │ Module: [Billing ▾]                                  │  │
│ │ Événement: [Paiement échoué ▾]                       │  │
│ │ Filtre: [3e tentative ▾]                             │  │
│ └──────────────────────────────────────────────────────┘  │
│            │                                              │
│            ▼                                              │
│ ┌��� Si... (optionnel) ────────────────────────────────┐   │
│ │ Module: [Documents ▾]                               │   │
│ │ Condition: [Compliance inférieure à ▾] [50%]        │   │
│ └─────────────────────────────────────────────────────┘   │
│            │                                              │
│            ▼                                              │
│ ┌─ Alors... ─────────────────────────────────────────┐   │
│ │ Action 1: [Billing → Suspendre l'entreprise ▾]      │   │
│ │ Action 2: [Notification → Envoyer email admin ▾]    │   │
│ │ Action 3: [Support → Créer ticket ▾]                │   │
│ │ [+ Ajouter une action]                              │   │
│ └─────────────────────────────────────────────────────┘   │
│                                                           │
│ 🧪 Simulation : "Cette règle aurait déclenché 3 fois     │
│    le mois dernier, affectant 2 entreprises"             │
│                                                           │
│ [Simuler] [Sauvegarder en brouillon] [Activer]          │
└──────────────────────────────────────────────────────────┘
```

## 5.3 Simulation ("Dry Run")

Avant d'activer une automation, l'admin peut **simuler** :

```
"Si cette règle avait été active les 30 derniers jours :
  → 12 déclenchements
  → 3 suspensions
  → 9 notifications envoyées
  → 2 tickets créés

  Entreprises affectées :
  → LogiTrans SARL (2 fois)
  → Express Route (1 fois)
  → MobiFleet (1 fois)"
```

Cela élimine la peur de l'automation. L'admin voit l'impact avant d'activer.

---

# 6. DIFFÉRENCIATION MARCHÉ

## 6.1 Pourquoi Leezr est une plateforme supérieure

| Concurrent | Ce qu'ils font | Ce que Leezr fait de différent |
|-----------|----------------|-------------------------------|
| Monday.com | Boards configurables | AI transverse intégrée + automations cross-modules |
| Notion | Docs + bases de données | Vrai moteur opérationnel (billing, compliance, workflows) |
| Odoo | ERP monolithique | Modulaire, API-first, UX moderne, AI-native |
| HubSpot | CRM + Marketing | Operations-focused, pas sales-focused |
| Freshworks | Suite d'outils séparés | Plateforme unifiée, un seul realtime, une seule AI |

## 6.2 Les 5 avantages uniques

### 1. AI-Native Platform
L'AI n'est pas un add-on. C'est un engine du core. Chaque module bénéficie d'analyse, prédiction, suggestion, explication — sans intégration supplémentaire. Les concurrents ajoutent "AI" comme feature marketing. Chez Leezr, l'AI est l'infrastructure.

### 2. Cross-Module Automations
Les automations ne sont pas enfermées dans un module. "Si paiement échoué ET document expiré → suspendre + notifier + créer ticket". Aucun concurrent B2B ne fait ça nativement.

### 3. Realtime-First
Pas du polling maquillé. Du vrai temps réel (SSE/Streams) intégré dans le core. Activity Feed, Inbox, Collaborative Presence — l'entreprise est vivante dans l'app.

### 4. Module Marketplace (futur)
Le contrat module standard permet à des tiers de créer des modules. Un intégrateur peut créer un module "Fleet Management" ou "Quality Control" qui hérite automatiquement de l'AI, des automations, du realtime, des notifications.

### 5. Copilote, pas Dashboard
Les autres montrent des chiffres. Leezr montre ce qui **nécessite attention** et **propose l'action**. La différence entre un tableau de bord et un copilote.

## 6.3 Le "Wow Effect" repensé

**Pour le prospect en démo** :
- "Regardez : un membre vient d'être ajouté. Leezr a automatiquement demandé ses documents, programmé les relances, et l'AI analysera chaque document soumis. Zéro action manuelle."
- "Ce client a 3 factures en retard et ses documents expirent. Leezr a détecté le pattern et recommande une action. Un clic."
- "Tapez ⌘K et cherchez n'importe quoi — un membre, une facture, un document. Tout est là."

**Pour le client existant (rétention)** :
- "Ce mois, Leezr a traité 67% de vos documents sans intervention. Vous avez économisé 15 heures."
- "Votre score compliance est passé de 78% à 94%. Voici pourquoi."
- "2 clients à risque de churn détectés par l'AI. Voici les signaux."

---

# 7. ROADMAP V2 — PLATFORM-FIRST

## Phase V2-1 : Core Platform Hardening (Semaines 1-4)

> **Rendre le core robuste : realtime, state, UX shell**

| Livrable | Catégorie |
|----------|-----------|
| Smart merge tous les stores (37 restants) | State |
| Error boundaries globales | UX |
| Toast queue multi-instance | UX |
| Empty states + Loading hierarchy | UX |
| Drawers dirty-check | UX |
| Optimistic updates CRUD | UX |
| Transitions CSS significatives | UX |
| Module Interface formalisé (contrat TypeScript/PHP) | Architecture |

---

## Phase V2-2 : Realtime + Activity Layer (Semaines 5-8)

> **La plateforme prend vie : events, inbox, feed**

| Livrable | Catégorie |
|----------|-----------|
| Redis Streams transport | Infra |
| SSE sur tous les modules | Realtime |
| Live Pulse indicator (navbar) | Realtime |
| Activity Feed multi-module | Feature |
| Inbox Intelligent multi-module | Feature |
| Browser notifications | UX |
| Collaborative Presence (awareness) | Feature |
| Event Stream side panel | Feature |

---

## Phase V2-3 : AI Engine Transverse (Semaines 9-14)

> **L'AI devient un engine du core, pas un feature de Documents**

| Livrable | Catégorie |
|----------|-----------|
| AI Engine abstraction (primitives: analyze, classify, predict, suggest, explain) | Architecture |
| AI Copilote global (santé entreprise multi-module) | Feature |
| AI Explain transverse (chaque décision expliquée) | Feature |
| Documents × AI : auto-review avec seuils | Feature |
| Billing × AI : anomaly detection, churn prediction | Feature |
| Support × AI : classification, priorité, suggestion | Feature |
| Members × AI : compliance risk scoring | Feature |
| AI Insights proactifs par module | Feature |
| AI progress UX (progress bar, phases, timeout) | UX |

---

## Phase V2-4 : Automation Engine Cross-Module (Semaines 15-18)

> **Les automations connectent tous les modules entre eux**

| Livrable | Catégorie |
|----------|-----------|
| Module trigger/action/condition declarations | Architecture |
| Automation Engine (event-driven + scheduled + conditional) | Feature |
| Visual Rule Builder | Feature |
| Simulation ("dry run") | Feature |
| Cross-module automation templates | Feature |
| Operations Center cockpit | Feature |
| AI-suggested automations | Feature |

---

## Phase V2-5 : Platform Differentiation (Semaines 19-24)

> **Les features qui font de Leezr une plateforme unique**

| Livrable | Catégorie |
|----------|-----------|
| Command Bar (⌘K) avec Search Engine | Feature |
| Smart Onboarding adaptatif | Feature |
| Module-standardized Cockpits (layout commun) | UX |
| Company Health Score (score global AI) | Feature |
| Predictive UX (prefetch, intent, smart next-action) | UX |
| AI Chat (langage naturel cross-module) | Feature |
| Module Marketplace foundation (contrat + sandbox) | Architecture |
| Public API v1 | Architecture |

---

## Timeline

```
Sem 1-4    ████████   V2-1 : Core Platform Hardening
Sem 5-8    ████████   V2-2 : Realtime + Activity Layer
Sem 9-14   ████████████ V2-3 : AI Engine Transverse
Sem 15-18  ████████   V2-4 : Automation Engine Cross-Module
Sem 19-24  ████████████ V2-5 : Platform Differentiation
```

**24 semaines. Une plateforme. Pas un vertical.**

---

# 8. LE MOT DE LA FIN

Leezr V1 a construit les briques : modules, multi-tenant, billing, documents, AI, realtime.

Leezr V2 connecte les briques : un core intelligent où chaque module bénéficie des mêmes engines (AI, automations, realtime, notifications, search).

Le résultat n'est pas un meilleur outil de gestion. C'est un **Operating System for Business Operations** — modulaire, intelligent, temps réel.

> L'entreprise n'utilise pas Leezr.
> L'entreprise **tourne** sur Leezr.
