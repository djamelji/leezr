# LEEZR — PHASE 1 : PRÉPARATION (Dashboard Company premium)

> Suite de ADR-741/742/743. **PRÉPARATION uniquement** — l'intégration visuelle complète de P1 attend ton retour sur les captures `/ui-lab`. Aucun code P1 écrit ici.

## Objectif P1
Transformer `pages/dashboard.vue` (cockpit Company) en réutilisant **le moteur de widgets existant** (`useSurfaceEngine`, `DashboardGrid`, `DashboardWidgetRegistry`, `WidgetManifest`) + la **couche Lzr** livrée en P0. Zéro reconstruction, drag/resize/perso **préservés**.

## Ce qui est réutilisé (inchangé)
- Backend : `app/Modules/Dashboard/*` (registry, `DashboardCatalogService`, layouts), `ModuleGate`/`EntitlementResolver`, read-models classe-A (cf. `UI-UX-MASTER-AUDIT.md` §E).
- Frontend : `core/surface-engine/useSurfaceEngine.js`, `composables/useDashboardGrid.js`, `components/dashboard/widgetComponentMap.js`, stores `companyDashboard`.
- P0 : `Lzr{KpiCard,ApexChart,Gauge,StatStrip,PageHeader,EmptyState,TimelineCard,WidgetFrame}`.

## Travaux P1 (ordre)
1. **Enrichir les widgets company existants** (déjà au catalogue, données réelles A) en les re-visualisant avec Lzr* :
   - `ComplianceRate` → `LzrGauge` (le « 3% » chiffre → jauge d'alerte). Fichier : `resources/js/views/company-dashboard/_ComplianceRateWidget.vue`.
   - KPIs shipments/drivers → `LzrKpiCard` (+ spark si série dispo).
2. **Ajouter les widgets Workforce/Planning/Pointage/Congés** (read-models A existants, wrappers C à créer — cf. matrice `UI-UX-MASTER-PLAN-V2.md` §2) :
   - Backend : `app/Modules/Workforce/**/Dashboard/widgets.php` (nouveaux `WidgetManifest`), `module()` = module workforce concerné, `permissions()` = permission réelle (ex. `workforce.timesheet_view`), `resolve()` branché sur le read-model (`TimeCockpitReadModel`, `CoverageReadModel`, `PlanningComparisonReadModel`, `LeaveRequestReadModel`, `TimesheetPeriodReadModel`). Montants paie → `compensation_read` obligatoire.
   - Frontend : composants widgets dans `resources/js/views/company-dashboard/` consommant Lzr*, enregistrés dans `widgetComponentMap.js`.
   - `vizType` : étendre `WidgetManifest` **via trait optionnel** `HasWidgetVisualization` (`vizType()` défaut 'kpi') + lecture `method_exists` dans le catalogue → **rétrocompatible** (aucune modif de l'interface, 30 widgets intacts) ; `widgetComponentMap` mappe `vizType`→Lzr avec **fallback sûr** (type inconnu → `LzrWidgetFrame` brut). Un module peut toujours fournir son composant (`registerWidgetComponent`).
3. **Smart-default par archétype/jobdomain** : étendre `modules/company/dashboard/dashboard.store.js` + `JobdomainRegistry.default_roles.*.dashboard_widgets` pour composer des rangées KPI→graphes→opérationnel→activité selon `management | operations_center | field_worker`.
4. **LzrPageHeader + période** sur le dashboard ; drill-down de chaque widget vers sa route métier.

## Gouvernance (garanties, déjà en place)
Visibilité = `DashboardCatalogService::forArchetype` (audience→scope→`ModuleGate`→permissions→archetype) ; catalogue + `batchResolve` re-filtrés serveur (widget interdit **absent du catalogue ET refusé côté API**) ; module désactivé → widget retiré (`filterLayout`) ; isolation `CompanyScope` dans `resolve()` ; perso ne contourne jamais l'autorisation. Montants confidentiels gated `compensation_read`.

## GATES de validation P1 (obligatoires, « livré » = visuel prouvé)
- **Captures AVANT/APRÈS** desktop/tablette/mobile + **light/dark**.
- **Démonstration sur 2 Companies** : (a) Company **réellement peuplée** (ex. Leezr Logistics) → cockpit riche ; (b) Company **sans données** (jour-0) → onboarding + trial + empty-states, **aucune donnée fictive, aucune surcharge**.
- **Données réelles uniquement** (classe A/B, jamais fictives).
- **Permissions & isolation multi-tenant** : tests qu'un profil (management vs exploitation vs field_worker) voit son set ; widget interdit absent du catalogue + **API 403**.
- **Drag & drop / perso préservés** (régression layout).
- **Loading / empty / error** sur chaque widget.
- **a11y** (axe 0 serious sur le contenu) + **perf** (résolution batch < 800ms P95, lazy hors-écran).
- **Build + PHPUnit `Dashboard|Widget|Workforce` verts** (non-régression).

## Décisions restantes à arbitrer avant d'écrire P1
1. **Primary de marque dark app-wide** : généraliser `#5D50E6` + variante dark dérivée au niveau synchro cookies (ADR-332) ? (validation visuelle requise — cf. ADR-743).
2. Priorité des archétypes réels par Company (confronter aux rôles réellement configurés, non supposés).
3. Périmètre exact des widgets P1 (la matrice §2 liste ~34 ; démarrer par les P1 : headcount, clock_live, completion, anomalies, pending_approval, uncovered, coverage, planned_vs_actual, leave.pending, compliance_rate re-viz).
