# Audit ciblé — Dashboard Platform : Cockpit historique vs widgets P2

> **Date** : 2026-11-10 · **Nature** : AUDIT LECTURE SEULE (aucun développement) · **Réf.** : ADR-441 (cockpit), ADR-595 (permissions dashboard), ADR-746/747/748b (widgets P2)
> **Méthode** : lecture du code rendu réel + 4 investigations parallèles + vérification des **données réelles** en base (DEV `leezr`).

---

## 1. Arbre de rendu réel de la page

Page : `resources/js/pages/platform/dashboard/[tab].vue` → onglet **Overview** = `_DashboardOverview.vue`.

```
platform/dashboard/[tab].vue  (layout platform)
└── _DashboardOverview.vue
    ├── <h4> Titre
    ├── ① ATTENTION REQUISE ........... COCKPIT (ADR-441)   ← historique
    │     source: dashboardStore.loadCockpit() → GET /platform/dashboard/attention
    │     gate: view_dashboard UNIQUEMENT
    ├── ② SANTÉ SYSTÈME (badges) ...... COCKPIT (ADR-441)   ← historique + DOUBLON
    │     source: GET /platform/dashboard/health  (scheduler/queue/ai/alerts)
    │     gate: view_dashboard UNIQUEMENT
    ├── ③ 5 CARTES KPI ................ COCKPIT (ADR-441)   ← historique + DOUBLONS
    │     companies / platformUsers / companyUsers / roles / modules
    │     source: 5 stores admin, chacun permission-gated serveur
    └── ④ DashboardGrid (moteur premium P2) ← nouveau
          source: dashboardStore.loadDashboard()/resolveWidgets()
          POST /platform/dashboard/widgets/data  (enforcement PAR widget)
          7 widgets : commercial_overview, plan_distribution, companies_overview,
                      system_health, support_overview, security_overview, catalog_overview
          + Drawer catalogue + persistance layout (platform_user_dashboard_layouts)
```

**Constat structurel** : deux générations d'interface sont **empilées**. Le cockpit ADR-441 (blocs ①②③, codés en dur dans la page) occupe tout le haut, **au-dessus** du moteur de widgets premium (④) qui est pourtant la surface cible. ② et ③ **recouvrent fonctionnellement** des widgets de ④.

| Bloc | Fichier | Données | Condition d'affichage | Permission | Propriétaire | Refresh | Maintenance | Utilité |
|---|---|---|---|---|---|---|---|---|
| ① Attention requise | `_DashboardOverview.vue:185-271` + `DashboardCockpitController@attention` | live COUNT (5 types) | `attentionItems.length>0` | **view_dashboard** (trop large) | platform.dashboard | onMounted, **pas de polling**, **pas de cache** | actif | **ÉLEVÉE** (actionnable) si permissions corrigées |
| ② Santé système badges | `_DashboardOverview.vue:285-314` + `DashboardCockpitController@health` | live, inline | toujours (hors loading) | **view_dashboard** (trop large) | platform.dashboard | onMounted, pas de cache | **redondant** | FAIBLE (doublon du widget) |
| ③ Cartes KPI | `_DashboardOverview.vue:328-373` + 5 stores | pagination `.total` / `.length` | `cards.length>0` (filtré par perm) | 5 perms fines ✓ | 5 modules admin | onMounted | partiellement redondant | MIXTE (voir §5) |
| ④ Widgets | `DashboardGrid.vue` + `DashboardWidgetRegistry` | read-models serveur | layout utilisateur | **par widget** ✓ | modules respectifs | par résolution layout | actif | ÉLEVÉE |

---

## 2. Audit de l'ancien Cockpit (blocs ① ②)

### 2.1 « Attention requise » — logique réelle (`DashboardCockpitController@attention`)

5 items émis uniquement si `count>0` : `invoices_overdue`, `tickets_unassigned`, `subscriptions_pending`, `payments_failed`, `alerts_critical`. **Aucun `label`** dans la réponse (le docblock le promet mais le code ne l'émet pas → le front résout via `type` en i18n).

**Factures en retard > 7 jours** (`:34-48`) :
```php
Invoice::withoutCompanyScope()
    ->whereIn('status', ['overdue', 'open'])
    ->where('due_at', '<', now()->subDays(7))->count();
```
- Statuts comptés : `overdue` **et** `open` (une facture `open` échue depuis >7j est bien « en retard » — défendable).
- Délai calculé depuis **`due_at`** (date d'échéance), pas la date d'émission. ✔
- Exclusion des régularisées : **uniquement par le statut**, **aucun garde `paid_at IS NULL`**. Risque latent : une facture marquée `paid` mais restée au statut `open` serait comptée. *(Donnée actuelle : 0 cas — voir §5.)*
- Sévérité : `critical` si >5, sinon `warning`. Drill-down : onglet Billing/invoices. ✔

**Abonnements en attente** (`:67-80`) :
```php
Subscription::withoutCompanyScope()->where('status', 'pending')->count();
```
- Compte **uniquement `status='pending'`** (validation admin requise). **N'inclut PAS `pending_payment`** — pourtant un état distinct du modèle (`Subscription.php`). Les abonnements bloqués en attente de paiement sont **invisibles** de cette alerte → couverture incomplète du « vrai besoin d'intervention ».
- Sévérité : toujours `warning` (pas d'escalade). Drill-down : onglet subscriptions. ✔

> **Vérité terrain confirmée** (base DEV) : `invoices où status∈(overdue,open) & due_at<now-7j = 2` ✔ (constat « 2 factures »), `subscriptions pending = 1` ✔ (constat « 1 abonnement en attente »). Les alertes reflètent de **vrais** chiffres.

### 2.2 « Santé système » badges — `DashboardCockpitController@health`

Logique **codée en dur dans le contrôleur** (pas de service), 4 badges : scheduler, queue, ai, alerts.
- **Scheduler** (`:123-134`) : compte les lignes `ScheduledTaskRun` des 2 dernières heures. `total=0 → unknown` · `failed>2 → critical` · `failed>0 → warning` · sinon `ok`.
- **Queue** (`:137-153`) : via `ScheduledTaskRegistry::queueStats()`. `failed>5 → critical` · `depth>100 → warning` · sinon `ok`.
- **AI** (`:155-160`) : **codé en dur `ok`/« Operational »** — aucune sonde réelle, ne peut jamais remonter un incident.
- **Alerts** (`:162-170`) : `PlatformAlert::active()->critical()`.

→ **Détail complet de la divergence avec le widget : §4.**

---

## 3. Audit des nouveaux widgets (④)

Registre : `app/Modules/Dashboard/DashboardWidgetRegistry.php` (+ `app/Modules/*/Dashboard/widgets.php`). `capabilities()` **inerte** (déprécié) — seule la RBAC `permissions()` gate.

| key | composant | resolver / read-model | permission | scope | source données | approx. ? |
|---|---|---|---|---|---|---|
| `platform.commercial_overview` | `_PlatformCommercialWidget` | `BillingMetricsCalculationService` + `PlatformCommercialSeriesService` | **view_billing** | both | subscriptions, plans, addon_subs | **OUI : MRR N-1 reconstruit (biais croissance)** |
| `platform.plan_distribution` | `_PlatformPlanDistributionWidget` | inline (`companies.plan_key` GROUP BY) | **view_billing** | both | companies | non |
| `platform.companies_overview` | `_PlatformCompaniesWidget` | inline | **view_companies** | both | companies (status, created_at) | non |
| `platform.system_health` | `_PlatformSystemHealthWidget` | `SystemHealthService::check()` | **view_system_health** | both | email_logs, AI, queues, scheduler, DB, disk, platform_alerts | seuils heuristiques |
| `platform.support_overview` | `_PlatformSupportWidget` | inline | **manage_support** | both | support_tickets | non |
| `platform.security_overview` | `_PlatformSecurityWidget` | inline | **security.alerts.view** | both | security_alerts | non |
| `platform.catalog_overview` | `_PlatformCatalogWidget` | inline | **manage_modules** | both | company_modules, jobdomains | non |

**Enforcement** (`DashboardWidgetController@batchResolve:61-77`) : chaque widget demandé est **vérifié individuellement** côté serveur → `error:'forbidden'` sinon. Le catalogue (`catalogForUser`) pré-filtre aussi. **Modèle RBAC correct (défense en profondeur).** C'est le modèle que le cockpit devrait suivre.

**Qualité de données** : seul `commercial_overview` approxime — l'historique MRR et le comparatif N-1 sont **reconstruits** depuis les abonnements actuellement actifs (pas de table de snapshot MRR), d'où un **biais « croissance »** (churn et changements de prix passés invisibles). Le service le **documente honnêtement** (`has_history`, `partial`, `delta_pct=null` si N-1=0). Mais présenté dans un grand graphe premium, ce caractère reconstruit n'est **pas signalé à l'utilisateur** → à qualifier visuellement.

---

## 4. Analyse du statut « Scheduler critique » & santé système

**Deux implémentations séparées et divergentes** (pas de service partagé) :

| | OLD (cockpit badge) | NEW (widget `system_health`) |
|---|---|---|
| Code | inline `DashboardCockpitController@health` | `SystemHealthService::check()` |
| Scheduler 0 run/2h | **`unknown`** (neutre) | **`critical`** (rouge) |
| AI | **codé en dur `ok`** | sonde réelle (provider + jobs + module actif) |
| Queue | pas de palier `pending>500` | palier `critical` à 500 |
| Couverture | 4 badges | 7 sections (+ email, DB, disque) |
| Vocabulaire | `ok/warning/critical/unknown` | `healthy/warning/critical` (pas d'`unknown`) |

**Mécanisme scheduler (les deux)** : comptage des lignes `ScheduledTaskRun` (écrites par `SchedulerInstrumentation::before()` comme effet de bord de l'exécution réelle d'une tâche). Ce n'est **ni** une clé de cache **ni** une sonde systemd → **pas** le motif « sonde incompatible avec l'infra ».

**Verdict faux-positif** :
- **Risque « sonde incompatible » : FAIBLE** — lit de vraies lignes DB de la même base que le scheduler écrit.
- **MAIS défaut de conception RÉEL** : un `total=0` **ne distingue pas** « cron mort » de « cron vivant mais toutes tâches désactivées/idle ». Et surtout, le signal **autoritatif existe déjà** — `SchedulerHeartbeat` (pulse chaque minute) + `ScheduledTaskRegistry::globalHealth()` (distingue explicitement `unknown`/`silent`/`dead`/`ok`) — mais **AUCUNE des 2 surfaces de santé ne l'utilise** (seul l'Automations cockpit le consomme). **C'est LE vrai défaut.**
- **Conséquence mesurée** : sur DEV, `scheduled_task_runs=0` **et** `scheduler_heartbeats=0`. Le widget `system_health` affiche donc **Scheduler = CRITIQUE** (alarme trompeuse), alors que le badge cockpit OLD affiche `unknown`. Même donnée, deux verdicts contradictoires visibles **simultanément** sur la même page.

---

## 5. Audit des compteurs administratifs

Tous les endpoints sont **gated serveur** à la bonne granularité (middleware `platform.permission:*`), le front ne fait que masquer la carte en plus.

| Compteur | Endpoint → requête | Source nombre | Permission | Terrain vérifié |
|---|---|---|---|---|
| **companies** | `GET /platform/companies` → `Company::...->paginate(20)` ; renvoie `->total()` (TOUTES : actives + suspendues) | pagination `.total` | view_companies | **20** ✔ |
| **platformUsers** | `GET /platform/platform-users` → `PlatformUser::...->paginate(20)` | pagination `.total` | manage_platform_users | 20 (via rôles pivot ; `is_platform_admin=0` en base) |
| **companyUsers** | `GET /platform/company-users` → `User::...->paginate()` (CompanyScope no-op → tous tenants) | pagination `.total` | view_company_users | 43 ✔ (= total users) |
| **roles** | `GET /platform/roles` → `PlatformRoleReadModel::catalog()` (sans pagination) | **array.length** | manage_roles | **19** ✔ |
| **modules** | `GET /platform/modules` → `PlatformModuleReadModel::catalog()` ; **le store ne garde que `data.company`** (modules scope=company) | **array.length** | manage_modules | ~23-25 (vs 51 platform_modules total) |

**Pertinence, compteur par compteur** :
- **companies** — légitime comme métrique d'échelle, **mais doublonne** `companies_overview` (qui donne en plus le statut et la tendance 7j). → carte plate superflue.
- **platformUsers / companyUsers** — métriques d'échelle acceptables, mais statiques et sans drill-down analytique ; relèvent plutôt d'un widget optionnel que de la Une.
- **roles (19)** — **ce n'est PAS un indicateur de pilotage SaaS** : c'est une donnée de **gestion RBAC** qui appartient à l'administration des accès. Afficher « 19 rôles » sur l'accueil n'aide aucune décision opérateur. → **à déplacer** vers l'admin Accès.
- **modules (25)** — idem : donnée de **gestion du catalogue**, déjà couverte par `catalog_overview` (adoption réelle). Le simple compte est décoratif sur l'accueil. → **à déplacer / fusionner**.

---

## 6. Tableau des doublons

| Information | Surface historique | Surface widget | Verdict |
|---|---|---|---|
| Santé système | ② badges cockpit (`view_dashboard`) | `system_health` (`view_system_health`) | **DOUBLON RÉEL + divergence de verdict** (scheduler unknown vs critical) |
| Nombre d'entreprises | ③ carte « Companies 20 » | `companies_overview` | **DOUBLON** (le widget est plus riche) |
| Modules | ③ carte « Modules 25 » | `catalog_overview` (adoption) | **CHEVAUCHEMENT** |
| Factures / subs / paiements | ① Attention requise | `commercial_overview` + widgets billing | Chevauchement conceptuel (alertes actionnables ≠ KPI agrégés → complémentaires si bien séparés) |
| Alertes | ② badge `alerts` = `platform_alerts` | `security_overview` = `security_alerts` | **Tables différentes** → pas un doublon dur, mais confusion possible |

---

## 7. Anomalies de métriques

1. **Subs « en attente » incomplet** : compte `pending` seul, ignore `pending_payment` (état distinct) → alerte sous-couvrante.
2. **Overdue sans garde `paid_at`** : exclusion des payées par statut seul (risque latent ; 0 cas actuel en base).
3. **MRR N-1 reconstruit** présenté sans qualification visuelle de fiabilité (biais croissance assumé en code, invisible pour l'opérateur).
4. **Badge AI (OLD) codé en dur `ok`** : ne peut jamais signaler un incident → faux sentiment de sécurité.
5. **Scheduler** : proxy grossier (2h de runs) au lieu du heartbeat autoritatif → faux `critical` sur environnement idle (cas DEV constaté).
6. **Vocabulaire de statut incohérent** entre OLD (`ok/unknown`) et NEW (`healthy`, sans `unknown`).

---

## 8. Anomalies de permissions (FUITE CONFIRMÉE)

**Cause racine** : mismatch de **granularité**, pas d'absence de middleware. Les endpoints cockpit `/dashboard/attention` et `/dashboard/health` sont gated par **`view_dashboard` seul** (`routes/platform.php:353`), alors qu'ils agrègent des données de 4 domaines de sensibilité que le moteur de widgets gate finement.

- **FUITE — utilisateur Support → données commerciales : OUI.** `view_dashboard` + `manage_support` sans `view_billing` → `/dashboard/attention` révèle factures en retard, abonnements en attente, paiements échoués (que le widget `view_billing` lui refuserait).
- **FUITE — utilisateur Finance → données Ops/Sécurité : OUI.** `view_dashboard` + `view_billing` sans `view_system_health`/`security.alerts.view` → `/dashboard/health` (scheduler/queue) + alerte critique via `/attention`, que `/system/health` lui renverrait en 403.
- **Contradiction avec l'intention ADR-595** (`DashboardModule.php:25-31`) : le split « les 2 agrégats sensibles exigent leur propre permission » a été appliqué à `/system/health` et `/usage/*` **mais pas** au badge cockpit `/dashboard/health`.

Les 5 compteurs, eux, sont **correctement gated** (pas de fuite).

---

## 9. Matrice de décision

| Bloc / indicateur | Source | Permission actuelle | Fiabilité | Doublon | **Décision** | Justification / conséquence |
|---|---|---|---|---|---|---|
| ① Attention requise (bandeau) | cockpit `/attention` | view_dashboard | vraie (données confirmées) | partiel | **CONSERVER + CORRIGER** | Vraie valeur actionnable. Corriger : filtrer par permission (§8), inclure `pending_payment`, garde `paid_at`. Reste en **en-tête** hors grille. |
| ② Santé système (badges) | cockpit `/health` | view_dashboard | divergente | **DOUBLON** | **SUPPRIMER** | Remplacé par le widget `system_health`. Pré-condition : widget dans le layout par défaut + correction scheduler. |
| ③ Carte Companies | stat | view_companies ✔ | ok | DOUBLON | **FUSIONNER** → `companies_overview` | Le widget est plus riche (statut + tendance). Retirer la carte plate. |
| ③ Carte Platform Users | stat | manage_platform_users ✔ | ok | — | **TRANSFORMER (widget optionnel)** | Métrique d'échelle ; proposer en widget catalogue, pas en Une imposée. |
| ③ Carte Company Users | stat | view_company_users ✔ | ok | — | **TRANSFORMER (widget optionnel)** | Idem. |
| ③ Carte Roles | stat | manage_roles ✔ | ok | — | **DÉPLACER** → admin Accès | Donnée RBAC, pas un KPI de pilotage SaaS. |
| ③ Carte Modules | stat | manage_modules ✔ | ok | CHEVAUCHE | **DÉPLACER / FUSIONNER** → `catalog_overview` | Donnée catalogue ; adoption déjà couverte. |
| ④ commercial_overview | widget | view_billing ✔ | MRR N-1 reconstruit | — | **CONSERVER + QUALIFIER** | Ajouter un indice visuel « historique reconstruit ». |
| ④ plan_distribution | widget | view_billing ✔ | exacte | — | **CONSERVER** | — |
| ④ companies_overview | widget | view_companies ✔ | exacte | remplace ③ | **CONSERVER** | Devient la source unique entreprises. |
| ④ system_health | widget | view_system_health ✔ | scheduler faux-critical | remplace ② | **CONSERVER + CORRIGER** | Basculer scheduler sur `globalHealth()`/heartbeat ; unifier le vocabulaire. |
| ④ support_overview | widget | manage_support ✔ | exacte | — | **CONSERVER** | — |
| ④ security_overview | widget | security.alerts.view ✔ | exacte | — | **CONSERVER** | — |
| ④ catalog_overview | widget | manage_modules ✔ | exacte | remplace ③ modules | **CONSERVER** | Source unique catalogue/adoption. |

---

## 10. Proposition de page cible

Un **cockpit unique**, permissionné de bout en bout :

```
┌ EN-TÊTE ────────────────────────────────────────────────┐
│ Titre + période globale                                  │
│ « Attention requise » : bandeau d'alertes ACTIONNABLES,  │
│   filtré par permission (billing→view_billing,           │
│   support→manage_support, alertes→security.alerts.view)  │
│   — si rien d'autorisé/actionnable : état « tout va bien »│
└──────────────────────────────────────────────────────────┘
┌ GRILLE DE WIDGETS (moteur premium, Presentation Engine) ─┐
│  commercial · plan_distribution · companies ·            │
│  system_health · support · security · catalog            │
│  → chaque widget gated serveur, adaptatif par surface,   │
│    composition propre aux permissions de l'utilisateur   │
└──────────────────────────────────────────────────────────┘
  (plus de bloc « Santé système » séparé, plus de cartes KPI plates)
```

- **Supprimé** : bloc ② (doublon santé), cartes ③ Companies/Modules (couvertes par widgets), cartes Roles (→ admin Accès).
- **Conservé/déplacé** : Platform/Company Users = widgets optionnels ; Roles/Modules = pages d'administration dédiées.
- **Préservé intégralement** : moteur de widgets, personnalisation, layouts enregistrés (`platform_user_dashboard_layouts`), Presentation Engine (ADR-748b), RBAC, ModuleManifests, données réelles. Aucune migration de données.

Un opérateur à **permissions minimales** ne voit que l'en-tête (vide si rien d'actionnable) + les widgets qu'il détient — jamais de carte à 0 non autorisée, jamais de donnée d'un autre domaine.

---

## 11. Plan de correction ciblé (exécutable — NON appliqué)

> Séquencé P0 (sécurité) → P1 (cohérence) → P2 (finitions). Chaque lot = un ADR.

**P0 — Fuite de permissions (sécurité, priorité absolue)**
- `app/Modules/Platform/Dashboard/Http/DashboardCockpitController.php` : dans `attention()` et `health()`, filtrer `$items`/`$badges` selon `$request->user('platform')->hasPermission(...)` — billing→`view_billing`, support→`manage_support`, alertes→`security.alerts.view`, badges health→`view_system_health`. (Miroir du pattern `DashboardWidgetController@batchResolve:70-73`.) Alternative : endpoints séparés par domaine.
- Tests Feature : un user Support ne reçoit aucun item billing ; un user Finance ne reçoit aucun badge health/alerte.

**P1 — Suppression des doublons & correction santé**
- `resources/js/pages/platform/dashboard/_DashboardOverview.vue` : retirer le bloc ② « Santé système » (lignes ~285-314) ; garantir `platform.system_health` dans le layout par défaut (`dashboard.store.js buildSmartDefaultLayout`). Retirer les cartes ③ Companies & Modules ; déplacer Roles (et Modules) vers leurs pages admin ; passer Platform/Company Users en widgets optionnels.
- `SystemHealthService.php` (+ `DashboardCockpitController@health` si conservé transitoirement) : basculer la détection scheduler sur `ScheduledTaskRegistry::globalHealth()` / `SchedulerHeartbeat` ; unifier le vocabulaire de statut ; supprimer l'AI codé en dur.
- Tests : `system_health` ne renvoie plus `critical` scheduler sur base idle (heartbeat → `unknown`).

**P2 — Qualité des métriques & finitions**
- `DashboardCockpitController@attention` : inclure `pending_payment` dans les subs en attente (ou libellé explicite) ; ajouter garde `whereNull('paid_at')` sur l'overdue.
- `_PlatformCommercialWidget.vue` : indice visuel « historique reconstruit » sur le comparatif N-1.
- i18n : nettoyer les clés orphelines des blocs supprimés.

---

## 12. Risques & tests nécessaires

| Risque | Mitigation / test |
|---|---|
| Filtrer le cockpit masque des items à des rôles restreints | **Intentionnel.** Tests Feature avec rôles Support/Finance ; vérifier 0 fuite inter-domaine. |
| Supprimer ② fait perdre une info santé | Garantir `system_health` dans le layout par défaut **avant** suppression ; test e2e présence widget. |
| Bascule scheduler sur heartbeat | **Lecture seule du signal** — ne touche NI scheduler NI workers. Vérifier `globalHealth()` → `unknown` (pas `critical`) sur heartbeat vide (cas DEV). |
| Déplacer Roles/Modules casse des liens | Conserver l'accès via l'admin ; redirections/nav vérifiées. |
| Layouts enregistrés contenant un widget retiré | Le registre renvoie `not_found` proprement (déjà géré `batchResolve`) ; test de non-régression layout. |
| Régression visuelle widgets | Suite ADR-748b (`presentation-resize.spec.js`) + `PlatformCommercialSeriesTest` 6/6 déjà vertes. |

---

### Annexe — données réelles vérifiées (base DEV `leezr`, 2026-11-10)
`companies=20` · `users=43` · subs : `active=5, trialing=1, past_due=1, pending=1` · invoices : `open=23, overdue=1, paid=4, void=1` · overdue>7j (overdue+open) = **2** · `platform_roles=19` · `platform_modules=51` (store garde company=~23-25) · `scheduled_task_runs=0` · `scheduler_heartbeats=0` · `billing_job_heartbeats` vide · `is_platform_admin=1 : 0 users`.

**Fin de l'audit — aucune modification de code/donnée/scheduler effectuée.**
