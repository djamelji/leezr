# Audit Produit Platform V2 — Page par page

> Date : 2026-04-13
> Scope : Toutes les pages `/platform/...` — 33 routes, 83 sous-composants

---

## Inventaire global

| Domaine | Pages | Sous-composants | Niveau produit |
|---------|-------|-----------------|---------------|
| Dashboard | 1 | 0 | MVP |
| Supervision (companies) | 2 | 7 | Correct |
| Billing | 4 | 27 | Bon |
| Plans | 2 | 0 | Correct |
| Modules | 2 | 2 | Bon |
| AI | 1 | 9 | Correct |
| Access (users/roles) | 2 | 4 | Correct |
| Support | 2 | 0 | MVP |
| International | 2 | 4 | Correct |
| Jobdomains | 2 | 1 | Correct |
| Settings | 1 | 7 | Correct |
| Automations | 1 | 0 | Bon |
| Security | 1 | 0 | MVP |
| Realtime | 1 | 0 | MVP |
| Documents | 2 | 0 | MVP |
| Documentation | 2 | 0 | Correct |
| Notifications | 1 | 0 | MVP |
| Account | 1 | 3 | Correct |
| Auth (login/forgot/reset) | 3 | 0 | Correct |
| Fields | 1 | 0 | Correct |

**Score global platform : 62/100** — Infrastructure technique solide, mais vision pilotage/décision quasi absente.

---

## Audit page par page

### 1. Dashboard (`/platform`)

**Rôle actuel** : Affiche 5 KPI (companies, platform users, company users, roles, modules) + grille de widgets configurable (engine D4e.3).

**Ce qui marche** :
- Widget engine avec drag&drop, catalog, scope (global/company)
- Layout personnalisé persisté par utilisateur
- Fallback graceful (Promise.allSettled)

**Ce qui est frustrant** :
- Les 5 KPI sont des compteurs bruts sans contexte ("+3 ce mois", "tendance", rien)
- Pas de section "Ce qui nécessite votre attention" (0 actionable insights)
- Pas de signaux d'alerte (factures impayées, providers IA down, tickets non assignés)
- Les widgets disponibles sont presque tous billing — pas de diversité métier
- Pas de comparaison temporelle (vs mois dernier, vs semaine dernière)
- Pas de quick actions (approuver un sub, résoudre un ticket, etc.)

**Ce qui manque pour piloter** :
- Signaux de churn (companies inactives, impayés >30j, downgrade récents)
- Revenue snapshot (MRR actuel, trend, AR outstanding en 1 coup d'oeil)
- Queue/infra health at a glance (crons OK? AI OK? SSE OK?)
- Dernières actions admin (qui a fait quoi récemment)
- Top 5 tickets ouverts les plus anciens

**Décisions possibles depuis cette page** : Aucune. Le dashboard est read-only et non-actionable.
**Décisions impossibles mais nécessaires** : Approuver un sub pending, escalader un ticket, intervenir sur un paiement failed.

**Niveau** : MVP — le moteur widget est là mais le contenu est pauvre et non-décisionnel.

**V2 attendue** : Dashboard de commandement — KPI actionnables, signaux de risque, quick actions, health status, activity feed.

---

### 2. Supervision — Companies (`/platform/supervision/companies`)

**Rôle actuel** : Liste paginée des companies avec recherche/filtre. 3 onglets : Companies, Members (cross-company), Company Logs.

**Ce qui marche** :
- Table fonctionnelle avec status/plan chips
- Navigation vers company detail

**Ce qui est frustrant** :
- Liste plate sans intelligence — juste un tableau CRUD
- Pas de health score, pas de revenue par company, pas de "dernière activité"
- Pas de tri par revenue, par date de création, par risque
- Pas de vue lifecycle (trial → active → churn)
- Pas de segments (high-value, at-risk, dormant)

**Ce qui manque** :
- Indicateurs inline : MRR, nombre de membres, dernière connexion, factures impayées
- Filtres avancés : par plan, par marché, par jobdomain, par statut billing
- Vue cards (alternative au tableau pour les KPI)
- Export CSV/Excel de la liste filtrée

**Niveau** : Correct — fonctionnel mais pas pilotable.

**V2 attendue** : Company intelligence list — enrichie de métriques business, filtrable par signaux de risque.

---

### 3. Company Detail 360° (`/platform/companies/[id]`)

**Rôle actuel** : Vue 360° avec sidebar bio + 5 onglets (Overview, Billing, Modules, Members, Activity).

**Ce qui marche** :
- Bio panel riche (status, plan, owner, market, jobdomain, metrics)
- Billing tab profonde (subscription, invoices, payments, wallet, payment methods Stripe)
- Plan change avec preview de proration
- Wallet adjustments (credit/debit)
- Module enable/disable
- Suspend/reactivate
- Activity audit trail

**Ce qui est frustrant** :
- 1241 lignes — monolithique, lent au chargement
- Pas de health score synthétique visible immédiatement
- L'onglet Overview est un formulaire d'édition — pas un vrai overview décisionnel
- Pas de timeline visuelle du lifecycle (inscription → trial → activation → upgrade/downgrade → churn)
- Pas de métriques d'usage (API calls, documents, AI tokens, dernière connexion)

**Ce qui manque** :
- Health score en haut du bio panel (synthèse de l'état de santé)
- Revenue lifetime (LTV calculé)
- Dernière connexion owner
- Documents en attente / compliance status
- Support tickets ouverts pour cette company
- Quick actions contextuelles (envoyer un email, ajouter un crédit, etc.)

**Niveau** : Bon — c'est la page la plus aboutie de la platform. Mais elle manque la couche "intelligence".

**V2 attendue** : Company 360° avec health score, timeline lifecycle, métriques d'usage, cross-domain (support, documents, AI).

---

### 4. Billing Hub (`/platform/billing`)

**Rôle actuel** : Hub à 8 onglets — Dashboard, Subscriptions, Invoices, Dunning, Scheduled Debits, Coupons, Forensics, Recovery. + Advanced (Credit Notes, Payments, Wallets, Governance, Ledger).

**Ce qui marche** :
- Dashboard avec KPIs (MRR, ARR, refund ratio, revenue trend)
- Invoices tab exhaustive (1049 lignes !) avec toutes les mutations (pay, void, refund, retry, dunning, credit note, write-off, bulk)
- Forensics avec timeline et snapshots
- Governance avec reconciliation, financial freeze, period closing
- Recovery avec dead letters, stuck checkouts, webhook recovery
- Coupons CRUD complet (675 lignes)
- Idempotency keys sur les mutations financières

**Ce qui est frustrant** :
- 13+ onglets répartis sur 2 pages (billing + billing/advanced) — navigation confuse
- Le dashboard est un agrégat de widgets, pas un cockpit
- Pas de vue "Revenue over time" dédiée (trend chart noyé dans les widgets)
- Pas de cohort analysis (quand les companies se sont inscrites vs revenue)
- Pas d'alertes billing (payment failed il y a 3j, pas de relance)
- Les onglets Advanced sont arbitrairement séparés du hub principal

**Ce qui manque** :
- Revenue analytics page dédiée (MRR evolution, churn rate, ARPU, LTV)
- Alertes proactives (X factures overdue > 30j, Y payments failed cette semaine)
- Vue "Revenue par plan" et "Revenue par marché"
- Funnel conversion trial → paid
- Export périodique planifié

**Niveau** : Bon — le plus complet du SaaS. Mais la navigation est confuse (trop d'onglets) et il manque la couche analytics/intelligence.

**V2 attendue** : Fusion billing + advanced en un seul hub. Page analytics dédiée. Alertes billing.

---

### 5. Plans (`/platform/plans`)

**Rôle actuel** : Liste CRUD + détail avec pricing tiers.

**Ce qui marche** :
- CRUD fonctionnel
- Toggle active/inactive
- Detail avec companies_count

**Ce qui est frustrant** :
- Pas de vue comparaison des plans côte à côte
- Pas de metrics par plan (revenue, churn, adoption)
- Pas de simulation de pricing (si je change le prix, combien de revenue ?)

**Niveau** : Correct — fonctionne mais pas intelligence.

---

### 6. Modules (`/platform/modules`)

**Rôle actuel** : Liste company modules + platform modules + payment modules/rules.

**Ce qui marche** :
- Vue duale (company/platform) bien séparée
- Toggle global
- Detail exhaustif (1500 lignes !) avec addon pricing, expert mode JSON, dependencies
- Payment modules + rules

**Ce qui est frustrant** :
- Le detail à 1500 lignes est un monolithe technique — trop complexe pour un admin non-dev
- Addon pricing UI est avancée mais intimidante
- Pas de vue "quels modules génèrent le plus de revenue addon"

**Niveau** : Bon — très complet techniquement, mais trop technique pour un admin métier.

---

### 7. AI Engine (`/platform/ai`)

**Rôle actuel** : 4 onglets — Providers (card grid avec health check), Usage (métriques), Routing (config), Settings.

**Ce qui marche** :
- Provider cards avec status/health/capabilities
- Install/activate/deactivate/config drawer
- Health check par provider
- Card-grid layout propre

**Ce qui est frustrant** :
- Pas de vue opérationnelle temps réel (jobs en cours, queue depth, latence live)
- Pas d'alertes AI (provider down, quota atteint, latence dégradée)
- Usage metrics peu détaillées (pas de breakdown par company, par module, par jour)
- Pas de cost tracking (combien coûte l'IA par mois, par company)

**Niveau** : Correct — config/setup OK, mais opérations/monitoring absents.

**V2 attendue** : AI Operations page — monitoring temps réel, cost tracking, alertes, breakdown par company.

---

### 8. Access Management (`/platform/access`)

**Rôle actuel** : 3 onglets — Platform Users (CRUD + invite), Roles (CRUD + permissions), Audit Logs.

**Ce qui marche** :
- Users CRUD avec invite/password mode
- Roles avec permissions groupées par module
- Audit logs avec filtres

**Ce qui est frustrant** :
- Pas de vue "dernière connexion" par user
- Pas de gestion de sessions actives (qui est connecté maintenant ?)
- Les logs sont techniques — pas de vue "qui a fait quoi d'important cette semaine"

**Niveau** : Correct — fonctionnel.

---

### 9. Support (`/platform/support`)

**Rôle actuel** : Liste tickets avec 4 KPI (open, in-progress, waiting, unassigned) + détail avec chat + notes internes.

**Ce qui marche** :
- KPI cards clairs
- Chat avec distinction réponse/note interne
- Actions : assign, resolve, close, change priority
- Filtres par status/priority/search

**Ce qui est frustrant** :
- Pas de SLA tracking (temps de réponse moyen, tickets ouverts > 24h)
- Pas d'auto-assign
- Pas de satisfaction (CSAT) post-résolution
- Pas de templates de réponse
- Pas de lien vers la company detail depuis le ticket
- Pas de vue "mes tickets" vs "tous les tickets" pour l'admin

**Ce qui manque** :
- SLA dashboard (avg response time, resolution time, breach count)
- Canned responses / templates
- Company context inline (plan, revenue, status)
- Escalation rules

**Niveau** : MVP — fonctionnel mais pas professionnel pour du support SaaS.

**V2 attendue** : Support ops — SLA tracking, templates, company context, satisfaction metrics.

---

### 10. International (`/platform/international`)

**Rôle actuel** : 4 onglets — Markets, Languages, Translations (matrix editor), FX Rates.

**Ce qui marche** :
- Market CRUD complet avec legal statuses
- Translation matrix editor (grid editable)
- FX rate refresh
- Import/export
- Permission-filtered tabs

**Ce qui est frustrant** :
- Translation matrix peut être lent avec beaucoup de clés
- Pas de "completion status" visible par locale (80% traduit, 95% traduit)

**Niveau** : Correct — complet et fonctionnel.

---

### 11. Jobdomains (`/platform/jobdomains`)

**Rôle actuel** : CRUD + détail avec market overlays.

**Ce qui marche** :
- Detail riche (fields, modules, permissions, documents, overlays)
- Market overlay system (customization par marché)

**Ce qui est frustrant** :
- Pas de vue "impact" (si je modifie ce jobdomain, combien de companies sont affectées ?)
- Interface de configuration dense

**Niveau** : Correct.

---

### 12. Settings (`/platform/settings`)

**Rôle actuel** : 7 onglets — General, Theme, Typography, Sessions, Maintenance, Billing, Notifications.

**Ce qui marche** :
- General (brand name, version, env, build info)
- Maintenance mode avec IP whitelist
- Session TTL config
- Theme customization
- Typography avec upload de fonts

**Ce qui est frustrant** :
- 7 onglets c'est trop — Theme + Typography devraient être fusionnés en "Branding"
- Maintenance est rarement utilisé — pourrait être une action, pas un onglet permanent
- Settings Billing contient quoi ? Probablement des config qui chevauchent le billing hub

**Niveau** : Correct — fonctionne mais organisation discutable.

**V2 attendue** : Fusion Theme+Typography en Branding. Maintenance en action quick, pas en onglet.

---

### 13. Automations (`/platform/automations`)

**Rôle actuel** : Cockpit des scheduled tasks — health status, 24h stats (success, failed, avg duration, queue pending), table des tasks avec run now, detail drawer avec run history.

**Ce qui marche** :
- Monitoring clair de la santé du scheduler
- Stats 24h per-task avec health color coding
- Run history paginée dans un drawer
- Bouton "Run now" pour exécution immédiate
- Queue monitoring (queue_default, queue_ai)

**Ce qui est frustrant** :
- Uniquement les scheduled tasks — pas les workflow rules user-defined (ADR-437)
- Pas de notifications si un task fail 3 fois d'affilée
- Pas de graphique de tendance health over time

**Niveau** : Bon — le meilleur cockpit opérationnel existant.

---

### 14. Security (`/platform/security`)

**Rôle actuel** : Liste des security alerts avec filtres (status, severity, type, company).

**Ce qui marche** :
- Alerts avec severity color coding
- Actions : acknowledge, resolve, false positive
- Filtres fonctionnels

**Ce qui est frustrant** :
- Page plate sans KPI (combien d'alertes ouvertes ? trend ?)
- Pas de notification push quand une alerte critique arrive
- Pas de corrélation avec d'autres signaux (est-ce un pattern ? multi-company ?)

**Niveau** : MVP — fonctionne mais pas exploitable comme outil de sécurité.

---

### 15. Realtime (`/platform/realtime`)

**Rôle actuel** : Status SSE, métriques, connexions, flush, kill switch.

**Ce qui marche** :
- Status on/off clair
- Kill switch d'urgence
- Liste des connexions actives

**Ce qui est frustrant** :
- Page technique — pertinente pour un dev, pas pour un admin
- Pas de graphique de connexions over time

**Niveau** : MVP — outil dev, pas outil admin.

---

### 16. Documents (`/platform/documents`)

**Rôle actuel** : Catalogue des types de documents CRUD.

**Ce qui marche** :
- CRUD basique

**Ce qui est frustrant** :
- Aucune vue sur les documents des companies (combien uploadés, compliance status global)
- Pure config, aucune intelligence

**Niveau** : MVP.

---

### 17. Documentation/Help Center (`/platform/documentation`)

**Rôle actuel** : CRUD articles d'aide (topics, groups, articles).

**Ce qui marche** :
- CRUD complet avec topics/groupes/articles
- Feedback stats + search misses

**Niveau** : Correct.

---

### 18. Notifications (`/platform/notifications`)

**Rôle actuel** : Inbox in-app des notifications.

**Ce qui marche** :
- Read/unread, mark all read

**Ce qui est frustrant** :
- Pas de gouvernance (quels topics sont actifs, quels templates existent)
- C'est juste une inbox — pas un centre de notifications admin

**Niveau** : MVP.

---

## Synthèse des fusions/réorganisations nécessaires

| Action | Détail |
|--------|--------|
| **FUSIONNER** | `billing/` + `billing/advanced/` → un seul hub avec nav restructurée |
| **FUSIONNER** | Settings Theme + Typography → "Branding" |
| **PROMOUVOIR** | Dashboard → vrai cockpit de commandement |
| **CRÉER** | Page Analytics/Revenue dédiée |
| **CRÉER** | Page Activity Feed globale |
| **CRÉER** | Page Alert Center unifiée |
| **CRÉER** | Page AI Operations (monitoring live) |
| **ENRICHIR** | Supervision companies → company intelligence |
| **ENRICHIR** | Support → support ops avec SLA |
| **DÉPLACER** | Maintenance de Settings vers une action quick access |

---

## Scores par domaine

| Domaine | Score | Verdict |
|---------|-------|---------|
| Billing | 78/100 | Le meilleur — profond mais navigation confuse |
| Company 360° | 72/100 | Solide mais manque intelligence |
| Automations | 70/100 | Bon cockpit opérationnel |
| Modules | 68/100 | Complet mais trop technique |
| International | 65/100 | Fonctionnel |
| Settings | 60/100 | Organisationnel à revoir |
| Access | 60/100 | Basique mais fonctionnel |
| AI | 55/100 | Config OK, opérations absentes |
| Plans | 55/100 | CRUD pur, pas d'intelligence |
| Support | 45/100 | MVP fonctionnel |
| Dashboard | 40/100 | Presque vide d'intelligence |
| Security | 35/100 | Liste basique |
| Realtime | 30/100 | Outil dev uniquement |
| Documents catalog | 30/100 | Config pure |
| Notifications | 25/100 | Inbox minimale |

**Score global platform : 62/100**
