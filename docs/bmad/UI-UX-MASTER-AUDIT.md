# LEEZR — AUDIT GLOBAL UI/UX & DASHBOARD EXPERIENCE + MASTER PLAN

> **Statut : AUDIT (lecture seule). Aucune implémentation, migration, seeder ni déploiement.**
> Date : 2026-11-08 · Méthode : inspection du code réel (6 explorations parallèles du dépôt) · Navigateur non connecté cette session → pas de captures réelles, constats fondés sur le code et les presets.
> Convention : **[FAIT]** = vérifié dans le code (chemin cité) · **[RECO]** = recommandation · **[HYP]** = hypothèse à valider.

---

## A. EXECUTIVE SUMMARY — pourquoi Leezr paraît « fade » aujourd'hui

**Contre-intuition centrale (le constat le plus important de cet audit) : Leezr ne manque pas d'architecture. Il manque d'exploitation.**

Deux explorations indépendantes (gouvernance backend, plomberie frontend) ont trouvé qu'un **moteur de widgets/dashboards serveur-piloté, multi-tenant, gouverné par permissions/modules/entitlements/jobdomain, avec grille drag&resize et persistance de layout, EXISTE DÉJÀ** et tourne en production. Le « moteur de widgets », les « dashboards personnalisables » et le « modèle de permissions » demandés dans le brief sont, pour l'essentiel, **déjà présents** (`app/Modules/Dashboard/` + `resources/js/core/surface-engine/`).

Les causes réelles de l'impression « plate » sont au nombre de six, toutes **[FAIT]** :

1. **Dashboards vides par défaut.** `pages/dashboard.vue` (home company) rend un header de bienvenue + checklist onboarding + 4 boutons + cartes « à traiter » ; la grille de widgets est **vide par défaut**. `pages/home.vue` (end-user) est une coquille de moteur **vide**. Le dashboard platform overview n'affiche que 5 cartes de comptage **sans courbe**.
2. **Quasi-absence de graphiques.** Il existe **4 composants de graphique dans toute l'application**, **tous côté platform-billing/operations** (`pages/platform/billing/_RevenueTrendWidget.vue`, `_CashflowTrendWidget.vue`, `_BillingDashboard.vue`, `pages/platform/operations/_OperationsUsageConsumption.vue`). **Zéro graphique côté company.** ApexCharts est installé et le pattern de widget-graphique est établi — mais inexploité.
3. **Read-models riches non exposés en widgets.** Le moteur de widgets company ne couvre que Billing, Compliance/Documents, AI, Shipments, Onboarding, Plan. **Aucun widget Workforce / Planning / Pointage / Congés**, alors que leurs read-models renvoient déjà des payloads KPI-ready (classe A, cf. §E).
4. **Cartes plates par défaut.** `1856` balises `<VCard>` dans `pages/`, **0 avec `elevation` explicite** ; le défaut compilé (`$card-elevation: 6`) combiné à des ombres **umbra-only très douces** produit un rendu sans hiérarchie d'élévation.
5. **Pages-listes plates.** Audit, my-deliveries, notifications, payroll, DSN, shipments, platform/companies : `VDataTable` seul, sans bandeau KPI ni synthèse visuelle, alors que les données existent.
6. **Dette d'outillage dormante.** `chart.js`/`vue-chartjs` et `swiper` installés mais **zéro usage applicatif** ; `@fullcalendar/*` mal classé en `devDependency` alors qu'il est utilisé en prod.

**Conclusion de cadrage : le chantier est à 80 % de l'« exploitation/enrichissement » et à 20 % de la « fondation ».** Le risque principal n'est pas technique, c'est de **reconstruire un moteur qui existe**. Le brief l'interdit explicitement (« Ne propose pas de reconstruire un mécanisme déjà existant ») — cet audit s'y tient.

---

## B. INVENTAIRE DE L'UI EXISTANTE (classé)

| Brique | Statut | Chemin / preuve | ADR |
|---|---|---|---|
| Moteur de widgets **backend** (WidgetManifest, registry, catalogue filtré, résolution, layout) | **EXISTANT-FONCTIONNEL** | `app/Modules/Dashboard/Contracts/WidgetManifest.php`, `DashboardWidgetRegistry.php`, `DashboardCatalogService.php`, `Widgets/*` (~30) | ADR-357, 371 |
| Moteur de **surface** frontend (catalog/layout/data/persistence) | **EXISTANT-FONCTIONNEL** | `resources/js/core/surface-engine/useSurfaceEngine.js`, `useSurfaceFirstFit.js` | — |
| **Grille** 12-col drag/resize/compaction/responsive | **EXISTANT-FONCTIONNEL** | `resources/js/composables/useDashboardGrid.js` (~700 l.), `components/dashboard/DashboardGrid.vue` | ADR-152 V6 |
| Registry de composants widgets (async) | **EXISTANT-FONCTIONNEL** | `components/dashboard/widgetComponentMap.js`, `widgetProfiles.js` | — |
| Stores dashboard platform + company (smart defaults) | **EXISTANT-FONCTIONNEL** | `modules/platform-admin/dashboard/dashboard.store.js`, `modules/company/dashboard/dashboard.store.js` | ADR-327, 441 |
| Persistance layout (company/platform/jobdomain default) | **EXISTANT-FONCTIONNEL** | `CompanyDashboardLayout.php`, `PlatformUserDashboardLayout.php`, `JobdomainDashboardDefault.php`, `LayoutPacker/Validator.php` | — |
| Navigation manifest-driven (plateforme + company) | **EXISTANT-FONCTIONNEL** | `app/Core/Navigation/NavBuilder.php` (11 étapes), `NavController.php` | ADR-601/690/635 |
| ModuleManifest + autodiscovery | **EXISTANT-FONCTIONNEL** | `app/Core/Modules/ModuleManifest.php`, `ModuleRegistry.php`, `Capabilities.php` | ADR-132/732 |
| RBAC platform + company (catalogue enrichi) | **EXISTANT-FONCTIONNEL** | `app/Core/RBAC/PermissionCatalogBuilder.php` | ADR-132/433 |
| Entitlements (5 portes) | **EXISTANT-FONCTIONNEL** | `app/Core/Modules/EntitlementResolver.php`, `ModuleGate.php` | ADR-204/211/212/734/737 |
| JobDomains (assemble, 1/Company) | **EXISTANT-FONCTIONNEL** (1 seul domaine `logistique` peuplé) | `app/Core/Jobdomains/JobdomainRegistry.php`, `Company::getJobdomainAttribute()` | ADR-167a/728 |
| Multi-tenant (scope global + contexte) | **EXISTANT-FONCTIONNEL** | `app/Core/Traits/BelongsToCompany.php`, `Scopes/CompanyScope.php`, `SetCompanyContext.php` | ADR-432/688/733/737 |
| Realtime SSE + primitive d'abonnement | **EXISTANT-FONCTIONNEL** | `core/realtime/RealtimeClient.js`, `useRealtimeSubscription.js`, `DomainEventBus.js` | ADR-126/427 |
| Design-system tokens (palette/typo/dark/responsive) | **EXISTANT-FONCTIONNEL** | `plugins/vuetify/{theme,defaults}.js`, `styles/@core/.../_variables.scss`, `core/components/*` (14 primitives E3-R) | ADR-159/332/379/693-696 |
| Widgets **graphiques** côté company | **ABSENT** | 0 fichier | — |
| Widgets Workforce/Planning/Timesheet/Leave | **ABSENT** (données présentes) | `app/Modules/Dashboard/widgets.php` n'en déclare aucun | — |
| `WidgetManifest::capabilities()` / `hasCapability()` | **DOCUMENTÉ-NON-IMPLÉMENTÉ** | `PlatformUser::hasCapability()` = stub `return true` | ADR-357 |
| Export Excel/PDF, heatmap, websocket bidirectionnel | **ABSENT** (dépendance à ajouter) | — | — |
| `chart.js`/`vue-chartjs`, `swiper` | **OBSOLÈTE/dormant** (installés, 0 usage app) | `package.json` | — |

---

## C. CAPACITÉS VUEXY INSTALLÉES (synthèse ; détail en annexe mentale)

Stack : **Vuetify 3.10.8** (patché), Vue 3.5, **Vuexy 9.5.0**. Librairies clés **présentes et câblées** : `apexcharts`+`vue3-apexcharts` (graphiques, **dep**, utilisé platform), `@fullcalendar/*` (calendrier, **utilisé** planning/congés — ⚠ mal classé `devDependency`), `@formkit/drag-and-drop` (**dep, utilisé** planning + catalog), `shepherd.js` (tour onboarding, utilisé), `@tiptap/*` (éditeur, utilisé), `vue-flatpickr`, `mapbox-gl`, `perfect-scrollbar`.

**Présents mais seulement en presets (non câblés)** : Kanban (`resources/ui/presets/apps/kanban/*` — drag lib déjà live ⇒ adoption la moins coûteuse), Chat, Email, CRM, Invoice. **Presets riches disponibles** : `cards/card-statistics/*` (16 KPI/spark), `charts/apex-chart/*` (11), `molecules/timeline|badge|chip|alert|tooltip|progress/*`, `forms/demos/tables/data-table/*` (10 patterns), `wizards/*`.

**Absents (dépendance/chantier à créer)** : realtime websocket bidirectionnel (SSE-only existe), export xlsx/PDF (CSV hand-rollé uniquement), heatmap (ApexCharts le supporte, aucun preset). **Dormants** : `chart.js`, `swiper`.

> Règle d'or respectée (`docs/bmad/06-ui-policy.md`) : les presets sont un **stock copié à la demande**, `@core`/`@layouts` ne se modifient jamais ; toute identité Leezr se construit **par-dessus** (couche `resources/js/core/components/`, 14 primitives déjà là).

Pour chaque capacité du brief : la matrice complète « présent ? / utilisé ? / où / dépendance / valeur » a été produite et est résumée ci-dessus ; les 3 seules capacités « à développer/dépendre » sont **realtime bidirectionnel, export riche, heatmap**.

---

## D. AUDIT PAGE PAR PAGE — notation & priorisation

Barème /5 par axe : **UI · UX · Richesse fonctionnelle · Exploitation données · Exploitation Vuexy · Responsive**. (Routes auto-générées depuis `resources/js/pages/`.)

### Les 12 pages prioritaires (les plus de levier)

| # | Page (chemin) | Scope | UI | UX | Rich | Data | Vuexy | Resp | Problème précis & reco |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `pages/dashboard.vue` | company | 2 | 3 | 2 | **1** | 2 | 4 | Home company la plus fréquentée : header + checklist + 4 boutons, **grille vide**, 0 KPI/0 graphe alors que workforce+planning+billing+leave existent. **Reco : smart-default jobdomain + bandeau KPI (effectifs, pointage live, shifts non couverts, congés à valider, trésorerie trial/impayés) + 2 graphes (prévu/réel, tendance).** |
| 2 | `pages/home.vue` | end-user | 2 | 2 | 1 | 1 | 2 | 4 | Coquille moteur **vide**. Doublon concurrent de `/dashboard`. **Reco : default employé (prochain shift, pointer, solde congés, demandes, docs) OU fusion avec `/dashboard` selon scope.** |
| 3 | `platform/dashboard/_DashboardOverview.vue` | platform | 3 | 3 | 3 | 3 | 2 | 4 | Cockpit + 5 cartes plates + chips santé, **aucune courbe** alors que les composants de tendance existent déjà côté billing. **Reco : sparklines MRR/signups/échecs + mini-funnel.** |
| 4 | `company/billing/[tab].vue` (overview) | company | 3 | 3 | 3 | 3 | 2 | 4 | Écran argent : cartes + 1 table, **aucune tendance dépense/usage**. **Reco : courbe dépenses, jauge quota, timeline paiements.** |
| 5 | `company/audit/index.vue` | company | 2 | 2 | 2 | 3 | 2 | 3 | `VDataTable` seul (198 l.), 0 bandeau/0 filtre à facettes/0 timeline. **Reco : KPI (événements 24h, par sévérité, par acteur) + filtres facettés + timeline.** |
| 6 | `company/my-deliveries/index.vue` | end-user | 2 | 2 | 2 | 2 | 2 | 3 | Table filtrée par statut, **0 bandeau today/in-transit/delivered, pas de carte**. **Reco : KPI strip + mini-map mapbox (déjà installé) + prochaines livraisons.** |
| 7 | `company/notifications/index.vue` | end-user | 3 | 3 | 2 | 3 | 2 | 4 | Liste + onglets, **0 résumé non-lus/sévérité** alors que la donnée est chargée. **Reco : bandeau non-lus par catégorie/sévérité.** |
| 8 | `company/workforce/payroll/index.vue` | company | 3 | 3 | 3 | 3 | 2 | 4 | 631 l. table + panneau, **0 total mois/mois ni headcount** — là où un résumé coût compte le plus. **Reco : KPI masse (gated `compensation_read`) + statut runs + funnel DSN.** |
| 9 | `company/documents/[tab].vue` (compliance) | company | 3 | 3 | 3 | 4 | 3 | 4 | Donnée compliance récupérée (`/company/documents/compliance`) mais rendue en cartes/table plates. **Reco : jauge de conformité (radial) + à-expirer + file de demandes.** |
| 10 | `platform/companies/index.vue` | platform | 3 | 3 | 3 | 3 | 2 | 4 | Liste tenant nue, **0 KPI portefeuille** (actifs/trial/churn, MRR par tenant) alors que la donnée existe par-tenant. **Reco : bandeau portefeuille + sparkline acquisition.** |
| 11 | `company/workforce/dsn/index.vue` | company | 3 | 3 | 3 | 3 | 2 | 4 | 502 l. table seule, **0 funnel statut/période**. **Reco : funnel de soumission + santé période.** |
| 12 | `company/shipments/index.vue` | company | 3 | 3 | 3 | 3 | 2 | 3 | Liste logistique sans synthèse statut/volume. **Reco : bandeau statut + volume jour, cohérent avec my-deliveries.** |

### Pages déjà riches (à préserver, ne pas « sur-carder »)
`workforce/planning/index.vue` (6884 l., multi-vues calendrier), `workforce/time/index.vue` (cockpit), `workforce/leave/index.vue`, `company/modules/index.vue`, `company/roles.vue`, `platform/billing/*` (le plus riche, seuls vrais graphes), `platform/companies/[id].vue`, `platform/jobdomains/[id].vue`. **[RECO]** : y appliquer le design-system premium (élévation/variants) sans ajouter de widgets décoratifs.

**Fait transversal** : le scope **company n'a aucun composant graphique** ; les cibles les plus rentables sont les deux dashboards + les pages-listes argent/conformité/logistique ci-dessus.

---

## E. CARTOGRAPHIE DES DONNÉES EXPLOITABLES

Classe : **A** = endpoint existant renvoie déjà le payload · **B** = calculable sur modèle existant · **C** = read-model/API à créer sur données existantes · **D** = donnée inexistante. Argent = **centimes** dans les tables récentes (`*_cents`) — piège de mélange avec anciennes colonnes.

**Endpoints d'agrégation déjà KPI-ready (réutilisables, classe A)** — extrait :
`company:/workforce/dashboard/statistics` · `/workforce/dashboard/priorities` (`HomePrioritiesReadModel`) · `/billing/overview` (`CompanyBillingReadService`) · `/workforce/timesheets/statistics` · `/time-entries/cockpit/summary`+`/board` (`TimeCockpitReadModel`) · `/workforce/shifts/statistics`,`/comparison`,`/planning/coverage/summary` · `/workforce/leaves/statistics` · `/workforce/payroll/statistics`,`/dsn/statistics` · `/company/documents/compliance`+`/activity` · `/workforce/signature/supervision`. Platform : `/billing/metrics` (`BillingMetricsCalculationService` = MRR/ARR/churn/trial-conv) · `/billing/aging`,`/dunning`,`/recovery-status` · `/usage/overview` (`UsageAnalyticsService`) · `/onboarding/funnel` · `/system/health` · `/support/metrics` · `/security/alerts` · `/activity`.

**Synthèse par domaine** (indicateur → classe → permission) :

- **Platform** : companies actives/suspendues (B, `view_companies`), signups/temps (B), funnel inscription+conversion+abandon (**A**, `view_usage_monitoring`), users/membres actifs vs suspendus (B), last-login (B), admins/rôles (B).
- **Billing platform** : MRR/ARR/MRR-history/churn/trial-conv/addon-MRR/blocked-renewals (**A**, `view_billing`), AR aging/outstanding (**A**), dunning/failed (**A**), revenue-trend/refund-ratio (**A**), ledger/réconciliation/drift (**A**), scheduled-debits (**A**).
- **Billing company** : plan/intervalle/prix (**A**, `billing.manage`), trial j-restants (**A**), addons & coût (**A**), wallet (**A**), impayés/échus (**A**), flag paiement échoué (**A**), next-invoice/plan-change preview (**A**). *Usage-metered (`BillableEvent`) = C.*
- **Catalog/Modules/Usage** : catalogue modules (**A**), modules actifs/company (**A**), quote addon (**A**), taux d'adoption module (B, cross-tenant), snapshots usage AI/emails/membres/stockage (**A** platform / **C** company). *API-requests/company = **D** (`usage_snapshots.api_requests` codé à 0, pas d'instrumentation middleware).*
- **Workforce** : effectifs/contrats actifs (**A**, `workforce.view`), headcount par dépt/rôle (B), contrats CDD à échéance (B), congés en attente (**A**), congés par type/jours (**A**), soldes congés (**A**, `LeaveBalanceCache`), taux d'absence (B), minutes travaillées/HS + taux complétion (**A**), anomalies timesheet (**A**), timesheets à valider (**A**), runs paie par statut/brut-cents (**A**, +`compensation_read` pour montants), DSN par statut (**A**). *Montants paie = scrubbed par `PayrollConfidentiality` sans `compensation_read`.*
- **Planning** : shifts total/statut/minutes (**A**, `workforce.planning_view`), gaps de couverture (**A**), shifts non couverts (**A/B**), prévu/réel + delta (**A**), conflits/sur-staffing (**A**), disponibilités (**A**), candidats remplacement (**A**), publication draft/publié (B).
- **Pointage** : working/on_break/completed/not_clocked (**A**), anomalies/incomplets jour (**A**), clock-ins live (**A**), réconciliation matched/ambiguous/no_shift (**A**), pauses (A/B).
- **Documents/Signature** : taux conformité valid/missing/expiring/expired global+type+rôle (**A**, `documents.view`), à-expirer/expirés (**A**), file de demandes/relances (**A**), feed activité (**A**), AI docs analysés/détections/confiance (**A**), signatures par statut (**A**), docs générés (**A**).
- **Audit/Notif/Realtime** : audit company/platform (**A**), feed activité platform (**A**), alertes sécurité par statut/sévérité (**A**), centre d'alertes (**A**), non-lus notif (**A**), SSE connexions/metrics (**A**), délivrabilité email 24h (**A**), santé système db/queue/scheduler/disk/ai (**A**).

**Fait capital** : l'écrasante majorité des indicateurs utiles sont **classe A ou B** — **aucune donnée fictive n'est nécessaire**. Le déficit est le **wrapper widget**, pas la donnée. **Vrais gaps D** : API-requests/company, rollups usage-metered historiques.

**Empty-state (Company neuve)** : tous les `statistics()`/read-models renvoient des zéros typés et collections vides ; les widgets sont gated par activation module → une Company jour-0 voit `OnboardingSetupWidget` + `PlanBadgeWidget` + compte à rebours trial, les KPI apparaissant à mesure de l'activation et des données. **Donc les dashboards restent utiles et honnêtes même sans données.**

---

## F. ARCHITECTURE CIBLE DU MOTEUR DE WIDGETS — **étendre l'existant, ne rien reconstruire**

**[FAIT] Le contrat `WidgetManifest` existe déjà** (`app/Modules/Dashboard/Contracts/WidgetManifest.php`) avec : `key, module, labelKey, descriptionKey, scope(global|company|both), permissions, capabilities, defaultConfig, layout, category, tags, component, audience(platform|company|both), resolution(server|client), resolve(context), datasetKey, transform, archetypes`. Enregistrement par `app/Modules/**/widgets.php` autodécouvert (`DashboardWidgetRegistry::boot()`). Catalogue filtré serveur par `DashboardCatalogService::forArchetype()` : audience → scope → `ModuleGate::isActive` → sous-ensemble permissions (owner bypass) → archetype. `filterLayout()` purge les tuiles de modules inactifs et compacte.

**[RECO] Plan cible = combler les manques du contrat existant, pas en créer un :**
1. **Ajouter un champ `visualization`/`vizType`** (kpi | spark | line | area | bar | donut | radial | timeline | table | gauge | list | map) au manifest pour piloter le rendu générique et le `minSize/maxSize/breakpoints` (certains déjà via `layout()`).
2. **Implémenter `resolution: 'server'`** pour les widgets lourds : la tuyauterie `datasetKey()`/`transform()` existe mais la plupart des widgets sont `'client'` (**SOUS-EXPLOITÉ**) → basculer les KPI agrégés vers le batch serveur `POST /dashboard/widgets/data` (déjà implémenté) pour cache + N+1 maîtrisés.
3. **Activer `capabilities()`** : `PlatformUser::hasCapability()` est un **stub `return true`** → le câbler aux permissions réelles si on veut une granularité capability au-delà des permissions.
4. **Fréquence de rafraîchissement + stratégie de cache + version** : ajouter `refreshInterval`, `cacheTtl`, `realtimeTopics[]`, `version` au manifest ; le realtime (`useRealtimeSubscription`) et le cache SWR runtime existent déjà côté front.
5. **Catalogue par JobDomain** : `JobdomainRegistry.default_roles.*.dashboard_widgets` mappe déjà jobdomain/archetype → clés widgets ; étendre avec les nouvelles clés workforce/planning.

**Règles impératives — déjà garanties par l'existant [FAIT]** : Platform/Company strictement séparés (deux registres, deux contrôleurs, deux guards) ; isolation inter-tenant par `CompanyScope` dans `resolve()` ; autorisation serveur (catalogue + `batchResolve` re-filtrent, ADR-371) ; widget non autorisé **absent du catalogue** ; module désactivé retire ses widgets (`filterLayout`) ; recalcul sur changement permissions (SSE `rbac.changed`) ; abonnement expiré respecte `EnsureBusinessAccess` (les reads dashboard sont allow-listés). **Aucun rôle codé en dur** : tout passe par permissions/bundles/archetypes.

---

## G. MODÈLE DE PERMISSIONS & DE VISIBILITÉ (existant)

Chaîne de vérité, **serveur = autorité** (`NavBuilder` step 5) :
- **Contexte client** (gating UI) hydraté au boot dans Pinia : `auth` (permissions company via `company_role.permissions`, owner `null`=ALL), `platformAuth` (super_admin `null`=ALL), `module` (`activeModules`, `companyPlanKey`, `_addonSubscriptions`), `jobdomain`, `world`. Façades `useCan`, `useCompanyPermissionContext`, `usePlatformPermissionContext`.
- **Autorité serveur** : `NavController` (owner/super_admin → `permissions=null`), `DashboardCatalogService::forArchetype`, `ModuleGate::isActive` → `EntitlementResolver`, `PermissionCatalogBuilder`.
- **Barrières** : `permission` (AND), `anyPermission` (OR, ADR-601/690), `excludePermission` (cache si l'user A la permission → bypass users exclus).
- **[RECO]** : un widget déclare `module` + `permissions` ; la visibilité catalogue ET l'API data sont déjà doublement gated. Ne jamais laisser une personnalisation outrepasser l'autorisation → garanti car le layout sauvegardé est **re-filtré** à la lecture (`filterLayout`) et chaque résolution data re-vérifie l'appartenance au catalogue.

---

## H. STRATÉGIE DASHBOARDS PLATFORM (par permissions, pas par rôles codés)

**[FAIT]** Confronter au RBAC réel : les « profils » du brief (superadmin, finance, support, ops, sécurité, catalogue…) **ne sont pas des rôles codés** ; ce sont des **compositions conditionnelles aux permissions platform** (`PlatformPermission`). Le dashboard platform est **un seul écran, widgets filtrés par permission** — pas N dashboards figés.

| Composition (permissions requises) | Widgets candidats (tous classe A) |
|---|---|
| **Pilotage global** (`view_billing`+`view_usage_monitoring`+`view_system_health`) | MRR/ARR + history, churn, trial-conv, funnel inscription, santé système, companies actives |
| **Finance/Billing** (`view_billing`) | MRR/ARR, AR aging, dunning queue, failed payments 7j, refund ratio, revenue MTD, blocked renewals, scheduled debits |
| **Support** (`support.view`/`view_support`) | tickets ouverts/SLA (`/support/metrics`), activité récente, companies à risque |
| **Ops/Observabilité** (`view_system_health`,`realtime.metrics.view`) | santé db/queue/scheduler/disk, délivrabilité email 24h, SSE connexions, jobs échoués |
| **Sécurité/Conformité** (`security.alerts.view`,`view_audit_logs`) | alertes sécurité par sévérité, centre d'alertes, feed audit, événements à traiter |
| **Catalogue/Modules** (`manage_modules`,`manage_catalog`) | adoption modules (B), modules par plan, jobdomains représentés |

Chaque indicateur est **classe-A disponible** sauf « adoption module » (B) et « API-requests » (D). **Aucun revenu/tendance inventé.**

---

## I. STRATÉGIE DASHBOARDS COMPANY (permissions × modules × jobdomain × données)

Adaptation réelle (pas 15 copies) via **4 dimensions déjà disponibles** : Company (`auth.currentCompany`), JobDomain (`jobdomain` store + `JobdomainRegistry.default_roles[].dashboard_widgets`), modules actifs (`module.activeModules`), permissions/archetype (`DashboardCatalogService::forArchetype`). Le **smart-default** (`companyDashboard` store, ADR-327) compose déjà des rangées KPIs/listes/bannières ; **l'étendre par jobdomain/archetype** suffit.

Compositions par **archétype réel** (`management | operations_center | field_worker`, seeds `JobdomainRegistry.default_roles`) — exemples conditionnels :

- **Direction (management)** : effectifs + variation, pointage live (working/absent), shifts non couverts aujourd'hui, congés à valider, trésorerie (trial/impayés), conformité (jauge), prévu/réel (courbe), activité/audit.
- **Responsable planning (operations_center)** : couverture jour/semaine (gaps), shifts non couverts, disponibilités, conflits/sur-staffing, prévu/réel, candidats remplacement.
- **Responsable RH** : effectifs par dépt, contrats à échéance, congés en attente/soldes, anomalies timesheet, timesheets à valider, conformité documents, signatures en cours.
- **Finance company** (si modules billing présents) : plan/trial, impayés/échus, wallet, prochaine facture, courbe dépenses.
- **Exploitation métier (jobdomain logistique)** : KPI expéditions/livraisons (statut/volume), SLA — **uniquement si module `shipments` actif**.
- **Employé (field_worker)** : prochain shift, pointer (clock widget existant `WorkforceClockWidget.vue`), solde congés, mes demandes, mes documents, notifications.

Pour chacun : **données réelles (classe A/B)**, permissions (cf. §E), source backend (read-models §E), drill-down (route métier), rafraîchissement (SSE topics existants : `billing.updated`, `document.updated`, `members.changed`…), **mobile** (primitives E3-R `AppResponsiveDataView`/`SmartActionBar`).

---

## J. MATRICE EXHAUSTIVE DES WIDGETS (extrait structuré — annexe complète à dérouler en Phase 0)

Colonnes : **ID** · Nom · Scope · Domaine/module · JobDomain · Type viz · Source (read-model) · Permission · Visibilité · Taille · Refresh · Drill-down · Complexité · Priorité · **Statut** (A=dispo / C=wrapper à créer / D=impossible).

### Company — Workforce / Planning / Pointage (tous C = read-model existe, widget à créer)
| ID | Viz | Source | Permission | Taille | Statut | Prio |
|---|---|---|---|---|---|---|
| `wf.headcount` | kpi+spark | `/workforce/dashboard/statistics` | workforce.view | S | **C** | P1 |
| `wf.headcount_by_dept` | bar | `EmployeeReadModel` | workforce.view | M | C(B) | P2 |
| `wf.contracts_expiring` | list+kpi | `EmploymentContract` | workforce.view | M | C(B) | P2 |
| `time.clock_live` | kpi (working/break/absent) | `TimeCockpitReadModel::summary` | workforce.timesheet_view | S/M | **C** | P1 |
| `time.anomalies` | kpi+list | `/time-entries/anomalies` | workforce.timesheet_view | M | **C** | P1 |
| `time.completion` | radial | `TimesheetPeriodReadModel::statistics` | workforce.timesheet_view | S | **C** | P1 |
| `time.pending_approval` | kpi+action | priorities | workforce.timesheet_approve | S | **C** | P1 |
| `plan.coverage_gaps` | bar/heat | `CoverageReadModel` | workforce.planning_view | M/L | **C** | P1 |
| `plan.uncovered_shifts` | kpi+list | `/planning/coverage/summary` | workforce.planning_view | M | **C** | P1 |
| `plan.planned_vs_actual` | line/area | `PlanningComparisonReadModel` | workforce.planning_view | L | **C** | P1 |
| `leave.pending` | kpi+action | `LeaveRequestReadModel::statistics` | workforce.leave_request | S | **C** | P1 |
| `leave.balance` | list/gauge | `LeaveBalanceReadModel` | workforce.leave_request | M | **C** | P2 |
| `payroll.runs_status` | funnel/kpi | `PayrollRunReadModel::statistics` | workforce.payroll_prepare | M | **C** | P2 |
| `payroll.mass` | kpi € | `PayrollLine` (gated) | compensation_read | M | C(sensible) | P3 |
| `dsn.status_funnel` | funnel | `DsnDeclarationReadModel` | workforce.payroll_export | M | **C** | P2 |

### Company — Documents / Signature (read-models A ; certains déjà widgetisés)
| `doc.compliance_rate` | radial/gauge | `DocumentComplianceReadModel` | documents.view | M | **A** (existe) | — |
| `doc.expiring` | list+kpi | idem | documents.view | M | A | P1 |
| `doc.request_queue` | list | `DocumentRequestQueueReadModel` | documents.manage | M | A | P2 |
| `sign.status` | kpi+list | `SignatureReadModel` | workforce_signature.view | M | **C** | P2 |
| `doc.ai_kpi` | kpi | `DocumentAiKpiReadService` | documents.view | S | A (existe) | — |

### Company — Billing (déjà widgetisé = A ; +graphes)
| `bill.plan_trial` | kpi | `/billing/overview` | billing.manage | S | A | — |
| `bill.outstanding` | kpi | idem | billing.manage | S | A | — |
| `bill.spend_trend` | line | ledger/overview | billing.manage | L | **C** | P2 |

### Company — Exploitation (jobdomain `logistique`, module shipments)
| `ship.status` | kpi+bar | `shipments` | shipments.view | M | C(B) | P2 |
| `ship.volume_today` | kpi+spark | `shipments` | shipments.view | S | C(B) | P2 |
| `delivery.my_today` | kpi+map | `my-deliveries`+mapbox | — (self) | M/L | C | P2 |

### Platform (tous A sauf noté)
| `plat.mrr` | kpi+history line | `BillingMetricsCalculationService` | view_billing | M | A | P1 |
| `plat.churn` | kpi+spark | idem | view_billing | S | A | P1 |
| `plat.trial_conv` | radial | idem | view_billing | S | A | P1 |
| `plat.ar_aging` | bar | `/billing/aging` | view_billing | M | A | P1 |
| `plat.dunning` | kpi+list | `/billing/dunning` | view_billing | M | A | P1 |
| `plat.signup_funnel` | funnel | `OnboardingFunnelService` | view_usage_monitoring | M | A | P1 |
| `plat.system_health` | status grid | `SystemHealthService` | view_system_health | M | A | P1 |
| `plat.security_alerts` | kpi+list | `SecurityAlert` | security.alerts.view | M | A | P1 |
| `plat.usage` | area | `UsageAnalyticsService` | view_usage_monitoring | L | A | P2 |
| `plat.module_adoption` | bar | `CompanyModule` cross-tenant | manage_modules | M | C(B) | P3 |
| `plat.api_requests` | — | `usage_snapshots.api_requests` | — | — | **D** (instrumentation absente) | — |

> **Compositions-types** (Superadmin, Finance, Support, Direction, RH, Exploitation, Manager, Employé) : voir §H/§I ; toutes **conditionnelles** permissions+modules+données.

---

## K. DESIGN SYSTEM CIBLE « LEEZR PREMIUM »

**Principe** : exploiter Vuexy à fond **par-dessus** (couche `resources/js/core/components/`), sans forker `@core`/`@layouts`. Tokens déjà en place (palette light+dark complète, primary dynamique plateforme/cookie, typo runtime `--lzr-font-family`, dark `system` par défaut, grille `card-grid-{xs/sm/md/lg}`, primitives responsive E3-R).

**[RECO] 10 règles pour sortir du « plat »** :
1. **Hiérarchie d'élévation intentionnelle** : définir 3 niveaux de carte (flat bordée pour les conteneurs, elevation douce pour KPI, elevation marquée pour l'actif) — corrige le « 1856 cards / 0 elevation ».
2. **Couleurs sémantiques contextuelles** systématiques via `StatusChip`/tonal (success/warning/error/info + on/darken déjà là).
3. **KPI cards normalisés** : réutiliser `@core/components/cards/CardStatistics{Vertical,Horizontal}.vue` + variantes spark des presets `card-statistics/*` — un seul composant Leezr `LzrKpiCard` par-dessus.
4. **Graphiques theme-aware** : pattern `_RevenueTrendWidget.vue` (`useTheme()`+`hexToRgb`) généralisé en `LzrApexChart` (spark/M/L selon `viewport.presentationMode`).
5. **Densité tableaux** maîtrisée (`$table-row-height:50px`) + `AppResponsiveDataView` (table desktop/liste mobile).
6. **Empty states systématiques** : imposer `EmptyState`/`ErrorState` (existants) partout (aujourd'hui hand-rollés).
7. **En-tête de page unifié** : généraliser `SectionHeader`+`PageBreadcrumbs`+`SmartActionBar`.
8. **Micro-interactions sobres** : au-delà de `nav-badge-pop`, ajouter hover/skeleton/transition d'apparition de widget, en respectant `prefers-reduced-motion`.
9. **Iconographie Tabler** cohérente (déjà aliasée).
10. **Mobile/tablette/desktop + dark + WCAG** : primitives E3-R + règles a11y de `smart-responsive-primitives.md` (cibles ≥44px, aria, focus) à ériger en checklist CI.

**Éviter les deux extrêmes** : (a) plat/vide → corrigé par KPI/graphes utiles ; (b) surcharge décorative → chaque carte doit porter un indicateur **classe A/B réel** (règle anti-fictif).

**Patterns réutilisables** (bibliothèque) : `LzrKpiCard`, `LzrApexChart`, `LzrGauge`, `LzrTimeline` (sur VTimeline), `LzrEmptyState`, `LzrPageHeader`, `LzrFilterBar` (sur `AppFilterPanel`), `LzrDataView` (sur `AppResponsiveDataView`).

---

## L. WIREFRAMES TEXTUELS (dashboards prioritaires)

**Dashboard Company — Direction (management, jobdomain logistique)**
```
┌ PageHeader: "Bonjour {prénom}" · période [7j|30j|mois] · [Personnaliser]  ┐
├ KPI STRIP (4×, elevation douce) ───────────────────────────────────────────┤
│ Effectifs 42 ▲+2 │ Pointage 31 présents/4 abs │ Shifts non couverts 3 ⚠ │ Congés à valider 5 │
├ Rangée graphes (grille 12-col) ────────────────────────────────────────────┤
│ [L=8] Prévu vs Réel (area, semaine)        │ [4] Conformité docs (radial 87%) │
├ Rangée listes/actions ─────────────────────────────────────────────────────┤
│ [6] Trésorerie: trial J-5 · 0 impayé       │ [6] À traiter: 5 congés, 2 anomalies │
├ [12] Activité récente (timeline SSE)                                        ┤
└ (vide par défaut → smart-default jobdomain ; tout widget drill-down vers sa page) ┘
```

**Dashboard Employé (field_worker, /home)**
```
┌ [Pointer ⏱ clock widget]  │ Prochain shift: Lun 08:00 Entrepôt A      ┐
├ Solde congés 12j │ Mes demandes 1 en attente │ Mes docs 1 à signer ⚠   ┤
├ [12] Mes prochains shifts (liste semaine)                               ┤
└ [12] Notifications (non-lues par catégorie)                             ┘
```

**Dashboard Platform (permissions-driven)**
```
┌ Cockpit "À traiter" (attention) + chips santé (db/queue/email/scheduler)  ┐
├ KPI: MRR 12.4k€ ▲ │ Churn 2.1% │ Trial→payant 38% │ Impayés 3            ┤
├ [8] MRR history (line) │ [4] Funnel inscription (bar)                     ┤
├ [6] Dunning queue (list) │ [6] Alertes sécurité (par sévérité)            ┤
└ (chaque widget conditionnel aux permissions platform de l'utilisateur)    ┘
```
(Wireframes = schémas ; aucune donnée réelle représentée.)

---

## M. PRÉFÉRENCES & PERSONNALISATION (modèle)

**[FAIT] Déjà en place** : drag/resize/add/remove/save (`useDashboardGrid` + `useSurfaceEngine` : `GET/PUT /dashboard/layout`), smart-default auto-sauvé si `isAdministrative`, persistance `CompanyDashboardLayout`/`PlatformUserDashboardLayout`, défauts jobdomain (`JobdomainDashboardDefault`).

**[RECO] Niveaux & héritage (priorité décroissante)** :
`Config utilisateur` > `Preset Company` > `Preset JobDomain` (`JobdomainRegistry.*.dashboard_widgets`) > `Preset Module` (`WidgetManifest.layout()`) > `Preset Platform`. Restauration = retomber au preset immédiatement supérieur.

**Comportements aux événements (déjà partiellement gérés par `filterLayout`)** : révocation permission / désactivation module / changement plan / suppression ou **versionnage** de widget / changement de rôle / mobile → **la tuile disparaît du layout rendu sans casser la sauvegarde** ; au retour d'autorisation elle réapparaît (le layout stocke des clés, filtrées à la lecture). **[RECO]** : ajouter `version` au manifest + migration douce de layout (drop tuile si version incompatible) ; **persistance minimale, versionnée, multi-tenant** : `{company_id|platform_user_id, scope, layout_json, schema_version}` (déjà le cas, à étendre d'un `schema_version`).

**Règle d'or** : une personnalisation ne peut jamais outrepasser une autorisation (garanti par re-filtrage serveur à la lecture + re-check à la résolution data).

---

## N. PERFORMANCE, SÉCURITÉ, MULTI-TENANCY

**[FAIT]** : batch de résolution (`POST /dashboard/widgets/data`), cache SWR runtime + invalidation SSE, isolation `CompanyScope`, lazy async des composants widgets (`defineAsyncComponent`).
**[RECO] objectifs mesurables** :
- Résolution dashboard < **800 ms P95** (batch + read-models déjà agrégés) ; widgets hors-écran en lazy/`IntersectionObserver`.
- **Zéro N+1** : privilégier les read-models `statistics()` (une requête agrégée) vs N appels ; bannir les KPI calculés côté front par boucle.
- Realtime **sélectif** : un widget s'abonne à ses `realtimeTopics[]` uniquement (`useRealtimeSubscription`), pas de refetch global.
- Cache **par tenant + user + permission-hash** (clé dérivée du catalogue filtré) ; TTL par widget (`cacheTtl`).
- Grandes périodes : agrégation serveur + pagination des listes ; mobile : S-mode (spark) par défaut, données réduites.
- **Pas de nouvelle infra** : SSE/DB-cache/queue existants suffisent (pas de websocket bidirectionnel ni nouveau service requis pour la cible).
Sécurité : catalogue + data doublement gated serveur ; pas de donnée inter-tenant ; `EnsureBusinessAccess` pour l'état d'abonnement.

---

## O. ARCHITECTURE MENUS & NAVIGATION

**[FAIT]** Navigation déjà manifest-driven, groupée par domaine, visibilité calculée (plan+jobdomain+permissions+surface/roleLevel), breadcrumbs (`PageBreadcrumbs`), recherche globale (`NavSearchBar`), cohérence platform/company (même `NavBuilder`).
**[RECO] Choix dashboards** : **une entrée « Tableau de bord » par scope** (company `/dashboard`, platform `/platform/dashboard`), **composée de plusieurs sections/onglets par domaine** à l'intérieur (pas N entrées de menu). Motivation : le moteur de widgets rend la multiplication d'entrées inutile ; un seul point d'entrée + personnalisation = moins de charge cognitive, cohérent avec l'archétype. **Drill-down widget → page métier** via la route déclarée (déjà le pattern `_RevenueTrendWidget` onClick). **Fusion `/home` ↔ `/dashboard`** à arbitrer (cf. §P).

---

## P. RISQUES, DÉPENDANCES, DÉCISIONS À ARBITRER

| # | Décision à arbitrer | Options | Reco |
|---|---|---|---|
| 1 | `/home` (end-user) vs `/dashboard` (company) — deux dashboards concurrents vides | (a) fusionner, rendu par archétype ; (b) garder 2 scopes distincts | **(b)** : `/home` = employé (field_worker), `/dashboard` = management/ops — mais **remplir les deux** |
| 2 | `vizType` dans WidgetManifest | étendre contrat vs composants ad hoc | **étendre** le manifest existant |
| 3 | `resolution: server` généralisé | garder client vs basculer serveur | **serveur** pour KPI agrégés (cache/perf) |
| 4 | `chart.js`/`swiper` dormants | supprimer vs garder | **supprimer** (dette) sauf besoin identifié |
| 5 | `@fullcalendar` en devDependency | déplacer en dependencies | **déplacer** (correction) |
| 6 | Export riche (xlsx/PDF) | nouvelle dépendance | **différer** (CSV suffit en P1) |
| 7 | Heatmap couverture planning | ApexCrecap heatmap vs bar | **bar d'abord**, heatmap en P3 |
| 8 | `capabilities()`/`hasCapability()` stub | implémenter vs retirer | implémenter **si** besoin de granularité au-delà des permissions, sinon documenter comme no-op |
| 9 | Montants paie sur dashboard | exposer gated vs exclure | **gated `compensation_read`** uniquement |

**Risques** : (i) re-développer l'existant (mitigation : cet audit) ; (ii) surcharge décorative (mitigation : règle « 1 carte = 1 indicateur classe A/B ») ; (iii) perf si widgets non lazy/non batchés ; (iv) jobdomain unique (`logistique`) → tester l'adaptation métier reste théorique tant qu'un 2ᵉ domaine n'existe pas (cohérent doctrine ADR-561→564).

---

## Q. RECOMMANDATION FINALE — par quoi commencer (impact visuel ET utilité maximale)

**Quick wins (fort impact, zéro reconstruction, data classe A/B déjà là) :**
1. **Remplir le dashboard Company** : créer ~8 `WidgetManifest` wrappers Workforce/Planning/Pointage/Congés (read-models A existants) + 2 graphes ApexCharts (prévu/réel, tendance) + un **smart-default par archétype/jobdomain**. → transforme l'écran le plus vu.
2. **Généraliser `LzrKpiCard` + `LzrApexChart`** (sur le pattern billing existant) et les poser sur les 12 pages prioritaires (§D) comme bandeaux KPI.
3. **Hiérarchie d'élévation** (3 niveaux de carte) + empty states systématiques → corrige le « plat » instantanément, sans nouveau composant lourd.
4. **Platform overview** : ajouter sparklines MRR/churn/funnel (composants déjà existants côté billing).

Ces 4 quick wins donnent un résultat **visuellement impressionnant ET réellement utile** en réutilisant 100 % de l'infrastructure existante.

---

## MASTER PLAN D'EXÉCUTION (par phases) — **à exécuter sur GO ultérieur, rien lancé ici**

> Chaque phase : Objectif · Périmètre · Fichiers/modules · Dépendances · Données · Contrats API · Réutilisation · Nouveaux composants · Tests · Risques · Critères d'acceptation · Complexité.

**PHASE 0 — Fondations design-system & contrat widget (étendre, pas reconstruire)**
- Objectif : couche premium + contrat widget complété.
- Périmètre : `LzrKpiCard/LzrApexChart/LzrGauge/LzrTimeline/LzrPageHeader/LzrEmptyState` (couche `resources/js/core/components/`) ; ajout `vizType/refreshInterval/cacheTtl/realtimeTopics/version` à `WidgetManifest`.
- Réutilise : presets `cards/charts`, pattern `_RevenueTrendWidget`, primitives E3-R. Nouveaux : 6 composants Lzr*. Pas de migration.
- Tests : unités composants + snapshot visuel Playwright (si infra). Accept : design tokens d'élévation documentés, Lzr* catalogués dans `docs/ui`. Complexité : **M**. Risque : faible (hors `@core`).

**PHASE 1 — Dashboard Platform + widgets transversaux**
- Périmètre : widgets MRR/churn/trial-conv/AR-aging/dunning/funnel/health/security (tous **A**), sparklines dans overview.
- Fichiers : `app/Modules/Platform/Dashboard/*`, `app/Modules/Billing/Dashboard/*` (registre existant), `pages/platform/dashboard/_DashboardOverview.vue`.
- Données : endpoints §E (A). Tests : permissions platform, API non-autorisée 403, visibilité catalogue. Accept : composition finance/support/ops/sécurité rend des KPI réels, **0 widget interdit, 0 chiffre inventé**. Complexité : **M**.

**PHASE 2 — Dashboard Company + personnalisation par permissions**
- Périmètre : smart-default par archétype/jobdomain, remplissage `/dashboard` + `/home`, drag/resize déjà là.
- Fichiers : `app/Modules/Dashboard/widgets.php`, `Widgets/*` (nouveaux wrappers billing/compliance déjà A), `modules/company/dashboard/dashboard.store.js`, `pages/dashboard.vue`, `pages/home.vue`.
- Tests : isolation multi-tenant, entitlements, module désactivé → widget retiré, révocation permission → recalcul. Accept : Company jour-0 utile (onboarding+trial), Company peuplée riche. Complexité : **M/L**.

**PHASE 3 — Widgets Workforce/Planning/Pointage & exploitation métier**
- Périmètre : les ~15 widgets **C** du §J (read-models A existants, wrappers à créer) + widgets jobdomain logistique (shipments/deliveries).
- Fichiers : `app/Modules/Workforce/.../widgets.php` (nouveau par module), `app/Modules/Dashboard/Widgets/*`.
- Données : `TimeCockpitReadModel`, `CoverageReadModel`, `PlanningComparisonReadModel`, `LeaveRequestReadModel`, `TimesheetPeriodReadModel`… (A). Tests : permissions workforce granulaires, `compensation_read` pour montants, empty-state. Accept : chaque widget = indicateur réel drill-down. Complexité : **L**.

**PHASE 4 — Personnalisation avancée (presets jobdomain, héritage, versioning)**
- Périmètre : chaîne d'héritage §M, `schema_version` layout, multi-vues/favoris, templates préconfigurés.
- Fichiers : `JobdomainDashboardDefault.php`, `LayoutValidator.php`, stores dashboard. Tests : révocation/désactivation/version → dégradation sûre ; mobile. Accept : aucune perso n'outrepasse l'autorisation. Complexité : **M**.

**PHASE 5 — Enrichissement des autres pages, polish, responsive, perf**
- Périmètre : bandeaux KPI sur les 12 pages §D, empty states, élévation, lazy widgets hors-écran, objectifs perf §N, nettoyage dette (chart.js/swiper, fullcalendar dep).
- Tests : non-régression, responsive, a11y WCAG, perf P95. Accept : objectifs §N atteints. Complexité : **M/L**.

**Quick wins transverses** (insérables dès P0/P1) : élévation cards, empty states systématiques, sparklines platform, `LzrKpiCard` sur dashboards.

---

## 15 bis. TESTS & CRITÈRES QUALITÉ (cibles)
Permissions platform & company · isolation multi-tenant (reprend harnais tenancy ADR-432/733) · entitlements · visibilité widgets · **API widget non autorisée = 403** · personnalisation (save/restore/version) · responsive · a11y WCAG · perf P95 · non-régression · visuels Playwright (infra e2e existante `tests/e2e/`).
**Objectifs couperets** : 0 fuite inter-tenant · 0 widget interdit affiché · **0 statistique inventée** · 0 faux dashboard sur données de démo · 0 composant Vuexy posé sans valeur · utilité métier démontrable par widget.

---

## Faits / Recommandations / Hypothèses — séparation
- **FAITS** : tout le §B, §C, §E, §F (contrat existant), §G, §O (existant) — chemins cités.
- **RECOMMANDATIONS** : §K, §J (matrice), §L, §M (héritage), §N (objectifs), Master Plan.
- **HYPOTHÈSES à valider** : fusion `/home`↔`/dashboard` ; granularité `capabilities()` ; pertinence heatmap ; priorité exacte des archétypes (dépend des rôles réellement configurés par Company, non codés).

---

### Checkpoint
Audit total livré (A→Q + Master Plan 6 phases + matrice widgets + wireframes). **Aucune ligne de code, migration, seeder ou déploiement.** Direction stratégique : **exploiter et enrichir le moteur de widgets existant**, surfacer les read-models déjà KPI-ready, poser une couche premium par-dessus Vuexy — sans jamais reconstruire l'infrastructure déjà présente. Prêt à démarrer la Phase 0 sur votre GO.
