# LEEZR — MASTER PLAN V2 (UI/UX PREMIUM) + SPEC PHASE 0 + VISION DASHBOARD COMPANY

> Suite de `docs/bmad/UI-UX-MASTER-AUDIT.md` (audit accepté) · ADR-741.
> **Statut : SPÉCIFICATION. Aucune implémentation, migration ni déploiement.** Captures « AVANT » réelles produites (voir §0).
> Convention : **[FAIT]** vérifié code/capture · **[RECO]** · **[DÉCISION]** à arbitrer avant exécution.

---

## 0. CORRECTION A — Audit visuel réel (captures AVANT produites)

**[FAIT]** Harnais Playwright existant exploité (`playwright.config.js`, `tests/e2e/helpers.js`, comptes seeders dev `owner@leezr.test` / `admin@leezr.com`) contre l'app servie en local (`leezr.test` = 200). Nouveau spec **lecture seule** (navigation + screenshot, aucune donnée créée) : `tests/e2e/ui-audit-before.spec.js` → 11 captures dans `tests/e2e/__screens__/ui-audit-before/` (desktop light/dark + mobile), 2 tests verts.

**Ce que les captures révèlent — et qui corrige l'audit code-only :**
Le dashboard Company d'une société **peuplée** (`Leezr Logistics`, jobdomain logistique, 50 collaborateurs) **n'est pas vide** : la grille de widgets se remplit (conformité, expéditions, chauffeurs…). **Le vrai défaut est la PLATITUDE, pas le vide** :
- **Zéro graphique.** « Taux de conformité **3%** » = chiffre rouge brut (devrait être une **jauge d'alerte**). « En transit 1 », « Manquants 64 », « Chauffeurs actifs 1 » = cartes-nombres uniformes.
- **Aucune hiérarchie d'élévation** : cartes blanches identiques, ombres quasi nulles → aspect « liste de cartes » et non « cockpit ».
- **Aucun widget Workforce/Planning** (pas de pointage live visuel, pas de couverture, pas de prévu/réel) alors que les read-models existent.
- Bandeau « À traiter » correct (4 KPI : congés 1, feuilles 2, signatures 7, intégrations 13) mais statique.
- Mobile : empilement propre (responsive OK) mais même platitude.

**[DÉCISION-protocole]** Chaque phase UI re-joue `ui-audit-before.spec.js` renommé `*-after` → comparaison AVANT/APRÈS obligatoire (desktop + mobile + dark), + `@axe-core/playwright` (a11y). **Correction A traitée : l'audit n'est plus code-only.**

> Limite honnête : captures produites en **local (`leezr.test`)**, pas `dev.leezr.com`, pour éviter toute interaction avec staging. Même seeders dev, rendu identique. Re-jouables sur DEV via `E2E_BASE_URL=https://dev.leezr.com`.

---

## 1. CORRECTION B — Capabilities : analyse & verdict de sécurité

**[FAIT] État réel du code :**
- `PlatformUser::hasCapability(string $key): bool` = **stub `return true`** (commentaire : *"capabilities not implemented yet, always allow"*), `app/Platform/Models/PlatformUser.php:76`.
- Appelé à **un seul endroit** : `DashboardWidgetRegistry::catalogForUser(PlatformUser)` (`:61-62`), boucle `foreach ($w->capabilities() as $cap) if (!$user->hasCapability($cap)) return false;`.
- `catalogForCompany(Company)` **n'utilise pas** `capabilities()` du tout (seulement audience + scope + `ModuleGate::isActive`). Le filtrage permission company réel est dans `DashboardCatalogService::forArchetype`.
- Plusieurs widgets déclarent `capabilities()` (Billing*, Compliance*, Shipments*, Drivers*…).

**Verdict :** `capabilities()` **ne gate rien aujourd'hui** — côté platform `hasCapability` renvoie toujours `true` (laisse tout passer), côté company il n'est jamais consulté. La **vraie** autorisation = `permissions()` (via `hasPermission`) + `ModuleGate`/`EntitlementResolver` (+ archetype company). Ce n'est donc pas une capability « fictive qui accorde un accès » (always-true ne *restreint* jamais, donc n'ouvre rien d'interdit non plus) — **le risque est l'inverse** : un futur développeur pourrait croire que `capabilities()` *restreint* un widget sensible, alors qu'il est transparent.

**[DÉCISION — avant Phase 1, impérative]** Au choix :
- **(a) Implémenter** `hasCapability` : mapper chaque clé de capability → une/des `PlatformPermission` requises, avec tests prouvant le **refus** (401/absence catalogue). Alors `capabilities()` devient un vrai sur-filtre.
- **(b) Déprécier** `capabilities()` : replier tout gating dans `permissions()` et supprimer l'appel stub (évite le faux sentiment de sécurité).
**Règle intangible retenue dans les deux cas** : **tout widget sensible DÉCLARE `permissions()`** ; aucune autorisation ne repose sur `capabilities()` tant que (a) n'est pas livré + testé. Recommandation : **(b)** à court terme (simplicité, pas de faux gate), (a) seulement si un besoin de granularité au-delà des permissions émerge.

---

## 2. CORRECTION C — Matrice opérationnelle des widgets (retenus, à valeur métier)

Périmètre volontairement **resserré** (pas de widgets décoratifs). Colonnes : **ID · Module · Scope · Permission réelle · JobDomain · Endpoint/read-model · Viz · Données (A/B/C/D) · Drill-down · Responsive · Prio**.
États données : **A** = endpoint existant · **B** = calculable modèle existant · **C** = wrapper widget à créer sur read-model existant · **D** = donnée inexistante.

### Company — Workforce / Planning / Pointage / Congés
| ID | Module | Perm | Endpoint/read-model | Viz | Données | Drill-down | Resp | Prio |
|---|---|---|---|---|---|---|---|---|
| `wf.headcount` | workforce | `workforce.view` | `/workforce/dashboard/statistics` | KPI+spark | **C** (A data) | /employees | S→full | P1 |
| `time.clock_live` | workforce | `workforce.timesheet_view` | `/time-entries/cockpit/summary` | donut (working/break/absent) | **C** (A) | /workforce/time | M | P1 |
| `time.completion` | workforce | `workforce.timesheet_view` | `/workforce/timesheets/statistics` | jauge radiale | **C** (A) | /workforce/time | S | P1 |
| `time.anomalies` | workforce | `workforce.timesheet_view` | `/time-entries/anomalies` | KPI+liste | **C** (A) | /workforce/time | M | P1 |
| `time.pending_approval` | workforce | `workforce.timesheet_approve` | `/workforce/dashboard/priorities` | KPI+action | **C** (A) | /workforce/time | S | P1 |
| `plan.uncovered` | workforce | `workforce.planning_view` | `/planning/coverage/summary` | KPI+liste | **C** (A) | /workforce/planning | M | P1 |
| `plan.coverage` | workforce | `workforce.planning_view` | `CoverageReadModel` | barres (besoin/assigné) | **C** (A) | /workforce/planning | M→L | P1 |
| `plan.planned_vs_actual` | workforce | `workforce.planning_view` | `/shifts/comparison` | aire/ligne | **C** (A) | /workforce/planning | L | P1 |
| `leave.pending` | workforce_leave | `workforce.leave_request` | `/workforce/leaves/statistics` | KPI+action | **C** (A) | /workforce/leave | S | P1 |
| `leave.balance` | workforce_leave | `workforce.leave_request` | `LeaveBalanceReadModel` | liste/jauge | **C** (A) | /workforce/leave | M | P2 |
| `wf.contracts_expiring` | workforce | `workforce.view` | `EmploymentContract` | KPI+liste | **C** (B) | /employees | M | P2 |
| `payroll.runs` | workforce_payroll | `workforce.payroll_prepare` | `/workforce/payroll/statistics` | funnel/KPI | **C** (A) | /workforce/payroll | M | P2 |
| `payroll.mass` | workforce_payroll | `compensation_read` | `PayrollLine` (gated) | KPI € | **C** (A, sensible) | /workforce/payroll | M | P3 |
| `dsn.status` | workforce_payroll | `workforce.payroll_export` | `/workforce/dsn/statistics` | funnel | **C** (A) | /workforce/dsn | M | P2 |

### Company — Documents / Signature / Billing (déjà widgetisés = A ; +graphes)
| `doc.compliance_rate` | core.documents | `documents.view` | `/company/documents/compliance` | **jauge radiale** | **A** (existe, à re-visualiser) | /company/documents | M | P1 |
| `doc.expiring` | core.documents | `documents.view` | idem | KPI+liste | A | /company/documents | M | P1 |
| `sign.status` | workforce_signature | `workforce_signature.view` | `/workforce/signature/supervision` | KPI+liste | **C** (A) | /workforce/signature | M | P2 |
| `bill.trial_outstanding` | billing | `billing.manage` | `/billing/overview` | KPI | A (existe) | /billing | S | — |
| `bill.spend_trend` | billing | `billing.manage` | ledger/overview | ligne | **C** (A) | /billing | L | P2 |

### Company — Exploitation (jobdomain `logistique`, module shipments)
| `ship.status` | shipments | `shipments.view` | shipments | KPI+barres | **C** (B) | /company/shipments | M | P2 |
| `delivery.my_today` | shipments | self | my-deliveries + mapbox | KPI+carte | **C** | /company/my-deliveries | M→L | P2 |

### Platform (tous A sauf noté) — compositions par permissions (§4)
| `plat.mrr` | billing | `view_billing` | `/billing/metrics` | KPI+history ligne | A | /platform/billing | M | P1 |
| `plat.churn` | billing | `view_billing` | idem | KPI+spark | A | /platform/billing | S | P1 |
| `plat.trial_conv` | billing | `view_billing` | idem | jauge | A | /platform/billing | S | P1 |
| `plat.ar_aging` | billing | `view_billing` | `/billing/aging` | barres | A | /platform/billing | M | P1 |
| `plat.dunning` | billing | `view_billing` | `/billing/dunning` | KPI+liste | A | /platform/billing | M | P1 |
| `plat.signup_funnel` | platform | `view_usage_monitoring` | `/onboarding/funnel` | funnel | A | /platform/dashboard | M | P1 |
| `plat.system_health` | infrastructure | `view_system_health` | `/system/health` | grille statut | A | /platform/operations | M | P1 |
| `plat.security_alerts` | security | `security.alerts.view` | `/security/alerts` | KPI+liste | A | /platform/operations | M | P1 |
| `plat.usage` | usage | `view_usage_monitoring` | `/usage/overview` | aire | A | /platform/operations | L | P2 |
| `plat.support` | support | `view_support` | `/support/metrics` | KPI | A | /platform/support | M | P2 |
| `plat.module_adoption` | modules | `manage_modules` | `CompanyModule` x-tenant | barres | **C** (B) | /platform/modules | M | P3 |

**Exclus faute de données (D)** : `plat.api_requests` (`usage_snapshots.api_requests` codé à 0, pas d'instrumentation) ; tout rollup usage-metered historique. **Total retenu ≈ 34 widgets** à valeur métier prouvée.

---

## 3. MASTER PLAN V2 (ordre validé)

| Phase | Objectif | Livrable visuel clé | Complexité |
|---|---|---|---|
| **P0 — Design System Premium** | Couche visuelle réutilisable par-dessus Vuexy (jamais `@core`/`@layouts`) | `Lzr*` components + hiérarchie d'élévation + tokens | M |
| **P1 — Dashboard Company + widgets Workforce/Planning** | Cockpit référence ; smart-default rempli par jobdomain/modules/permissions | **Le dashboard cible (§5)** | M/L |
| **P2 — Dashboard Platform** | Compositions auto par permissions réelles (pas de rôle codé) | KPI+graphes billing/ops/sécurité | M |
| **P3 — Dashboard End-User/Employé** | Expérience dédiée (shift/pointage/congés/docs/notifs), mobile-first | `/home` employé | M |
| **P4 — Personnalisation avancée** | Presets Company/JobDomain/Module/User, héritage, versioning | layouts hérités, dégradation sûre | M |
| **P5 — Modernisation des autres pages** | Pages plates → KPI contextuels/filtres/viz (sans décor inutile) | 12 pages prioritaires (§D audit) | M/L |

**Gate qualité OBLIGATOIRE par phase (critère de livraison, §5-critères)** : captures avant/après (desktop+mobile+dark), comparaison richesse, données réelles vérifiées, tests permissions+isolation, responsive, états loading/empty/error, perso widgets. **« Build + PHPUnit verts » ne suffit plus.**

---

## 4. Dashboard Platform — compositions par permissions (pas de rôle codé) [RECO]

Un seul écran, widgets filtrés serveur par `PlatformPermission`. Confronté au RBAC réel (`PermissionCatalogBuilder` admin) :
- `view_billing` → `plat.mrr/churn/trial_conv/ar_aging/dunning`.
- `view_usage_monitoring` → `plat.signup_funnel/usage`.
- `view_system_health` + `realtime.metrics.view` → `plat.system_health`.
- `security.alerts.view` + `view_audit_logs` → `plat.security_alerts` + feed audit.
- `view_support` → `plat.support`.
- `manage_modules` → `plat.module_adoption`.
Aucun KPI inventé ; `plat.api_requests` exclu (D).

---

## 5. VISION DÉTAILLÉE — Dashboard Company cible (le premier objectif)

**Principe** : transformer les cartes-nombres actuelles (capture §0) en **cockpit premium** — KPI strip → graphes → listes opérationnelles → activité — gouverné par jobdomain + modules + permissions + archétype, **données réelles uniquement**, **utile même jour-0**.

**Composition cible (archétype management, jobdomain logistique, grille 12-col existante) :**
```
┌ LzrPageHeader — "Bonjour {prénom}" · sélecteur période [7j|30j|mois] · [Personnaliser] [+ Widget] ┐
├ LzrStatStrip (4–6 KPI, élévation douce, sparkline) ──────────────────────────────────────────────┤
│ Effectifs 50 ▂▃▅ │ Présents 0/50 + Non pointés 12 │ Shifts non couverts ⚠ │ Congés à valider 1 │ Trésorerie trial J-x │
├ Rangée graphes (12-col) ─────────────────────────────────────────────────────────────────────────┤
│ [L=8] plan.planned_vs_actual (aire, semaine)              │ [4] doc.compliance_rate (JAUGE 3% rouge) │
├ Rangée opérationnelle ───────────────────────────────────────────────────────────────────────────┤
│ [4] time.clock_live (donut présents/pause/absents) │ [4] plan.uncovered (liste) │ [4] ship.status (barres) │
├ Rangée "À traiter" (conservée, enrichie) + activité ─────────────────────────────────────────────┤
│ [6] Actions urgentes (congés/feuilles/signatures/anomalies) │ [6] Activité récente (LzrTimeline, SSE) │
└ (tout widget = drill-down route métier ; perso drag/resize/save existante préservée)               ┘
```
- **Jauge conformité** : le « 3% » rouge devient une `LzrGauge` radiale rouge → lecture d'alerte immédiate (exemple-type de la transformation).
- **Jour-0 (Company neuve)** : `OnboardingSetupWidget` + `PlanBadgeWidget` + compte à rebours trial ; les KPI/graphes apparaissent à l'activation des modules & à l'arrivée des données (empty-states `LzrEmptyState`). **Aucune donnée fictive, aucune surcharge.**
- **Archétypes** (seeds `JobdomainRegistry.default_roles`) : `operations_center` → priorité couverture/planning ; `field_worker` → renvoyé vers `/home` (P3). Smart-default par archétype dans `modules/company/dashboard/dashboard.store.js`.

**Références Vuexy ciblées** (presets, copie à la demande) : `cards/card-statistics/*` (KPI+spark), `charts/apex-chart/*` (aire/barres/radial), `cards/card-advance/CardAdvanceActivityTimeline.vue`. Rendu = **densité + hiérarchie + couleur contextuelle**, pas de carte décorative.

---

## 6. SPEC EXÉCUTABLE — PHASE 0 (Design System Premium)

### 6.1 Objectif
Livrer la **bibliothèque de composants visuels réutilisables** + la **hiérarchie d'élévation/densité** qui rendront toutes les phases suivantes « premium », **sans toucher `@core`/`@layouts`** (loi UI `docs/bmad/06-ui-policy.md`).

### 6.2 Composants à CRÉER (couche Leezr `resources/js/core/components/`)
| Composant | Base réutilisée | Rôle |
|---|---|---|
| `LzrKpiCard.vue` | `@core/components/cards/CardStatisticsVertical.vue` + presets `card-statistics/*` | nombre + delta ▲▼ + sparkline Apex + couleur sémantique + icône Tabler |
| `LzrApexChart.vue` | pattern `pages/platform/billing/_RevenueTrendWidget.vue` (`useTheme`+`hexToRgb`, `viewport.presentationMode` S/M/L), `@core/libs/apex-chart/apexCharConfig.js` | line/area/bar/donut/radial theme-aware, mode spark en S |
| `LzrGauge.vue` | ApexCharts radialBar | taux/objectifs (ex. conformité) avec seuils sémantiques |
| `LzrStatStrip.vue` | grille `card-grid-*` (ADR-379) | conteneur bandeau KPI responsive |
| `LzrPageHeader.vue` | `core/components/SectionHeader.vue` + `PageBreadcrumbs.vue` + `SmartActionBar.vue` | en-tête unifié (titre, période, actions) |
| `LzrEmptyState.vue` | `core/components/EmptyState.vue` (existe) | imposer un empty-state cohérent partout |
| `LzrTimelineCard.vue` | Vuetify `VTimeline` + presets `molecules/timeline/*` | activité/historique |
| `LzrWidgetFrame.vue` | `components/dashboard/*` | cadre widget commun (titre, loading skeleton, error, drill-down, refresh) consommé par `widgetComponentMap.js` |

### 6.3 Tokens & hiérarchie (fichiers hors `@core`)
- `resources/styles/styles.scss` : ajouter 3 classes utilitaires d'**élévation** (`.lzr-card--flat` bordée conteneur / `.lzr-card--raised` KPI / `.lzr-card--active` focus) + densité (gap, padding) — **par-dessus** Vuexy, pas de fork.
- Réutiliser palette/semantic/dark déjà complets (`plugins/vuetify/theme.js`), typo runtime (`--lzr-font-family`), icônes Tabler.
- Micro-interactions sobres (hover/entrée widget) respectant `prefers-reduced-motion`.

### 6.4 Contrats à ÉTENDRE (préparation P1, non bloquant P0)
- `app/Modules/Dashboard/Contracts/WidgetManifest.php` : ajouter `vizType` (kpi|spark|line|area|bar|donut|radial|gauge|timeline|list|map), `refreshInterval`, `cacheTtl`, `realtimeTopics[]`, `version`. **Défauts rétro-compatibles** (aucun widget existant cassé). *Migration de données : aucune.*
- `resources/js/components/dashboard/widgetComponentMap.js` : map `vizType` → `Lzr*` pour un rendu générique (réutilise `useSurfaceEngine`/`useDashboardGrid` **inchangés**).

### 6.5 Fichiers concernés (récap)
`resources/js/core/components/Lzr*.vue` (nouveaux) · `resources/styles/styles.scss` · `docs/ui/` (documenter les primitives) · `app/Modules/Dashboard/Contracts/WidgetManifest.php` (champs optionnels) · `resources/js/components/dashboard/widgetComponentMap.js`. **Aucun fichier `@core`/`@layouts` modifié. Aucune migration/seed.**

### 6.6 Tests & critères de validation VISUELLE (gate obligatoire)
- **Playwright** : étendre `tests/e2e/ui-audit-*.spec.js` → captures AVANT/APRÈS d'une page-démo exposant `LzrKpiCard`/`LzrApexChart`/`LzrGauge` en **desktop + mobile + dark**.
- **@axe-core/playwright** : 0 violation *serious/critical* sur la page-démo.
- Vérifications : 3 niveaux d'élévation visibles ; sparkline/jauge rendues theme-aware (light+dark) ; mobile empile sans débordement horizontal ; états loading (skeleton)/empty (`LzrEmptyState`)/error.
- **Pas de runner JS unit** (vitest/jest absents) → la validation composant passe par Playwright + revue visuelle (pas d'invention de harnais).
- PHPUnit : inchangé (P0 est front-only ; l'extension de contrat garde des défauts → `DashboardWidgetRegistry`/catalogue tests restent verts).
- **Critère de livraison** : captures avant/après fournies + comparaison explicite à une référence Vuexy `card-statistics` + a11y OK. « Build vert » seul = insuffisant.

### 6.7 Décisions bloquantes à arbitrer AVANT P0/P1
1. **[DÉCISION] `/home` vs `/dashboard`** : `/home` = employé (field_worker, P3), `/dashboard` = management/ops (P1) — **remplir les deux**, pas fusionner. → à confirmer.
2. **[DÉCISION] capabilities()** (§1) : (b) déprécier / replier dans permissions (reco court terme) vs (a) implémenter `hasCapability` sur RBAC. **À trancher avant P1** (sécurité).
3. **[DÉCISION] valeurs d'élévation/densité** (3 niveaux) : nécessite validation visuelle de ta part sur la page-démo P0.
4. **[DÉCISION] dette libs** : supprimer `chart.js`/`vue-chartjs` + `swiper` dormants ; déplacer `@fullcalendar/*` en `dependencies`. (Hygiène, non bloquant mais à acter.)
5. **[DÉCISION] périmètre `vizType`** : enum figé ci-dessus — à valider.
6. **Pas de nouvelle dépendance** en P0 (ApexCharts suffit) ; export xlsx/PDF et heatmap **différés** (P5/P3).

### 6.8 Critères d'acceptation Phase 0
- ✅ 8 composants `Lzr*` livrés, documentés dans `docs/ui/`, utilisés sur une page-démo.
- ✅ Hiérarchie d'élévation visible (captures avant/après).
- ✅ Theme-aware (light+dark), responsive (desktop/tablette/mobile), a11y axe 0 serious.
- ✅ `WidgetManifest` étendu rétro-compatible, catalogue/tests PHPUnit verts.
- ✅ `@core`/`@layouts` intacts ; aucune donnée fictive ; aucune migration.

---

## 7. Règles d'architecture (rappel, respectées par conception)
1 Company = 1 JobDomain · Platform/Company strictement séparés · tous les Platform ≠ superadmins · widgets déclarés par leurs modules · visibilité = permissions+modules+entitlements+contexte (serveur = autorité) · perso n'accorde aucun droit · aucune donnée inter-tenant · aucun KPI fictif · aucun Vuexy décoratif. **Réutilisation imposée** : `DashboardWidgetRegistry`, `WidgetManifest`, `DashboardCatalogService`, `useSurfaceEngine`, `useDashboardGrid` — **inchangés**.

---

### Checkpoint V2
- **Master Plan réordonné** (P0 design-system → P1 Company → P2 Platform → P3 Employé → P4 perso → P5 pages) + critère de livraison visuelle imposé.
- **Correction A** : captures AVANT réelles produites (`tests/e2e/__screens__/ui-audit-before/`, harnais Playwright), protocole avant/après acté.
- **Correction B** : capabilities = stub inerte, non-sécurité ; décision (déprécier vs implémenter) à trancher avant P1 ; règle « tout widget sensible déclare `permissions()` ».
- **Correction C** : matrice opérationnelle ~34 widgets (ID/module/scope/perm/jobdomain/endpoint/viz/données A-D/drill-down/responsive/prio).
- **Phase 0 spécifiée** (composants, fichiers, contrats, tests, validation visuelle, décisions bloquantes) + **vision détaillée du dashboard Company cible**.
**Aucune implémentation, migration ni déploiement.** Prêt à exécuter P0 sur ton GO (après arbitrage des 6 décisions §6.7).
