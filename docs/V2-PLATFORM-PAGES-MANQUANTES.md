# Pages Platform Manquantes — V2

> Date : 2026-04-13
> Contexte : Audit platform a identifié 62/100 — des pans entiers de gouvernance SaaS n'existent pas.

---

## 1. `/platform/dashboard` — Cockpit de commandement (REFONTE)

**Objectif** : Première page qu'un admin voit. Doit répondre à "Que dois-je faire maintenant ?" en 5 secondes.

**Données affichées** :
- **Attention Required** : items nécessitant une action immédiate (factures overdue >7j, tickets non assignés >24h, payments failed, subscriptions pending approval, providers IA down)
- **Revenue snapshot** : MRR actuel + variation vs mois dernier, AR outstanding, revenue MTD
- **Company pulse** : new signups (7j), trials expiring (7j), active companies, churn (30j)
- **System health** : scheduler (OK/warning/critical), AI providers, queue depth, SSE status — 4 badges
- **Recent activity feed** : 10 dernières actions admin (qui a fait quoi)
- **Quick actions** : Approve sub, assign ticket, replay DLQ, run task

**Actions disponibles** :
- Clic sur chaque item "Attention" → navigation directe vers l'objet
- Quick actions inline sans naviguer
- Toggle widget catalog (existant, à enrichir)

**Pourquoi nécessaire** : Un dashboard SaaS sans intelligence décisionnelle est un compteur inutile. L'admin ouvre cette page et doit voir les feux rouges.

**Priorité** : **P0**

---

## 2. `/platform/activity` — Flux d'activité global

**Objectif** : Timeline chronologique de tout ce qui se passe sur la plateforme. Le "journal de bord" du SaaS.

**Données affichées** :
- Timeline verticale : événements les plus récents
- Filtres : par type (billing, auth, admin, company, support, module), par sévérité, par acteur, par date
- Chaque événement : icône, acteur (admin ou système), action, cible, timestamp, diff optionnel
- Agrégation : "12 invoices generated" plutôt que 12 lignes individuelles

**Actions disponibles** :
- Filtrer par domaine
- Clic sur un événement → navigation vers l'objet concerné
- Export du feed filtré

**Pourquoi nécessaire** : L'audit existe en backend (60+ types, DiffEngine) mais est enterré dans des onglets "Logs". Aucune vue centralisée. L'admin ne sait pas ce qui s'est passé depuis sa dernière connexion.

**Priorité** : **P1**

---

## 3. `/platform/billing/analytics` — Revenue Intelligence

**Objectif** : Comprendre la santé financière du SaaS. Répondre à "Est-ce que le business grandit ?"

**Données affichées** :
- **MRR Evolution** : graphique 12 mois avec breakdown (new, expansion, contraction, churn)
- **Churn Rate** : mensuel, par plan, par marché
- **ARPU** : Average Revenue Per User, tendance
- **LTV** : Lifetime Value estimé par plan
- **Revenue by plan** : pie/bar chart
- **Revenue by market** : geographic breakdown
- **Trial → Paid conversion** : funnel avec taux par étape
- **Net Revenue Retention** : >100% = expansion > churn

**Actions disponibles** :
- Sélection de période
- Export des données
- Drill-down par plan/marché

**Pourquoi nécessaire** : Les widgets billing existent mais sont éparpillés et incomplets. Pas de vue cohort, pas de churn analysis, pas de NRR. Impossible de piloter le business sans analytics revenue.

**Priorité** : **P1**

---

## 4. `/platform/alerts` — Centre d'alertes unifié

**Objectif** : Centraliser TOUTES les alertes de TOUS les systèmes en un seul endroit.

**Données affichées** :
- **Alertes actives** groupées par sévérité (critical, warning, info)
- Sources : billing (payment failed, overdue >Xj, dunning stage), security (intrusion, brute force), AI (provider down, quota reached), infra (queue stalled, cron failed), support (ticket SLA breach)
- Chaque alerte : source, sévérité, message, timestamp, company concernée, actions possibles
- **KPIs en tête** : alertes critiques actives, alertes résolues 24h, MTTR

**Actions disponibles** :
- Acknowledge, resolve, dismiss, escalate
- Filtrer par source, sévérité, date
- Notification push pour critiques

**Pourquoi nécessaire** : La page security/index.vue ne couvre que les alertes sécurité. Les alertes billing, AI, infra n'ont AUCUNE UI. L'admin découvre les problèmes par hasard en naviguant.

**Priorité** : **P0**

---

## 5. `/platform/companies` — Company Intelligence List (REFONTE de supervision)

**Objectif** : Remplacer la liste plate supervision par une vue enrichie de métriques business.

**Données affichées** :
- Colonnes enrichies : name, plan, status, **MRR**, **members**, **dernière activité**, **health score** (badge couleur), marché
- **Health score** calculé : combinaison de activité récente + factures à jour + profils complets + usage
- Filtres avancés : par plan, par marché, par jobdomain, par health (at-risk, healthy, dormant), par statut billing
- Segments prédéfinis : "At risk" (impayés + inactifs), "High value" (MRR top 20%), "New" (< 30j), "Trial ending" (< 7j)
- Vue alternative cards (toggle)

**Actions disponibles** :
- Tous les filtres ci-dessus
- Clic → company detail
- Export CSV de la liste filtrée
- Bulk actions (suspend, change plan)

**Pourquoi nécessaire** : La supervision actuelle est une table CRUD sans intelligence. Impossible d'identifier rapidement les companies à risque ou à forte valeur.

**Priorité** : **P1**

---

## 6. `/platform/health` — System Health & Diagnostics

**Objectif** : Vue centralisée de la santé technique du SaaS. Répondre à "Est-ce que tout fonctionne ?"

**Données affichées** :
- **Infra status** : serveur web, database, Redis, queue workers, SSE — statut vert/orange/rouge
- **Cron health** : scheduler status, last run, failed tasks 24h
- **Queue health** : depth par queue (default, ai), processing rate, failed jobs
- **AI providers** : statut de chaque provider (healthy/degraded/down), latence
- **Payment gateway** : Stripe status, webhook delivery rate
- **Storage** : disk usage, upload stats
- **Performance** : avg response time, error rate (si disponible)

**Actions disponibles** :
- Retry failed jobs
- Flush queue
- Kill switch realtime
- Run health check

**Pourquoi nécessaire** : Les infos sont éparpillées (automations pour crons, realtime pour SSE, AI pour providers, recovery pour billing). Aucune vue d'ensemble "est-ce que la plateforme fonctionne ?".

**Priorité** : **P2**

---

## 7. `/platform/search` — Recherche globale

**Objectif** : Trouver n'importe quoi sur la plateforme depuis un seul champ de recherche.

**Données affichées** :
- Résultats groupés par type : Companies, Users, Invoices, Tickets, Plans, Modules
- Chaque résultat : nom/numéro, type badge, status chip, action (naviguer)
- Suggestions rapides pendant la frappe (autocomplete)

**Actions disponibles** :
- Navigation directe vers l'objet trouvé
- Filtrer par type de résultat

**Pourquoi nécessaire** : Un admin qui cherche une company, un ticket, une facture par numéro doit naviguer dans 5+ pages différentes. La recherche globale est standard dans tout produit SaaS admin.

**Priorité** : **P2**

---

## 8. `/platform/usage` — Usage Monitoring par Company

**Objectif** : Comprendre comment chaque company utilise la plateforme. Identifier les sur-consommateurs et les dormants.

**Données affichées** :
- **Tableau** : company, membres actifs (7j), documents uploadés (30j), AI tokens (30j), dernière connexion owner
- **Agrégats** : total API calls, storage utilisé, AI tokens consommés
- **Tendance** : graphique d'usage agrégé (30j)
- **Anomalies** : companies avec usage anormal (spike ou chute)

**Actions disponibles** :
- Filtrer par plan, marché
- Drill-down vers company detail
- Export

**Pourquoi nécessaire** : Aucune visibilité sur l'usage réel. Impossible de savoir si les companies utilisent vraiment le produit, quels modules sont populaires, qui surconsomme l'IA.

**Priorité** : **P2**

---

## 9. `/platform/onboarding` — Funnel d'onboarding

**Objectif** : Suivre le parcours des nouvelles companies de l'inscription au premier paiement.

**Données affichées** :
- **Funnel** : Inscription → Setup complété → Premier membre ajouté → Premier document → Premier paiement
- **Trials actifs** : liste avec jours restants, étapes complétées, owner
- **Conversion rate** : trial → paid (7j, 30j, 90j)
- **Abandons** : companies inscrites mais inactives depuis X jours

**Actions disponibles** :
- Contacter owner d'un trial (email)
- Étendre un trial
- Convertir en gratuit/suspendu

**Pourquoi nécessaire** : Aucune visibilité sur le funnel d'acquisition. On ne sait pas combien de trials convertissent, à quel moment ils abandonnent, quels sont les points de friction.

**Priorité** : **P3**

---

## 10. `/platform/incidents` — Gestion d'incidents

**Objectif** : Quand quelque chose tourne mal (panne, bug, incident billing), documenter et suivre la résolution.

**Données affichées** :
- **Incidents ouverts** : titre, sévérité, date de début, durée, systèmes impactés
- **Timeline** : événements de l'incident (détection → investigation → fix → post-mortem)
- **Incidents passés** : historique pour pattern detection

**Actions disponibles** :
- Créer un incident
- Ajouter une note/mise à jour
- Résoudre/fermer
- Lier à des alertes existantes

**Pourquoi nécessaire** : Actuellement, les incidents sont gérés en dehors de la plateforme (Slack, email). Pas d'historique, pas de post-mortem, pas de MTTR tracké.

**Priorité** : **P3**

---

## 11. `/platform/ai/operations` — AI Operations (REFONTE tab usage)

**Objectif** : Monitoring opérationnel temps réel de l'IA — au-delà de la config.

**Données affichées** :
- **Live** : jobs en cours, queue depth, latence moyenne actuelle
- **Cost tracking** : tokens consommés → coût estimé (par provider pricing), breakdown par module et par company
- **Alertes** : quota atteint, provider dégradé, latence >Xs
- **Top consumers** : companies qui consomment le plus d'IA (30j)
- **Error rate** : par provider, tendance

**Actions disponibles** :
- Bloquer une company sur-consommatrice
- Rerouler un provider
- Ajuster le quota global

**Pourquoi nécessaire** : L'AI coûte de l'argent (tokens API). Aucune visibilité sur les coûts réels, aucune alerte sur les dérapages, aucun breakdown par company.

**Priorité** : **P2**

---

## Récapitulatif

| Page | Type | Priorité | Effort | Backend existant ? |
|------|------|----------|--------|-------------------|
| Dashboard (refonte) | REFONTE | P0 | Moyen | Oui (widgets, stats) |
| Alert Center | NOUVEAU | P0 | Moyen | Partiel (security alerts) |
| Activity Feed | NOUVEAU | P1 | Faible | Oui (audit logs 60+ types) |
| Revenue Analytics | NOUVEAU | P1 | Moyen | Partiel (metrics, MRR widgets) |
| Company Intelligence | REFONTE | P1 | Moyen | Oui (companies + billing) |
| System Health | NOUVEAU | P2 | Faible | Oui (automations, realtime, AI health) |
| Global Search | NOUVEAU | P2 | Moyen | À créer (endpoint search) |
| Usage Monitoring | NOUVEAU | P2 | Moyen | Partiel (AI usage) |
| AI Operations | REFONTE tab | P2 | Moyen | Oui (AI health, usage) |
| Onboarding Funnel | NOUVEAU | P3 | Moyen | Partiel (companies, subscriptions) |
| Incident Center | NOUVEAU | P3 | Fort | À créer |
