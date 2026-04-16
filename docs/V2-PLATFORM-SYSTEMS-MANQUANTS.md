# Systèmes Platform Manquants — V2

> Date : 2026-04-13
> Contexte : Pour piloter un SaaS sérieux, il faut des systèmes transverses, pas juste des pages CRUD.

---

## 1. Alert Center (système transverse)

**Pourquoi il manque** : Chaque module a ses propres erreurs/problèmes mais aucune agrégation. Les security alerts existent isolément. Les billing failures sont vues uniquement dans billing. Les AI degradations dans AI. L'admin doit naviguer dans 5+ pages pour savoir si "tout va bien".

**Valeur business** : Réduction du MTTR (Mean Time To Resolution). Un incident billing non détecté pendant 3 jours = perte de revenue + churn.

**Utilisateurs cibles** : Platform admins, billing ops, support ops.

**Scope** : Platform uniquement.

**Sources d'alertes** :
| Source | Exemples d'alertes |
|--------|-------------------|
| Billing | Payment failed (>3 retries), invoice overdue >30j, dunning stage 3, checkout stuck >1h |
| Security | Brute force detected, suspicious login, credential compromise |
| AI | Provider down, latence >10s, quota company >80%, error rate >5% |
| Infra | Cron failed 3x consécutifs, queue depth >1000, SSE disconnected, disk >90% |
| Support | Ticket non assigné >24h, SLA breach, ticket urgent non traité >4h |
| Business | Company inactive >30j, trial expire tomorrow (no payment method), downgrade detected |

**Dépendances** :
- Backend : `AlertRegistry` + `AlertEvaluator` cron (évalue les conditions toutes les 5min)
- Frontend : page `/platform/alerts` + badge counter dans le sidebar
- Stockage : table `platform_alerts` (polymorphique, source/type/severity/status/company_id)

**Priorité** : **P0** — sans ça, on est aveugle.

---

## 2. Company Health Score (système calculé)

**Pourquoi il manque** : Aucun moyen de savoir si une company va bien ou est en danger. Il faut ouvrir le company detail, regarder billing, support, activity séparément. Aucune synthèse.

**Valeur business** : Détection précoce du churn. Une company avec un health score dégradé peut être contactée proactivement avant qu'elle ne parte.

**Utilisateurs cibles** : Platform admins, customer success (futur).

**Scope** : Calculé backend, affiché dans supervision/company detail/dashboard.

**Composantes du score (0-100)** :
| Signal | Poids | Calcul |
|--------|-------|--------|
| Paiement à jour | 30% | Factures overdue = 0 ? 100 : (1 = 60, 2+ = 20, >30j = 0) |
| Activité récente | 25% | Dernière connexion owner <7j = 100, <30j = 60, >30j = 20, >90j = 0 |
| Profils complets | 15% | % de membres avec profil complet |
| Usage modules | 15% | % de modules activés qui sont réellement utilisés |
| Support | 15% | 0 tickets ouverts = 100, 1-2 = 80, 3+ = 40, SLA breach = 0 |

**Couleurs** : 80-100 = green (healthy), 60-79 = orange (attention), <60 = red (at risk)

**Dépendances** :
- Backend : `CompanyHealthScoreCalculator` (cron daily ou on-demand)
- Stockage : colonne `health_score` + `health_calculated_at` sur `companies`
- Frontend : badge dans supervision list, bio panel company detail, dashboard

**Priorité** : **P1** — clé pour l'intelligence admin.

---

## 3. Revenue Intelligence (système analytics)

**Pourquoi il manque** : Les widgets billing donnent des chiffres instantanés (MRR now, AR outstanding now). Aucune analyse de tendance, aucun cohort, aucun breakdown. Impossible de répondre à "est-ce que le business croît ?" ou "quel plan génère le plus de revenue ?".

**Valeur business** : Pilotage stratégique. Pricing decisions basées sur des données réelles au lieu d'intuitions.

**Utilisateurs cibles** : Platform admins, founders, finance.

**Scope** : Page analytics + API backend.

**Métriques requises** :
| Métrique | Calcul |
|----------|--------|
| MRR | Sum(subscriptions actives * monthly_price) |
| MRR Growth | MRR[mois] - MRR[mois-1] decomposé en New/Expansion/Contraction/Churn |
| Churn Rate | Companies churned / Companies début de mois |
| NRR | (MRR_début + expansion - contraction - churn) / MRR_début |
| ARPU | MRR / nombre de companies payantes |
| LTV | ARPU / churn_rate_mensuel |
| Revenue by plan | Breakdown par plan |
| Revenue by market | Breakdown par market |
| Trial conversion | Trials started / Trials converted dans une période |

**Dépendances** :
- Backend : `RevenueMetricsCalculator` (daily snapshot dans `financial_snapshots`)
- Les `FinancialSnapshot` existent déjà ! Il faut les exploiter davantage.
- Frontend : page analytics avec ApexCharts (déjà disponible via preset)

**Priorité** : **P1** — sans analytics revenue, le billing est un outil comptable, pas un outil de pilotage.

---

## 4. Activity Feed Global (système d'événements)

**Pourquoi il manque** : L'infrastructure d'audit existe (60+ types d'actions, DiffEngine, PlatformAuditLog + CompanyAuditLog), mais elle est enterrée dans des onglets "Logs" techniques. Aucune vue chronologique globale "Qu'est-ce qui s'est passé ?".

**Valeur business** : Traçabilité opérationnelle. Quand un admin arrive le matin, il voit ce que les autres admins ont fait et ce que le système a fait automatiquement.

**Utilisateurs cibles** : Tous les platform admins.

**Scope** : Page `/platform/activity` + widget dashboard.

**Différence avec les audit logs existants** :
| Audit Logs (existant) | Activity Feed (nouveau) |
|------------------------|------------------------|
| Technique, raw data | Humain, phrasé lisible |
| Filtrage par action/actor/target | Timeline chronologique |
| Pas d'agrégation | Agrégation intelligente ("12 invoices created") |
| Enterré dans des onglets | Page dédiée + widget dashboard |
| Pas de navigation | Clic → objet concerné |

**Dépendances** :
- Backend : endpoint `GET /platform/activity` qui projette les AuditLogs existants en format lisible
- Frontend : composant Timeline (preset Vuetify existant)
- Pas de nouvelle table — réutilise `platform_audit_logs` + `company_audit_logs`

**Priorité** : **P1** — faible effort, forte valeur (le backend existe déjà).

---

## 5. Usage Monitoring (système de métriques)

**Pourquoi il manque** : Aucune visibilité sur comment les companies utilisent la plateforme. On vend des modules et de l'IA mais on ne sait pas qui les utilise, combien, à quel coût.

**Valeur business** : Pricing ajustement (modules sous-utilisés = trop chers ou mal positionnés). Identification des companies dormantes (churn signal). Cost management AI.

**Utilisateurs cibles** : Platform admins, product management.

**Métriques tracées** :
| Métrique | Source | Granularité |
|----------|--------|-------------|
| Membres actifs (7j/30j) | Login events | Par company |
| Documents uploadés | Document creation events | Par company |
| AI tokens consommés | AI usage store (existe déjà) | Par company, par module |
| Modules activés vs utilisés | Module activations + usage events | Par company |
| Dernière connexion owner | Auth events | Par company |

**Dépendances** :
- Backend : `UsageMetricsCollector` (daily cron, agrège depuis les events existants)
- Stockage : table `company_usage_snapshots` (daily rollup)
- Frontend : page `/platform/usage` + colonne dans supervision list

**Priorité** : **P2**

---

## 6. SLA & Support Operations (système support)

**Pourquoi il manque** : Le support existant est un ticket system basique. Pas de SLA, pas de satisfaction, pas de templates, pas de contexte company inline.

**Valeur business** : Un support lent ou sans suivi = churn. Le SLA tracking permet de garantir un niveau de service.

**Composantes** :
| Feature | Détail |
|---------|--------|
| SLA Rules | Définir : temps de première réponse, temps de résolution, par priorité |
| SLA Tracking | Calcul automatique : breached/on-track/warning par ticket |
| SLA Dashboard | KPIs : avg response time, avg resolution time, breach count, trend |
| Company context | Dans le ticket detail : plan, revenue, health score, derniers tickets |
| Canned responses | Templates de réponse réutilisables |
| CSAT | Email post-résolution avec notation (1-5) |

**Dépendances** :
- Backend : `SlaPolicy` model, `SlaEvaluator` cron, `CannedResponse` model
- Frontend : enrichissement de la page support existante

**Priorité** : **P2**

---

## 7. Feature Flags & Rollout (système de contrôle)

**Pourquoi il manque** : Pas de mécanisme pour activer une feature progressivement (10% des companies, puis 50%, puis 100%). Le module system est binaire (on/off).

**Valeur business** : Déploiement progressif = moins de risque. A/B testing possible. Canary deployments.

**Scope** :
- Flags définis par feature/module
- Ciblage : par company, par plan, par marché, par %, par date
- Override par company (force on/off)

**Dépendances** :
- Backend : `FeatureFlag` model, `FeatureFlagEvaluator` service
- Frontend : page admin pour gérer les flags

**Priorité** : **P3** — nice to have, pas bloquant maintenant.

---

## 8. Scheduler & Jobs Center (enrichissement automations)

**Pourquoi il manque** : Automations couvre les scheduled tasks mais pas les jobs queue (background jobs). Quand un job fail, on le voit uniquement dans les logs serveur.

**Valeur business** : Visibilité opérationnelle complète. Un job AI qui fail silencieusement = documents non traités = company frustrée.

**Composantes** :
| Feature | Détail |
|---------|--------|
| Failed jobs viewer | Voir les failed_jobs avec payload, exception, stack trace |
| Retry failed job | Retry individuel ou bulk |
| Job metrics | Processing rate, avg duration, fail rate par queue |
| Dead letter monitoring | Existe déjà dans billing recovery, généraliser |

**Dépendances** :
- Backend : Laravel `failed_jobs` table (existe déjà), endpoint pour lister/retry
- Frontend : enrichir la page automations avec un onglet "Failed Jobs"

**Priorité** : **P2**

---

## 9. Import/Export Governance

**Pourquoi il manque** : Markets et translations ont import/export, mais pas de vue globale de "quels imports/exports sont possibles" ni de log d'exécution.

**Valeur business** : Faible — les imports existants suffisent.

**Priorité** : **P3**

---

## Matrice de priorisation

| Système | Priorité | Effort | Impact produit | Impact business | Backend existe ? |
|---------|----------|--------|----------------|-----------------|------------------|
| Alert Center | P0 | Moyen | Critique | Critique | Partiel |
| Company Health Score | P1 | Faible | Fort | Fort | Non |
| Revenue Intelligence | P1 | Moyen | Fort | Critique | Partiel |
| Activity Feed | P1 | Faible | Fort | Moyen | Oui (audit logs) |
| Usage Monitoring | P2 | Moyen | Moyen | Fort | Partiel (AI) |
| SLA Support Ops | P2 | Moyen | Fort | Fort | Non |
| Jobs Center | P2 | Faible | Moyen | Moyen | Oui (failed_jobs) |
| Feature Flags | P3 | Fort | Moyen | Moyen | Non |
| Import/Export Gov | P3 | Faible | Faible | Faible | Partiel |
